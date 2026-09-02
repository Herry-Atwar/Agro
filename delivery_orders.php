<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_delivery_orders');

// ─── Auto-generate DO number ───────────────────────────────────────────────────
function gen_do_number(PDO $db): string {
    $ym = date('Ym');
    $prefix = 'DO-' . $ym . '-';
    $stmt = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(do_number,'-',-1) AS UNSIGNED)),0)+1
        FROM delivery_orders WHERE do_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── Auto-generate invoice number ─────────────────────────────────────────────
function gen_invoice_number(PDO $db): string {
    $ym = date('Ym');
    $prefix = 'INV-' . $ym . '-';
    $stmt = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED)),0)+1
        FROM delivery_orders WHERE invoice_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── Create Journal Entry for sales invoice ───────────────────────────────────
function create_sales_journal(PDO $db, array $do_row): ?int {
    // Determine AR account by product
    $ar_map = ['CPO'=>'1121','Kernel'=>'1122','FFB'=>'1123','PKO'=>'1121','Other'=>'1120'];
    $rev_map = ['CPO'=>'4110','Kernel'=>'4120','FFB'=>'4100','PKO'=>'4130','Other'=>'4100'];
    $ar_code  = $ar_map[$do_row['product_type']]  ?? '1120';
    $rev_code = $rev_map[$do_row['product_type']] ?? '4100';

    // Try specific sub-account first, fall back to parent account
    $ar_id  = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='$ar_code'  AND is_active=1 LIMIT 1")->fetchColumn();
    if (!$ar_id)  $ar_id  = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='1120' AND is_active=1 LIMIT 1")->fetchColumn();
    $rev_id = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='$rev_code' AND is_active=1 LIMIT 1")->fetchColumn();
    if (!$rev_id) $rev_id = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='4100' AND is_active=1 LIMIT 1")->fetchColumn();
    if (!$ar_id || !$rev_id) return null;

    // Reference number for journal entry
    $ym = date('Ym', strtotime($do_row['invoice_date']));
    $prefix = 'JE-SALES-' . $ym . '-';
    $stmt = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reference_number,'-',-1) AS UNSIGNED)),0)+1
        FROM journal_entries WHERE reference_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    $ref = $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);

    $amount = (float)$do_row['total_amount'];

    // Insert header
    $db->prepare("
        INSERT INTO journal_entries
            (entry_date, entry_type, reference_number, description,
             company_id, total_debit, total_credit,
             currency_code, status, posted_date, posted_by, created_by)
        VALUES (?, 'sales_invoice', ?, ?, ?, ?, ?, 'IDR', 'posted', NOW(), 1, 'system')
    ")->execute([
        $do_row['invoice_date'],
        $ref,
        'Sales invoice ' . $do_row['invoice_number'] . ' — ' . $do_row['product_type'] . ' DO: ' . $do_row['do_number'],
        $do_row['company_id'],
        $amount,
        $amount,
    ]);
    $je_id = (int)$db->lastInsertId();

    // Line 1: Dr Accounts Receivable
    $db->prepare("
        INSERT INTO journal_entry_lines
            (journal_entry_id, line_number, gl_account_id,
             debit_amount, credit_amount,
             base_currency_debit, base_currency_credit,
             currency_code, exchange_rate, description)
        VALUES (?,1,?, ?,0, ?,0, 'IDR',1,?)
    ")->execute([$je_id, $ar_id, $amount, $amount, 'AR — ' . $do_row['customer_name'] . ' (' . $do_row['product_type'] . ')']);

    // Line 2: Cr Revenue
    $db->prepare("
        INSERT INTO journal_entry_lines
            (journal_entry_id, line_number, gl_account_id,
             debit_amount, credit_amount,
             base_currency_debit, base_currency_credit,
             currency_code, exchange_rate, description)
        VALUES (?,2,?, 0,?, 0,?, 'IDR',1,?)
    ")->execute([$je_id, $rev_id, $amount, $amount, 'Revenue — ' . $do_row['product_type'] . ' sales']);

    return $je_id;
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // Create DO
    if ($action === 'create_do') {
        try {
            $db->beginTransaction();
            $num = gen_do_number($db);
            $db->prepare("
                INSERT INTO delivery_orders
                    (do_number, do_date, contract_id, company_id, customer_id, product_type,
                     quantity_kg, unit_price, currency, delivery_date, delivery_location,
                     driver_name, vehicle_number, seal_number,
                     gross_weight_kg, tare_weight_kg, status, notes, created_by)
                VALUES (?,?,?,?,?,?, ?,?,?,?,?, ?,?,?, ?,?,'draft',?,'admin')
            ")->execute([
                $num,
                post('do_date'), (int)post('contract_id'),
                (int)post('company_id'), (int)post('customer_id'), post('product_type'),
                (float)post('quantity_kg'), (float)post('unit_price'), post('currency') ?: 'IDR',
                post('delivery_date'), post('delivery_location'),
                post('driver_name'), post('vehicle_number'), post('seal_number'),
                post('gross_weight_kg') ?: null, post('tare_weight_kg') ?: null,
                post('notes'),
            ]);
            $db->commit();
            set_message('success', "Delivery Order <b>$num</b> created successfully!");
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('delivery_orders.php?contract_id=' . (int)post('contract_id'));
    }

    // Mark as invoiced → create journal entry
    if ($action === 'confirm_invoice') {
        $do_id = (int)post('do_id');
        try {
            $db->beginTransaction();
            $inv_num  = gen_invoice_number($db);
            $inv_date = post('invoice_date') ?: date('Y-m-d');

            // fetch DO row + customer name
            $stmt = $db->prepare("
                SELECT do2.*, cu.customer_name
                FROM delivery_orders do2
                JOIN customers cu ON do2.customer_id = cu.customer_id
                WHERE do2.do_id = ?
            ");
            $stmt->execute([$do_id]);
            $do_row = $stmt->fetch(PDO::FETCH_ASSOC);
            $do_row['invoice_number'] = $inv_num;
            $do_row['invoice_date']   = $inv_date;

            $je_id = create_sales_journal($db, $do_row);

            $db->prepare("
                UPDATE delivery_orders
                SET status='invoiced', invoice_number=?, invoice_date=?, journal_entry_id=?,
                    updated_by='admin'
                WHERE do_id=?
            ")->execute([$inv_num, $inv_date, $je_id, $do_id]);

            $db->commit();
            set_message('success', "Invoice <b>$inv_num</b> created. Journal entry auto-posted.");
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('delivery_orders.php?contract_id=' . (int)post('contract_id'));
    }

    // Confirm delivery
    if ($action === 'confirm_delivery') {
        try {
            $db->prepare("UPDATE delivery_orders SET status='delivered', updated_by='admin' WHERE do_id=?")
               ->execute([(int)post('do_id')]);
            set_message('success', 'Delivery confirmed.');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('delivery_orders.php?contract_id=' . (int)post('contract_id'));
    }

    // Update DO (draft only)
    if ($action === 'update_do') {
        $do_id = (int)post('do_id');
        try {
            $db->prepare("
                UPDATE delivery_orders
                SET do_date=?, delivery_date=?, delivery_location=?,
                    quantity_kg=?, unit_price=?,
                    driver_name=?, vehicle_number=?, seal_number=?,
                    gross_weight_kg=?, tare_weight_kg=?,
                    notes=?, updated_by='admin'
                WHERE do_id=? AND status='draft'
            ")->execute([
                post('do_date'), post('delivery_date'), post('delivery_location'),
                (float)post('quantity_kg'), (float)post('unit_price'),
                post('driver_name'), post('vehicle_number'), post('seal_number'),
                post('gross_weight_kg') ?: null, post('tare_weight_kg') ?: null,
                post('notes'),
                $do_id,
            ]);
            set_message('success', 'Delivery Order updated successfully.');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('delivery_orders.php?contract_id=' . (int)post('contract_id'));
    }

    // Delete DO
    if ($action === 'delete_do') {
        try {
            $db->prepare("DELETE FROM delivery_orders WHERE do_id=? AND status='draft'")
               ->execute([(int)post('do_id')]);
            set_message('success', 'Delivery order deleted.');
        } catch (PDOException $e) {
            set_message('error', 'Cannot delete: ' . $e->getMessage());
        }
        redirect('delivery_orders.php?contract_id=' . (int)post('contract_id'));
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$contract_id_filter = (int)get('contract_id', 0);
$status_filter      = get('status', '');
$year               = get('year', date('Y'));
$search             = get('search', '');

// ─── Reference data ───────────────────────────────────────────────────────────
$companies = $db->query("SELECT * FROM companies ORDER BY company_code")->fetchAll(PDO::FETCH_ASSOC);
$customers = $db->query("SELECT * FROM customers WHERE status='Active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
$contracts_ref = $db->query("
    SELECT sc.contract_id, sc.contract_number, sc.product_type,
           sc.quantity_mt, sc.unit_price, sc.company_id, sc.customer_id,
           sc.delivery_location, cu.customer_name
    FROM sales_contracts sc
    JOIN customers cu ON sc.customer_id = cu.customer_id
    WHERE sc.status NOT IN ('cancelled','fully_delivered')
    ORDER BY sc.contract_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Keyed for quick lookup
$contracts_map = [];
foreach ($contracts_ref as $cr) { $contracts_map[$cr['contract_id']] = $cr; }

// ─── DO list ──────────────────────────────────────────────────────────────────
try {
    $sql = "SELECT do2.*, sc.contract_number, c.company_name, cu.customer_name,
                   je.reference_number AS je_ref
            FROM delivery_orders do2
            JOIN sales_contracts sc ON do2.contract_id = sc.contract_id
            JOIN companies  c  ON do2.company_id  = c.company_id
            JOIN customers  cu ON do2.customer_id = cu.customer_id
            LEFT JOIN journal_entries je ON do2.journal_entry_id = je.id
            WHERE YEAR(do2.do_date)=?";
    $p = [$year];
    if ($contract_id_filter) { $sql .= " AND do2.contract_id=?"; $p[] = $contract_id_filter; }
    if ($status_filter)      { $sql .= " AND do2.status=?";       $p[] = $status_filter; }
    if ($search) {
        $sql .= " AND (do2.do_number LIKE ? OR do2.invoice_number LIKE ? OR cu.customer_name LIKE ?)";
        $t="%$search%"; $p[]=$t; $p[]=$t; $p[]=$t;
    }
    $sql .= " ORDER BY do2.do_date DESC, do2.do_id DESC";
    $stmt=$db->prepare($sql); $stmt->execute($p);
    $dos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $dos = []; }

$selected_contract = $contract_id_filter ? ($contracts_map[$contract_id_filter] ?? null) : null;
$status_colours = ['draft'=>'secondary','confirmed'=>'primary','delivered'=>'info',
                   'invoiced'=>'warning','cancelled'=>'danger'];
$product_colours=['FFB'=>'success','CPO'=>'warning','Kernel'=>'info','PKO'=>'primary','Other'=>'secondary'];

require_once 'includes/header.php';
?>

<style>
    .do-teal { color: #0d9488 !important; }
    .bg-do   { background-color: #0d9488 !important; }
    .btn-do  { background-color: #0d9488; color:#fff; border:none; }
    .btn-do:hover { background-color: #0a7c73; color:#fff; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="do-teal"><i class="bi bi-truck"></i> Delivery Orders</h1>
            <p class="text-muted mb-0">
                <a href="sales_contracts.php" class="text-decoration-none">Sales Contracts</a>
                &rsaquo; <b>Delivery Orders</b>
                &rsaquo; <a href="payment_receives.php" class="text-decoration-none">Payment Receives</a>
                <?php if ($selected_contract): ?>
                    &nbsp;|&nbsp; Contract: <strong><?= htmlspecialchars($selected_contract['contract_number']) ?></strong>
                    (<?= htmlspecialchars($selected_contract['customer_name']) ?>)
                <?php endif; ?>
            </p>
        </div>
        <div class="col-auto">
            <button class="btn btn-do" data-bs-toggle="modal" data-bs-target="#createDOModal">
                <i class="bi bi-plus-circle"></i> New DO
            </button>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-header bg-do text-white py-2"><i class="bi bi-funnel"></i> Filter Delivery Orders</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="DO # / invoice / customer…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y=date('Y')-2;$y<=date('Y')+1;$y++): ?>
                        <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="contract_id" class="form-select form-select-sm">
                    <option value="">All Contracts</option>
                    <?php foreach ($contracts_ref as $cr): ?>
                        <option value="<?=$cr['contract_id']?>" <?=$cr['contract_id']==$contract_id_filter?'selected':''?>>
                            <?= htmlspecialchars($cr['contract_number']) ?> — <?= htmlspecialchars($cr['customer_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <?php foreach (array_keys($status_colours) as $s): ?>
                        <option value="<?=$s?>" <?=$s===$status_filter?'selected':''?>><?=ucfirst($s)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-do btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="delivery_orders.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- KPI -->
<?php
$total_qty    = array_sum(array_column($dos,'quantity_kg'))/1000;
$total_inv    = array_sum(array_column($dos,'total_amount'));
$invoiced_cnt = count(array_filter($dos,fn($r)=>$r['status']==='invoiced'));
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Total DOs</div><div class="fw-bold fs-4"><?=count($dos)?></div>
        <div class="small text-warning"><?=$invoiced_cnt?> invoiced</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Total Qty (MT)</div><div class="fw-bold fs-5"><?=number_format($total_qty,1)?></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Invoiced Amount</div><div class="fw-bold fs-5">Rp <?=number_format($total_inv/1000000,1)?>M</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Journal Entries Created</div>
        <div class="fw-bold fs-5 text-success"><?=count(array_filter($dos,fn($r)=>!empty($r['je_ref'])))?></div>
    </div></div></div>
</div>

<!-- DO Table -->
<div class="card">
    <div class="card-header bg-do text-white py-2">
        <i class="bi bi-table"></i> <?=count($dos)?> Delivery Order(s)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>DO #</th>
                        <th>DO Date</th>
                        <th>Contract</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th class="text-end">Qty (MT)</th>
                        <th class="text-end">Amount (Rp)</th>
                        <th>Invoice #</th>
                        <th>Journal</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dos)): ?>
                        <tr><td colspan="11" class="text-center text-muted py-3">No delivery orders found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dos as $r): ?>
                            <tr>
                                <td class="fw-bold"><?=htmlspecialchars($r['do_number'])?></td>
                                <td><?=date('d/m/Y',strtotime($r['do_date']))?></td>
                                <td><a href="sales_contracts.php"><?=htmlspecialchars($r['contract_number'])?></a></td>
                                <td><?=htmlspecialchars($r['customer_name'])?><br>
                                    <small class="text-muted"><?=htmlspecialchars($r['company_name'])?></small></td>
                                <td><span class="badge bg-<?=$product_colours[$r['product_type']]??'secondary'?>"><?=$r['product_type']?></span></td>
                                <td class="text-end"><?=number_format($r['quantity_mt'],1)?></td>
                                <td class="text-end"><?=number_format($r['total_amount'],0)?></td>
                                <td><?= $r['invoice_number'] ? '<span class="badge bg-success">'.htmlspecialchars($r['invoice_number']).'</span>' : '—' ?></td>
                                <td>
                                    <?php if ($r['je_ref']): ?>
                                        <a href="journal_entry_detail.php?ref=<?=urlencode($r['je_ref'])?>"
                                           class="badge bg-info text-dark text-decoration-none">
                                            <?=htmlspecialchars($r['je_ref'])?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-<?=$status_colours[$r['status']]??'secondary'?>"><?=ucfirst($r['status'])?></span></td>
                                <td>
                                    <?php if ($r['status'] === 'draft'): ?>
                                        <!-- Edit -->
                                        <button class="btn btn-sm btn-outline-secondary" title="Edit DO"
                                                onclick="openEditModal(<?= htmlspecialchars(json_encode($r)) ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <!-- Confirm delivery -->
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="confirm_delivery">
                                            <input type="hidden" name="do_id" value="<?=$r['do_id']?>">
                                            <input type="hidden" name="contract_id" value="<?=$r['contract_id']?>">
                                            <button type="submit" class="btn btn-sm btn-outline-info" title="Confirm Delivered"
                                                    onclick="return confirm('Mark as Delivered?')">
                                                <i class="bi bi-check2-circle"></i>
                                            </button>
                                        </form>
                                        <!-- Delete -->
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Delete this DO?')">
                                            <input type="hidden" name="action" value="delete_do">
                                            <input type="hidden" name="do_id" value="<?=$r['do_id']?>">
                                            <input type="hidden" name="contract_id" value="<?=$r['contract_id']?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php elseif ($r['status'] === 'delivered'): ?>
                                        <!-- Go to Sales Invoices to create a period invoice -->
                                        <a href="sales_invoices.php"
                                           class="btn btn-sm btn-outline-warning"
                                           title="Create Invoice in Sales Invoices">
                                            <i class="bi bi-receipt"></i> Invoice
                                        </a>
                                    <?php elseif ($r['status'] === 'invoiced'): ?>
                                        <?php if ($r['invoice_number']): ?>
                                            <a href="sales_invoices.php?search=<?= urlencode($r['invoice_number']) ?>"
                                               class="btn btn-sm btn-outline-info btn-sm" title="View Invoice">
                                                <i class="bi bi-receipt-cutoff"></i> <?= htmlspecialchars($r['invoice_number']) ?>
                                            </a>
                                        <?php endif; ?>
                                        <a href="payment_receives.php?do_id=<?=$r['do_id']?>"
                                           class="btn btn-sm btn-outline-success" title="Record Payment">
                                            <i class="bi bi-cash-coin"></i> Pay
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create DO Modal -->
<div class="modal fade" id="createDOModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <input type="hidden" name="action" value="create_do">
            <div class="modal-content">
                <div class="modal-header bg-do text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> New Delivery Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Sales Contract *</label>
                            <select name="contract_id" id="doContractSel" class="form-select form-select-sm" required
                                    onchange="fillContractDefaults(this)">
                                <option value="">— Select Contract —</option>
                                <?php foreach ($contracts_ref as $cr): ?>
                                    <option value="<?=$cr['contract_id']?>"
                                            data-product="<?=$cr['product_type']?>"
                                            data-price="<?=$cr['unit_price']?>"
                                            data-company="<?=$cr['company_id']?>"
                                            data-customer="<?=$cr['customer_id']?>"
                                            data-location="<?=htmlspecialchars($cr['delivery_location']??'')?>"
                                            <?= $cr['contract_id']==$contract_id_filter?'selected':'' ?>>
                                        <?=htmlspecialchars($cr['contract_number'])?> — <?=htmlspecialchars($cr['customer_name'])?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">DO Date *</label>
                            <input type="date" name="do_date" class="form-control form-control-sm" value="<?=date('Y-m-d')?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Delivery Date *</label>
                            <input type="date" name="delivery_date" class="form-control form-control-sm" value="<?=date('Y-m-d')?>" required>
                        </div>
                        <input type="hidden" name="company_id" id="doCompanyId">
                        <input type="hidden" name="customer_id" id="doCustomerId">
                        <div class="col-md-3">
                            <label class="form-label">Product *</label>
                            <input type="text" name="product_type" id="doProductType" class="form-control form-control-sm" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Qty (kg) *</label>
                            <input type="number" step="0.01" name="quantity_kg" id="doQtyKg" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit Price (IDR/kg) *</label>
                            <input type="number" step="0.01" name="unit_price" id="doUnitPrice" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Currency</label>
                            <select name="currency" class="form-select form-select-sm">
                                <option value="IDR">IDR</option><option value="USD">USD</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Delivery Location</label>
                            <input type="text" name="delivery_location" id="doDelivLoc" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Driver Name</label>
                            <input type="text" name="driver_name" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Vehicle #</label>
                            <input type="text" name="vehicle_number" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gross Weight (kg)</label>
                            <input type="number" step="0.01" name="gross_weight_kg" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tare Weight (kg)</label>
                            <input type="number" step="0.01" name="tare_weight_kg" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Seal #</label>
                            <input type="text" name="seal_number" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3"><!-- spacer --></div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-do btn-sm"><i class="bi bi-save"></i> Save DO</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Edit DO Modal -->
<div class="modal fade" id="editDOModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <input type="hidden" name="action" value="update_do">
            <input type="hidden" name="do_id" id="editDoId">
            <input type="hidden" name="contract_id" id="editContractId">
            <div class="modal-content">
                <div class="modal-header bg-do text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Delivery Order — <span id="editDoNumber"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">DO Date *</label>
                            <input type="date" name="do_date" id="editDoDate" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Delivery Date *</label>
                            <input type="date" name="delivery_date" id="editDelivDate" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Qty (kg) *</label>
                            <input type="number" step="0.01" name="quantity_kg" id="editQtyKg" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit Price (IDR/kg) *</label>
                            <input type="number" step="0.01" name="unit_price" id="editUnitPrice" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Delivery Location</label>
                            <input type="text" name="delivery_location" id="editDelivLoc" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Driver Name</label>
                            <input type="text" name="driver_name" id="editDriverName" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Vehicle #</label>
                            <input type="text" name="vehicle_number" id="editVehicleNum" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gross Weight (kg)</label>
                            <input type="number" step="0.01" name="gross_weight_kg" id="editGrossWt" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tare Weight (kg)</label>
                            <input type="number" step="0.01" name="tare_weight_kg" id="editTareWt" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Seal #</label>
                            <input type="text" name="seal_number" id="editSealNum" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3"><!-- spacer --></div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="editNotes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-do btn-sm"><i class="bi bi-save"></i> Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <input type="hidden" name="action" value="confirm_invoice">
            <input type="hidden" name="do_id" id="invDoId">
            <input type="hidden" name="contract_id" id="invContractId">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-receipt"></i> Create Invoice + Journal Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        This will generate an invoice number and automatically post a
                        <strong>double-entry journal</strong>:<br>
                        Dr Accounts Receivable → Cr Revenue
                    </p>
                    <div class="mb-3">
                        <label class="form-label">Invoice Date *</label>
                        <input type="date" name="invoice_date" class="form-control" value="<?=date('Y-m-d')?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-receipt"></i> Create Invoice</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function fillContractDefaults(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('doCompanyId').value   = opt.dataset.company   || '';
    document.getElementById('doCustomerId').value  = opt.dataset.customer  || '';
    document.getElementById('doProductType').value = opt.dataset.product   || '';
    document.getElementById('doUnitPrice').value   = opt.dataset.price     || '';
    document.getElementById('doDelivLoc').value    = opt.dataset.location  || '';
}
function openEditModal(r) {
    document.getElementById('editDoId').value        = r.do_id;
    document.getElementById('editContractId').value  = r.contract_id;
    document.getElementById('editDoNumber').textContent = r.do_number;
    document.getElementById('editDoDate').value      = r.do_date;
    document.getElementById('editDelivDate').value   = r.delivery_date  || '';
    document.getElementById('editQtyKg').value       = r.quantity_kg    || '';
    document.getElementById('editUnitPrice').value   = r.unit_price     || '';
    document.getElementById('editDelivLoc').value    = r.delivery_location || '';
    document.getElementById('editDriverName').value  = r.driver_name    || '';
    document.getElementById('editVehicleNum').value  = r.vehicle_number || '';
    document.getElementById('editGrossWt').value     = r.gross_weight_kg || '';
    document.getElementById('editTareWt').value      = r.tare_weight_kg || '';
    document.getElementById('editSealNum').value     = r.seal_number    || '';
    document.getElementById('editNotes').value       = r.notes          || '';
    new bootstrap.Modal(document.getElementById('editDOModal')).show();
}
function openInvoiceModal(doId, contractId) {
    document.getElementById('invDoId').value = doId;
    document.getElementById('invContractId').value = contractId;
    new bootstrap.Modal(document.getElementById('invoiceModal')).show();
}
// Auto-fill if contract pre-selected
window.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('doContractSel');
    if (sel && sel.value) fillContractDefaults(sel);
});
</script>

<?php require_once 'includes/footer.php'; ?>
