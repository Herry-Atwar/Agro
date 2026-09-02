<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_sales_invoices');

// ─── Auto-number helpers ───────────────────────────────────────────────────────
function gen_inv_number(PDO $db, string $date): string {
    $ym     = date('Ym', strtotime($date));
    $prefix = 'INV-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED)),0)+1
        FROM sales_invoices WHERE invoice_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── Create one Journal Entry for an invoice (sum of all DO lines) ────────────
function create_invoice_journal(PDO $db, array $inv): ?int {
    $ar_map  = ['CPO'=>'1121','Kernel'=>'1122','FFB'=>'1123','PKO'=>'1121','Mixed'=>'1120','Other'=>'1120'];
    $rev_map = ['CPO'=>'4110','Kernel'=>'4120','FFB'=>'4100','PKO'=>'4130','Mixed'=>'4100','Other'=>'4100'];
    $ar_code  = $ar_map[$inv['product_type']]  ?? '1120';
    $rev_code = $rev_map[$inv['product_type']] ?? '4100';

    $ar_id  = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='$ar_code'  AND is_active=1 LIMIT 1")->fetchColumn();
    if (!$ar_id)  $ar_id  = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='1120' AND is_active=1 LIMIT 1")->fetchColumn();
    $rev_id = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='$rev_code' AND is_active=1 LIMIT 1")->fetchColumn();
    if (!$rev_id) $rev_id = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='4100' AND is_active=1 LIMIT 1")->fetchColumn();
    if (!$ar_id || !$rev_id) return null;

    $ym     = date('Ym', strtotime($inv['invoice_date']));
    $prefix = 'JE-INV-' . $ym . '-';
    $stmt   = $db->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reference_number,'-',-1) AS UNSIGNED)),0)+1 FROM journal_entries WHERE reference_number LIKE ?");
    $stmt->execute([$prefix . '%']);
    $ref    = $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
    $amount = (float)$inv['total_amount'];

    $db->prepare("
        INSERT INTO journal_entries
            (entry_date, entry_type, reference_number, description,
             company_id, total_debit, total_credit,
             currency_code, status, posted_date, posted_by, created_by)
        VALUES (?, 'sales_invoice', ?, ?, ?, ?, ?, 'IDR', 'posted', NOW(), 1, 'system')
    ")->execute([
        $inv['invoice_date'], $ref,
        'Sales Invoice ' . $inv['invoice_number'] . ' — ' . $inv['customer_name'] .
        ' (' . $inv['period_from'] . ' ~ ' . $inv['period_to'] . ')',
        $inv['company_id'], $amount, $amount,
    ]);
    $je_id = (int)$db->lastInsertId();

    $db->prepare("
        INSERT INTO journal_entry_lines
            (journal_entry_id, line_number, gl_account_id,
             debit_amount, credit_amount, base_currency_debit, base_currency_credit,
             currency_code, exchange_rate, description)
        VALUES (?,1,?, ?,0, ?,0, 'IDR',1,?)
    ")->execute([$je_id, $ar_id, $amount, $amount,
        'AR — ' . $inv['customer_name'] . ' (' . $inv['product_type'] . ')']);

    $db->prepare("
        INSERT INTO journal_entry_lines
            (journal_entry_id, line_number, gl_account_id,
             debit_amount, credit_amount, base_currency_debit, base_currency_credit,
             currency_code, exchange_rate, description)
        VALUES (?,2,?, 0,?, 0,?, 'IDR',1,?)
    ")->execute([$je_id, $rev_id, $amount, $amount,
        'Sales Revenue — ' . $inv['invoice_number']]);

    return $je_id;
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // ── Create invoice from selected DOs ──────────────────────────────────────
    if ($action === 'create_invoice') {
        $do_ids      = array_map('intval', $_POST['do_ids'] ?? []);
        $invoice_date = post('invoice_date') ?: date('Y-m-d');
        $due_date     = post('due_date') ?: null;
        $notes        = post('notes');

        if (empty($do_ids)) {
            set_message('error', 'Please select at least one Delivery Order.');
            redirect('sales_invoices.php');
        }

        try {
            $db->beginTransaction();

            // Load the selected DOs
            $in   = implode(',', $do_ids);
            $rows = $db->query("
                SELECT do2.*, cu.customer_name
                FROM delivery_orders do2
                JOIN customers cu ON do2.customer_id = cu.customer_id
                WHERE do2.do_id IN ($in)
                  AND do2.status = 'delivered'
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                throw new Exception('No delivered DOs found for the selected IDs.');
            }

            // All DOs must share the same company + customer
            $company_ids  = array_unique(array_column($rows, 'company_id'));
            $customer_ids = array_unique(array_column($rows, 'customer_id'));
            if (count($company_ids) > 1 || count($customer_ids) > 1) {
                throw new Exception('All selected DOs must belong to the same company and customer.');
            }

            // Determine product type (Mixed if more than one)
            $ptypes = array_unique(array_column($rows, 'product_type'));
            $ptype  = count($ptypes) === 1 ? $ptypes[0] : 'Mixed';

            $subtotal = array_sum(array_column($rows, 'total_amount'));
            $dates    = array_column($rows, 'do_date');
            sort($dates);

            $inv_num = gen_inv_number($db, $invoice_date);

            $db->prepare("
                INSERT INTO sales_invoices
                    (invoice_number, invoice_date, due_date,
                     company_id, customer_id, product_type,
                     period_from, period_to,
                     subtotal, total_amount, currency,
                     status, notes, created_by)
                VALUES (?,?,?, ?,?,?, ?,?, ?,?,'IDR', 'draft',?,'admin')
            ")->execute([
                $inv_num, $invoice_date, $due_date ?: null,
                $company_ids[0], $customer_ids[0], $ptype,
                $dates[0], end($dates),
                $subtotal, $subtotal,
                $notes,
            ]);
            $inv_id = (int)$db->lastInsertId();

            // Insert lines + mark DOs as invoiced
            foreach ($rows as $r) {
                $db->prepare("
                    INSERT INTO sales_invoice_lines
                        (invoice_id, do_id, do_number, do_date, product_type,
                         quantity_kg, unit_price, line_amount)
                    VALUES (?,?,?,?, ?, ?,?,?)
                ")->execute([
                    $inv_id, $r['do_id'], $r['do_number'], $r['do_date'], $r['product_type'],
                    $r['quantity_kg'], $r['unit_price'], $r['total_amount'],
                ]);

                $db->prepare("
                    UPDATE delivery_orders
                    SET status='invoiced', invoice_number=?, invoice_date=?, updated_by='admin'
                    WHERE do_id=?
                ")->execute([$inv_num, $invoice_date, $r['do_id']]);
            }

            // Auto-create & post journal entry
            $inv_row = array_merge(
                $db->query("SELECT * FROM sales_invoices WHERE invoice_id=$inv_id")->fetch(PDO::FETCH_ASSOC),
                ['customer_name' => $rows[0]['customer_name']]
            );
            $je_id = create_invoice_journal($db, $inv_row);

            $db->prepare("UPDATE sales_invoices SET status='posted', journal_entry_id=? WHERE invoice_id=?")
               ->execute([$je_id, $inv_id]);

            $db->commit();
            set_message('success', "Invoice <b>$inv_num</b> created for " . count($rows) . " DOs. Journal auto-posted.");
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('sales_invoices.php');
    }

    // ── Cancel invoice (draft only) ───────────────────────────────────────────
    if ($action === 'cancel_invoice') {
        $inv_id = (int)post('invoice_id');
        try {
            $db->beginTransaction();
            // Revert DOs back to delivered
            $db->prepare("
                UPDATE delivery_orders do2
                JOIN sales_invoice_lines sil ON sil.do_id = do2.do_id
                SET do2.status='delivered', do2.invoice_number=NULL, do2.invoice_date=NULL
                WHERE sil.invoice_id=?
            ")->execute([$inv_id]);
            $db->prepare("DELETE FROM sales_invoice_lines WHERE invoice_id=?")->execute([$inv_id]);
            $db->prepare("DELETE FROM sales_invoices WHERE invoice_id=? AND status='draft'")->execute([$inv_id]);
            $db->commit();
            set_message('success', 'Invoice cancelled and DOs reverted to delivered.');
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('sales_invoices.php');
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$year            = get('year', date('Y'));
$month           = get('month', '');
$customer_filter = get('customer_id', '');
$status_filter   = get('status', '');
$search          = get('search', '');

// ─── Reference data ───────────────────────────────────────────────────────────
$companies = $db->query("SELECT * FROM companies ORDER BY company_code")->fetchAll(PDO::FETCH_ASSOC);
$customers = $db->query("SELECT * FROM customers WHERE status='Active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

// Delivered DOs available for invoicing (grouped for the "New Invoice" selector)
$deliverable_dos = $db->query("
    SELECT do2.do_id, do2.do_number, do2.do_date, do2.product_type,
           do2.quantity_kg, do2.unit_price, do2.total_amount,
           do2.company_id, do2.customer_id,
           cu.customer_name, c.company_name AS company_name,
           sc.contract_number
    FROM delivery_orders do2
    JOIN customers cu ON do2.customer_id = cu.customer_id
    JOIN companies  c ON do2.company_id  = c.company_id
    JOIN sales_contracts sc ON do2.contract_id = sc.contract_id
    WHERE do2.status = 'delivered'
    ORDER BY do2.customer_id, do2.do_date
")->fetchAll(PDO::FETCH_ASSOC);

// Group deliverable DOs by customer for the modal selector
$dos_by_customer = [];
foreach ($deliverable_dos as $d) {
    $dos_by_customer[$d['customer_id']][] = $d;
}

// ─── Invoice list ─────────────────────────────────────────────────────────────
$sql = "
    SELECT si.*,
           cu.customer_name,
           c.company_name,
           COUNT(sil.line_id)          AS do_count,
           je.reference_number         AS je_ref
    FROM sales_invoices si
    JOIN customers cu ON si.customer_id = cu.customer_id
    JOIN companies  c ON si.company_id  = c.company_id
    LEFT JOIN sales_invoice_lines sil ON sil.invoice_id = si.invoice_id
    LEFT JOIN journal_entries je ON si.journal_entry_id = je.id
    WHERE YEAR(si.invoice_date) = ?";
$p = [$year];
if ($month)           { $sql .= " AND MONTH(si.invoice_date)=?";     $p[] = $month; }
if ($customer_filter) { $sql .= " AND si.customer_id=?";             $p[] = $customer_filter; }
if ($status_filter)   { $sql .= " AND si.status=?";                  $p[] = $status_filter; }
if ($search)          {
    $sql .= " AND (si.invoice_number LIKE ? OR cu.customer_name LIKE ?)";
    $t = "%$search%"; $p[] = $t; $p[] = $t;
}
$sql .= " GROUP BY si.invoice_id ORDER BY si.invoice_date DESC, si.invoice_id DESC";
$stmt = $db->prepare($sql); $stmt->execute($p);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$status_colours  = ['draft'=>'secondary','posted'=>'success','paid'=>'primary','cancelled'=>'danger'];
$product_colours = ['FFB'=>'success','CPO'=>'warning','Kernel'=>'info','PKO'=>'primary','Mixed'=>'secondary','Other'=>'secondary'];

require_once 'includes/header.php';
?>

<style>
    .inv-blue  { color: #1d4ed8 !important; }
    .bg-inv    { background-color: #1d4ed8 !important; }
    .btn-inv   { background-color: #1d4ed8; color:#fff; border:none; }
    .btn-inv:hover { background-color: #1e40af; color:#fff; }
    .do-check-row:hover { background:#f0f4ff; cursor:pointer; }
    .do-check-row.selected { background:#dbeafe; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="inv-blue"><i class="bi bi-receipt-cutoff"></i> Sales Invoices</h1>
            <p class="text-muted mb-0">
                <a href="sales_contracts.php"  class="text-decoration-none">Sales Contracts</a>
                &rsaquo; <a href="delivery_orders.php" class="text-decoration-none">Delivery Orders</a>
                &rsaquo; <b>Sales Invoices</b>
                &rsaquo; <a href="payment_receives.php" class="text-decoration-none">Payment Receives</a>
            </p>
        </div>
        <div class="col-auto">
            <?php if (!empty($deliverable_dos)): ?>
            <button class="btn btn-inv" data-bs-toggle="modal" data-bs-target="#createInvModal">
                <i class="bi bi-plus-circle"></i> New Invoice
                <span class="badge bg-warning text-dark ms-1"><?= count($deliverable_dos) ?> DO ready</span>
            </button>
            <?php else: ?>
            <button class="btn btn-inv" disabled title="No delivered DOs available">
                <i class="bi bi-plus-circle"></i> New Invoice
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- ── Filters ──────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-inv text-white py-2"><i class="bi bi-funnel"></i> Filter Invoices</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="Invoice # / customer…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-1">
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y=date('Y')-2;$y<=date('Y')+1;$y++): ?>
                        <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-1">
                <select name="month" class="form-select form-select-sm">
                    <option value="">All Months</option>
                    <?php for ($m=1;$m<=12;$m++): ?>
                        <option value="<?=$m?>" <?=$m==$month?'selected':''?>><?=date('M',mktime(0,0,0,$m,1))?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="customer_id" class="form-select form-select-sm">
                    <option value="">All Customers</option>
                    <?php foreach ($customers as $cu): ?>
                        <option value="<?=$cu['customer_id']?>" <?=$cu['customer_id']==$customer_filter?'selected':''?>>
                            <?= htmlspecialchars($cu['customer_name']) ?>
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
                <button type="submit" class="btn btn-inv btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="sales_invoices.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── KPI ───────────────────────────────────────────────────────────────────── -->
<?php
$total_inv = array_sum(array_column($invoices,'total_amount'));
$posted_cnt = count(array_filter($invoices, fn($r)=>$r['status']==='posted'));
$paid_cnt   = count(array_filter($invoices, fn($r)=>$r['status']==='paid'));
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Total Invoices</div>
        <div class="fw-bold fs-4"><?= count($invoices) ?></div>
        <div class="small text-muted"><?= $posted_cnt ?> posted · <?= $paid_cnt ?> paid</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Invoice Value</div>
        <div class="fw-bold fs-5">Rp <?= number_format($total_inv/1000000,1) ?>M</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">DO Ready to Invoice</div>
        <div class="fw-bold fs-4 text-warning"><?= count($deliverable_dos) ?></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Customers with Open DOs</div>
        <div class="fw-bold fs-4 text-info"><?= count($dos_by_customer) ?></div>
    </div></div></div>
</div>

<!-- ── Invoice Table ─────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header bg-inv text-white py-2">
        <i class="bi bi-table"></i> <?= count($invoices) ?> Invoice(s)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th class="text-center">DOs</th>
                        <th>Period</th>
                        <th class="text-end">Amount (Rp)</th>
                        <th>Journal</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No invoices found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $r): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($r['invoice_number']) ?></td>
                            <td><?= date('d/m/Y', strtotime($r['invoice_date'])) ?></td>
                            <td><?= htmlspecialchars($r['customer_name']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($r['company_name']) ?></small></td>
                            <td><span class="badge bg-<?= $product_colours[$r['product_type']]??'secondary' ?>"><?= $r['product_type'] ?></span></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary"
                                        onclick="showInvLines(<?= $r['invoice_id'] ?>)"
                                        title="View DOs in this invoice">
                                    <i class="bi bi-list-ul"></i>
                                    <span class="badge bg-primary"><?= $r['do_count'] ?></span>
                                </button>
                            </td>
                            <td class="small text-muted">
                                <?= $r['period_from'] ? date('d/m/Y',strtotime($r['period_from'])) : '—' ?>
                                <?= $r['period_to'] && $r['period_to'] !== $r['period_from'] ? ' ~ '.date('d/m/Y',strtotime($r['period_to'])) : '' ?>
                            </td>
                            <td class="text-end fw-bold"><?= number_format($r['total_amount'],0) ?></td>
                            <td>
                                <?php if ($r['je_ref']): ?>
                                    <a href="journal_entry_detail.php?ref=<?= urlencode($r['je_ref']) ?>"
                                       class="badge bg-info text-dark text-decoration-none">
                                        <?= htmlspecialchars($r['je_ref']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-<?= $status_colours[$r['status']]??'secondary' ?>"><?= ucfirst($r['status']) ?></span></td>
                            <td>
                                <?php if ($r['status'] === 'draft'): ?>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Cancel this invoice and revert DOs?')">
                                        <input type="hidden" name="action"     value="cancel_invoice">
                                        <input type="hidden" name="invoice_id" value="<?= $r['invoice_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-x-circle"></i> Cancel
                                        </button>
                                    </form>
                                <?php elseif ($r['status'] === 'posted'): ?>
                                    <a href="payment_receives.php?invoice_id=<?= $r['invoice_id'] ?>"
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

<!-- ── Create Invoice Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="createInvModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form method="POST" id="invForm">
            <input type="hidden" name="action" value="create_invoice">
            <div class="modal-content">
                <div class="modal-header bg-inv text-white">
                    <h5 class="modal-title"><i class="bi bi-receipt-cutoff"></i> New Sales Invoice</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <!-- Invoice header -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Filter by Customer</label>
                            <select id="filterCustomer" class="form-select form-select-sm" onchange="filterDOs()">
                                <option value="">All Customers</option>
                                <?php foreach ($customers as $cu): ?>
                                    <?php if (isset($dos_by_customer[$cu['customer_id']])): ?>
                                    <option value="<?= $cu['customer_id'] ?>"><?= htmlspecialchars($cu['customer_name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" id="filterFrom" class="form-control form-control-sm" onchange="filterDOs()">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" id="filterTo" class="form-control form-control-sm" onchange="filterDOs()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Invoice Date *</label>
                            <input type="date" name="invoice_date" class="form-control form-control-sm"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="due_date" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="1"></textarea>
                        </div>
                    </div>

                    <!-- DO selection table -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 inv-blue"><i class="bi bi-truck"></i> Select Delivery Orders to Invoice</h6>
                        <div class="d-flex gap-2 align-items-center">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll(true)">Select All</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAll(false)">Deselect All</button>
                            <span id="selCount" class="badge bg-inv">0 selected</span>
                            <span class="fw-bold text-success" id="selTotal">Rp 0</span>
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height:340px;overflow-y:auto;">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width:36px;"><input type="checkbox" id="chkAll" onchange="selectAll(this.checked)"></th>
                                    <th>DO #</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Contract</th>
                                    <th>Product</th>
                                    <th class="text-end">Qty (MT)</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Amount (Rp)</th>
                                </tr>
                            </thead>
                            <tbody id="doSelectBody">
                                <?php foreach ($deliverable_dos as $d): ?>
                                <tr class="do-check-row"
                                    data-customer="<?= $d['customer_id'] ?>"
                                    data-date="<?= $d['do_date'] ?>"
                                    data-amount="<?= $d['total_amount'] ?>"
                                    onclick="toggleRow(this)">
                                    <td onclick="event.stopPropagation()">
                                        <input type="checkbox" name="do_ids[]" value="<?= $d['do_id'] ?>"
                                               class="do-chk"
                                               onchange="updateSelection(); highlightRow(this)">
                                    </td>
                                    <td class="fw-bold"><?= htmlspecialchars($d['do_number']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($d['do_date'])) ?></td>
                                    <td><?= htmlspecialchars($d['customer_name']) ?></td>
                                    <td><?= htmlspecialchars($d['contract_number']) ?></td>
                                    <td><span class="badge bg-<?= $product_colours[$d['product_type']]??'secondary' ?>"><?= $d['product_type'] ?></span></td>
                                    <td class="text-end"><?= number_format($d['quantity_kg']/1000, 3) ?></td>
                                    <td class="text-end"><?= number_format($d['unit_price'], 0) ?></td>
                                    <td class="text-end fw-bold"><?= number_format($d['total_amount'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($deliverable_dos)): ?>
                                <tr><td colspan="9" class="text-center text-muted py-3">No delivered DOs available</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto text-muted small">
                        <i class="bi bi-info-circle"></i> All selected DOs must be from the same customer.
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-inv btn-sm" id="saveInvBtn" disabled>
                        <i class="bi bi-save"></i> Create Invoice
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Invoice Lines Modal ────────────────────────────────────────────────────── -->
<div class="modal fade" id="invLinesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-inv text-white">
                <h5 class="modal-title"><i class="bi bi-list-ul"></i> Delivery Orders in Invoice</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="invLinesBody">
                <div class="text-center text-muted py-3"><i class="bi bi-hourglass"></i> Loading…</div>
            </div>
        </div>
    </div>
</div>

<script>
// ── DO selection logic ────────────────────────────────────────────────────────
function updateSelection() {
    const checked = document.querySelectorAll('.do-chk:checked');
    const count   = checked.length;
    let total     = 0;
    let customers = new Set();

    document.querySelectorAll('#doSelectBody tr').forEach(tr => {
        const chk = tr.querySelector('.do-chk');
        if (chk && chk.checked) {
            total += parseFloat(tr.dataset.amount || 0);
            customers.add(tr.dataset.customer);
            tr.classList.add('selected');
        } else if (chk) {
            tr.classList.remove('selected');
        }
    });

    document.getElementById('selCount').textContent  = count + ' selected';
    document.getElementById('selTotal').textContent  = 'Rp ' + total.toLocaleString('id-ID', {maximumFractionDigits:0});
    const btn = document.getElementById('saveInvBtn');
    btn.disabled = count === 0;

    if (customers.size > 1) {
        btn.disabled = true;
        document.getElementById('selCount').textContent = count + ' selected ⚠ different customers!';
        document.getElementById('selCount').classList.add('bg-danger');
    } else {
        document.getElementById('selCount').classList.remove('bg-danger');
    }
}

function toggleRow(tr) {
    const chk = tr.querySelector('.do-chk');
    if (chk) { chk.checked = !chk.checked; updateSelection(); }
}

function highlightRow(chk) { updateSelection(); }

function selectAll(checked) {
    document.querySelectorAll('.do-chk').forEach(chk => {
        const tr = chk.closest('tr');
        if (tr && tr.style.display !== 'none') chk.checked = checked;
    });
    document.getElementById('chkAll').checked = checked;
    updateSelection();
}

function filterDOs() {
    const custId  = document.getElementById('filterCustomer').value;
    const fromVal = document.getElementById('filterFrom').value;
    const toVal   = document.getElementById('filterTo').value;

    document.querySelectorAll('#doSelectBody tr[data-date]').forEach(tr => {
        const matchCust = !custId  || tr.dataset.customer === custId;
        const matchFrom = !fromVal || tr.dataset.date >= fromVal;
        const matchTo   = !toVal   || tr.dataset.date <= toVal;
        tr.style.display = (matchCust && matchFrom && matchTo) ? '' : 'none';
        if (tr.style.display === 'none') {
            const chk = tr.querySelector('.do-chk');
            if (chk) chk.checked = false;
        }
    });
    updateSelection();
}

// ── Show invoice lines ────────────────────────────────────────────────────────
function showInvLines(invoiceId) {
    const modal = new bootstrap.Modal(document.getElementById('invLinesModal'));
    document.getElementById('invLinesBody').innerHTML =
        '<div class="text-center text-muted py-3"><i class="bi bi-hourglass"></i> Loading…</div>';
    modal.show();
    fetch('ajax_invoice_lines.php?invoice_id=' + invoiceId)
        .then(r => r.text())
        .then(html => { document.getElementById('invLinesBody').innerHTML = html; })
        .catch(() => { document.getElementById('invLinesBody').innerHTML =
            '<p class="text-danger p-3">Could not load lines.</p>'; });
}
</script>

<?php require_once 'includes/footer.php'; ?>
