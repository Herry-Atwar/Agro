<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_product_deliveries');

// ─── Auto-number helpers ───────────────────────────────────────────────────────
function gen_pd_number(PDO $db): string {
    $ym     = date('Ym');
    $prefix = 'PD-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(delivery_number,'-',-1) AS UNSIGNED)),0)+1
        FROM product_deliveries WHERE delivery_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

function gen_pd_invoice(PDO $db): string {
    $ym     = date('Ym');
    $prefix = 'INV-PD-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED)),0)+1
        FROM product_deliveries WHERE invoice_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── Create journal entry (Dr AR / Cr Revenue) for a delivery ─────────────────
function create_pd_journal(PDO $db, array $pd, float $amount, string $inv_num, string $inv_date): ?int {
    $ar_map  = ['CPO'=>'1121','Kernel'=>'1122','FFB'=>'1123','PKO'=>'1121','Other'=>'1120'];
    $rev_map = ['CPO'=>'4110','Kernel'=>'4120','FFB'=>'4100','PKO'=>'4130','Other'=>'4100'];
    $ar_code  = $ar_map[$pd['product_type']]  ?? '1120';
    $rev_code = $rev_map[$pd['product_type']] ?? '4100';

    // Try specific sub-account first, fall back to parent account
    $ar_id  = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='$ar_code'  AND is_active=1 LIMIT 1")->fetchColumn();
    if (!$ar_id)  $ar_id  = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='1120' AND is_active=1 LIMIT 1")->fetchColumn();
    $rev_id = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='$rev_code' AND is_active=1 LIMIT 1")->fetchColumn();
    if (!$rev_id) $rev_id = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='4100' AND is_active=1 LIMIT 1")->fetchColumn();
    if (!$ar_id || !$rev_id) return null;

    $ym     = date('Ym', strtotime($inv_date));
    $prefix = 'JE-PD-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reference_number,'-',-1) AS UNSIGNED)),0)+1
        FROM journal_entries WHERE reference_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    $ref = $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);

    $db->prepare("
        INSERT INTO journal_entries
            (entry_date, entry_type, reference_number, description,
             company_id, total_debit, total_credit,
             currency_code, status, posted_date, posted_by, created_by)
        VALUES (?, 'sales_invoice', ?, ?, ?, ?, ?, 'IDR', 'posted', NOW(), 1, 'system')
    ")->execute([
        $inv_date, $ref,
        'Product Delivery Invoice ' . $inv_num . ' — ' . $pd['product_type'] . ' ' . $pd['delivery_number'],
        $pd['company_id'], $amount, $amount,
    ]);
    $je_id = (int)$db->lastInsertId();

    $db->prepare("
        INSERT INTO journal_entry_lines
            (journal_entry_id, line_number, gl_account_id,
             debit_amount, credit_amount,
             base_currency_debit, base_currency_credit,
             currency_code, exchange_rate, description)
        VALUES (?,1,?, ?,0, ?,0, 'IDR',1,?)
    ")->execute([$je_id, $ar_id, $amount, $amount, 'AR — ' . $pd['customer_name'] . ' (' . $pd['product_type'] . ')']);

    $db->prepare("
        INSERT INTO journal_entry_lines
            (journal_entry_id, line_number, gl_account_id,
             debit_amount, credit_amount,
             base_currency_debit, base_currency_credit,
             currency_code, exchange_rate, description)
        VALUES (?,2,?, 0,?, 0,?, 'IDR',1,?)
    ")->execute([$je_id, $rev_id, $amount, $amount, 'Revenue — ' . $pd['product_type'] . ' delivery ' . $pd['delivery_number']]);

    return $je_id;
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // ── Create new delivery + contract lines ──────────────────────────────────
    if ($action === 'create_delivery') {
        try {
            $db->beginTransaction();
            $num = gen_pd_number($db);

            $db->prepare("
                INSERT INTO product_deliveries
                    (delivery_number, delivery_date, company_id, customer_id, product_type,
                     gross_weight_kg, tare_weight_kg,
                     delivery_location, origin_location,
                     driver_name, vehicle_number, seal_number,
                     status, notes, created_by)
                VALUES (?,?,?,?,?, ?,?, ?,?, ?,?,?, 'draft',?,'admin')
            ")->execute([
                $num,
                post('delivery_date'),
                (int)post('company_id'), (int)post('customer_id'), post('product_type'),
                (float)post('gross_weight_kg'), (float)post('tare_weight_kg'),
                post('delivery_location'), post('origin_location'),
                post('driver_name'), post('vehicle_number'), post('seal_number'),
                post('notes'),
            ]);
            $del_id = (int)$db->lastInsertId();

            // Contract lines: arrays from form
            $contract_ids = $_POST['line_contract_id'] ?? [];
            $quantities   = $_POST['line_quantity_kg']  ?? [];
            $prices       = $_POST['line_unit_price']   ?? [];

            foreach ($contract_ids as $i => $cid) {
                $cid = (int)$cid;
                $qty = (float)($quantities[$i] ?? 0);
                $prc = (float)($prices[$i]     ?? 0);
                if ($cid <= 0 || $qty <= 0) continue;
                $db->prepare("
                    INSERT INTO delivery_contract_lines
                        (delivery_id, contract_id, quantity_kg, unit_price, notes)
                    VALUES (?,?,?,?,?)
                ")->execute([$del_id, $cid, $qty, $prc, post('notes')]);
            }

            $db->commit();
            set_message('success', "Delivery <b>$num</b> created successfully!");
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('product_deliveries.php');
    }

    // ── Update delivery header + lines (draft only) ───────────────────────────
    if ($action === 'update_delivery') {
        $del_id = (int)post('delivery_id');
        try {
            $db->beginTransaction();
            $db->prepare("
                UPDATE product_deliveries
                SET delivery_date=?, company_id=?, customer_id=?, product_type=?,
                    gross_weight_kg=?, tare_weight_kg=?,
                    delivery_location=?, origin_location=?,
                    driver_name=?, vehicle_number=?, seal_number=?,
                    notes=?, updated_by='admin'
                WHERE delivery_id=? AND status='draft'
            ")->execute([
                post('delivery_date'),
                (int)post('company_id'), (int)post('customer_id'), post('product_type'),
                (float)post('gross_weight_kg'), (float)post('tare_weight_kg'),
                post('delivery_location'), post('origin_location'),
                post('driver_name'), post('vehicle_number'), post('seal_number'),
                post('notes'),
                $del_id,
            ]);
            // Replace contract lines
            $db->prepare("DELETE FROM delivery_contract_lines WHERE delivery_id=?")->execute([$del_id]);
            $contract_ids = $_POST['line_contract_id'] ?? [];
            $quantities   = $_POST['line_quantity_kg']  ?? [];
            $prices       = $_POST['line_unit_price']   ?? [];
            foreach ($contract_ids as $i => $cid) {
                $cid = (int)$cid;
                $qty = (float)($quantities[$i] ?? 0);
                $prc = (float)($prices[$i]     ?? 0);
                if ($cid <= 0 || $qty <= 0) continue;
                $db->prepare("
                    INSERT INTO delivery_contract_lines
                        (delivery_id, contract_id, quantity_kg, unit_price, notes)
                    VALUES (?,?,?,?,?)
                ")->execute([$del_id, $cid, $qty, $prc, post('notes')]);
            }
            $db->commit();
            set_message('success', 'Delivery updated successfully.');
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('product_deliveries.php');
    }

    // ── Approve delivery (draft → confirmed) ──────────────────────────────────
    if ($action === 'approve_delivery') {
        try {
            $db->prepare("UPDATE product_deliveries SET status='confirmed', updated_by='admin' WHERE delivery_id=? AND status='draft'")
               ->execute([(int)post('delivery_id')]);
            set_message('success', 'Delivery approved and confirmed.');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('product_deliveries.php');
    }

    // ── Confirm delivery (confirmed → delivered) ───────────────────────────────
    if ($action === 'confirm_delivery') {
        try {
            $db->prepare("UPDATE product_deliveries SET status='delivered', updated_by='admin' WHERE delivery_id=? AND status='confirmed'")
               ->execute([(int)post('delivery_id')]);
            set_message('success', 'Delivery confirmed.');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('product_deliveries.php');
    }

    // ── Create invoice + auto-journal ─────────────────────────────────────────
    if ($action === 'create_invoice') {
        $del_id  = (int)post('delivery_id');
        $inv_date = post('invoice_date') ?: date('Y-m-d');
        try {
            $db->beginTransaction();
            $inv_num = gen_pd_invoice($db);

            // Fetch delivery + customer name
            $pd = $db->prepare("
                SELECT pd.*, cu.customer_name
                FROM product_deliveries pd
                JOIN customers cu ON pd.customer_id = cu.customer_id
                WHERE pd.delivery_id = ?
            ");
            $pd->execute([$del_id]);
            $row = $pd->fetch(PDO::FETCH_ASSOC);

            // Total invoice = sum of all contract lines
            $total = (float)$db->prepare("SELECT COALESCE(SUM(line_amount),0) FROM delivery_contract_lines WHERE delivery_id=?")
                               ->execute([$del_id]) ? $db->query("SELECT COALESCE(SUM(line_amount),0) FROM delivery_contract_lines WHERE delivery_id=$del_id")->fetchColumn() : 0;

            // recalculate properly
            $stmt = $db->prepare("SELECT COALESCE(SUM(line_amount),0) FROM delivery_contract_lines WHERE delivery_id=?");
            $stmt->execute([$del_id]);
            $total = (float)$stmt->fetchColumn();

            $je_id = create_pd_journal($db, $row, $total, $inv_num, $inv_date);

            $db->prepare("
                UPDATE product_deliveries
                SET status='invoiced', invoice_number=?, invoice_date=?, journal_entry_id=?,
                    updated_by='admin'
                WHERE delivery_id=?
            ")->execute([$inv_num, $inv_date, $je_id, $del_id]);

            $db->commit();
            set_message('success', "Invoice <b>$inv_num</b> created and journal auto-posted.");
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('product_deliveries.php');
    }

    // ── Delete draft delivery ─────────────────────────────────────────────────
    if ($action === 'delete_delivery') {
        try {
            $db->prepare("DELETE FROM product_deliveries WHERE delivery_id=? AND status='draft'")
               ->execute([(int)post('delivery_id')]);
            set_message('success', 'Delivery deleted.');
        } catch (PDOException $e) {
            set_message('error', 'Cannot delete: ' . $e->getMessage());
        }
        redirect('product_deliveries.php');
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$year           = get('year', date('Y'));
$company_filter = get('company_id', '');
$status_filter  = get('status', '');
$search         = get('search', '');

// ─── Reference data ───────────────────────────────────────────────────────────
$companies = $db->query("SELECT * FROM companies ORDER BY company_code")->fetchAll(PDO::FETCH_ASSOC);
$customers = $db->query("SELECT * FROM customers WHERE status='Active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

// Active/partially-delivered contracts for line selection
$contracts_ref = $db->query("
    SELECT sc.contract_id, sc.contract_number, sc.product_type,
           sc.quantity_mt, sc.unit_price, sc.company_id, sc.customer_id,
           sc.delivery_location, cu.customer_name
    FROM sales_contracts sc
    JOIN customers cu ON sc.customer_id = cu.customer_id
    WHERE sc.status NOT IN ('cancelled','fully_delivered')
    ORDER BY sc.contract_date DESC, sc.contract_number
")->fetchAll(PDO::FETCH_ASSOC);

// Keyed
$contracts_map = [];
foreach ($contracts_ref as $cr) { $contracts_map[$cr['contract_id']] = $cr; }

// ─── Delivery list ────────────────────────────────────────────────────────────
try {
    $sql = "SELECT pd.*, c.company_name, cu.customer_name,
                   COUNT(dcl.line_id)            AS contract_count,
                   COALESCE(SUM(dcl.quantity_kg),0) AS allocated_kg,
                   COALESCE(SUM(dcl.line_amount),0)  AS total_amount,
                   je.reference_number            AS je_ref
            FROM product_deliveries pd
            JOIN companies  c  ON pd.company_id  = c.company_id
            JOIN customers  cu ON pd.customer_id = cu.customer_id
            LEFT JOIN delivery_contract_lines dcl ON dcl.delivery_id = pd.delivery_id
            LEFT JOIN journal_entries je ON pd.journal_entry_id = je.id
            WHERE YEAR(pd.delivery_date) = ?";
    $p = [$year];
    if ($company_filter) { $sql .= " AND pd.company_id=?"; $p[] = $company_filter; }
    if ($status_filter)  { $sql .= " AND pd.status=?";     $p[] = $status_filter; }
    if ($search) {
        $sql .= " AND (pd.delivery_number LIKE ? OR cu.customer_name LIKE ? OR pd.vehicle_number LIKE ?)";
        $t = "%$search%"; $p[] = $t; $p[] = $t; $p[] = $t;
    }
    $sql .= " GROUP BY pd.delivery_id ORDER BY pd.delivery_date DESC, pd.delivery_id DESC";
    $stmt = $db->prepare($sql); $stmt->execute($p);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $deliveries = []; }

$status_colours = [
    'draft'     => 'secondary',
    'confirmed' => 'primary',
    'delivered' => 'info',
    'invoiced'  => 'warning',
    'received'  => 'success',
    'cancelled' => 'danger',
];
$product_colours = ['FFB'=>'success','CPO'=>'warning','Kernel'=>'info','PKO'=>'primary','Other'=>'secondary'];

require_once 'includes/header.php';
?>

<style>
    .pd-purple { color: #7c3aed !important; }
    .bg-pd     { background-color: #7c3aed !important; }
    .btn-pd    { background-color: #7c3aed; color:#fff; border:none; }
    .btn-pd:hover { background-color: #6d28d9; color:#fff; }
    .line-row   { background: #f8f7ff; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="pd-purple"><i class="bi bi-box-seam"></i> Product Deliveries</h1>
            <p class="text-muted mb-0">
                <a href="sales_contracts.php"   class="text-decoration-none">Sales Contracts</a>
                &rsaquo; <b>Product Deliveries</b>
                &rsaquo; <a href="payment_receives.php"  class="text-decoration-none">Payment Receives</a>
                &rsaquo; <a href="delivery_monitoring.php" class="text-decoration-none text-success">
                    <i class="bi bi-graph-up"></i> Monitoring
                </a>
            </p>
        </div>
        <div class="col-auto d-flex gap-2">
            <a href="delivery_monitoring.php" class="btn btn-outline-success btn-sm">
                <i class="bi bi-graph-up-arrow"></i> Monitoring
            </a>
            <button class="btn btn-pd" data-bs-toggle="modal" data-bs-target="#createPDModal">
                <i class="bi bi-plus-circle"></i> New Delivery
            </button>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- ── Info banner ─────────────────────────────────────────────────────────── -->
<div class="alert alert-info py-2 mb-3">
    <i class="bi bi-info-circle"></i>
    <strong>Multi-Contract Delivery:</strong> One truck/vessel can be allocated across
    <em>multiple sales contracts</em>. The weighbridge net weight is split into contract lines.
</div>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-pd text-white py-2"><i class="bi bi-funnel"></i> Filter Deliveries</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="PD # / customer / vehicle…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = date('Y')-2; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="company_id" class="form-select form-select-sm">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= $c['company_id'] ?>" <?= $c['company_id']==$company_filter?'selected':'' ?>>
                            <?= htmlspecialchars($c['company_name']) ?>
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
                <button type="submit" class="btn btn-pd btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="product_deliveries.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── KPI row ──────────────────────────────────────────────────────────────── -->
<?php
$total_net = array_sum(array_column($deliveries, 'net_weight_kg')) / 1000;
$total_amt = array_sum(array_column($deliveries, 'total_amount'));
$inv_count = count(array_filter($deliveries, fn($r) => $r['status'] === 'invoiced'));
$multi_cnt = count(array_filter($deliveries, fn($r) => (int)$r['contract_count'] > 1));
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Total Deliveries</div>
        <div class="fw-bold fs-4"><?= count($deliveries) ?></div>
        <div class="small text-success"><?= $inv_count ?> invoiced</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Net Weight (MT)</div>
        <div class="fw-bold fs-5"><?= number_format($total_net, 1) ?></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Invoice Value</div>
        <div class="fw-bold fs-5">Rp <?= number_format($total_amt/1000000, 1) ?>M</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Multi-Contract Deliveries</div>
        <div class="fw-bold fs-5 text-warning"><?= $multi_cnt ?></div>
        <div class="small text-muted">split across ≥2 contracts</div>
    </div></div></div>
</div>

<!-- ── Delivery Table ────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header bg-pd text-white py-2">
        <i class="bi bi-table"></i> <?= count($deliveries) ?> Product Delivery(s)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>PD #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th class="text-end">Net Wt (MT)</th>
                        <th class="text-center">Contracts</th>
                        <th class="text-end">Invoice Amt (Rp)</th>
                        <th>Invoice #</th>
                        <th>Journal</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4">No deliveries found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($deliveries as $r): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($r['delivery_number']) ?></td>
                                <td><?= date('d/m/Y', strtotime($r['delivery_date'])) ?></td>
                                <td><?= htmlspecialchars($r['customer_name']) ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($r['company_name']) ?></small></td>
                                <td><span class="badge bg-<?= $product_colours[$r['product_type']] ?? 'secondary' ?>"><?= $r['product_type'] ?></span></td>
                                <td class="text-end"><?= number_format($r['net_weight_kg']/1000, 3) ?></td>
                                <td class="text-center">
                                    <?php if ((int)$r['contract_count'] > 0): ?>
                                        <button class="btn btn-sm btn-outline-primary"
                                                onclick="showLines(<?= $r['delivery_id'] ?>)"
                                                title="View contract lines">
                                            <i class="bi bi-list-ul"></i>
                                            <span class="badge bg-primary"><?= $r['contract_count'] ?></span>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?= $r['total_amount'] > 0 ? number_format($r['total_amount'], 0) : '—' ?>
                                </td>
                                <td><?= $r['invoice_number']
                                    ? '<span class="badge bg-success">'.htmlspecialchars($r['invoice_number']).'</span>'
                                    : '—' ?></td>
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
                                <td><span class="badge bg-<?= $status_colours[$r['status']] ?? 'secondary' ?>"><?= ucfirst($r['status']) ?></span></td>
                                <td>
                                    <?php if ($r['status'] === 'draft'): ?>
                                        <!-- Edit -->
                                        <button class="btn btn-sm btn-outline-secondary"
                                                onclick="openEditModal(<?= $r['delivery_id'] ?>)"
                                                title="Edit Delivery">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <!-- Approve -->
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="approve_delivery">
                                            <input type="hidden" name="delivery_id" value="<?= $r['delivery_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary"
                                                    onclick="return confirm('Approve this delivery?')"
                                                    title="Approve">
                                                <i class="bi bi-check-circle"></i> Approve
                                            </button>
                                        </form>
                                        <!-- Delete -->
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Delete this delivery?')">
                                            <input type="hidden" name="action" value="delete_delivery">
                                            <input type="hidden" name="delivery_id" value="<?= $r['delivery_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($r['status'] === 'confirmed'): ?>
                                        <!-- Confirm Delivered -->
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="confirm_delivery">
                                            <input type="hidden" name="delivery_id" value="<?= $r['delivery_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-info"
                                                    onclick="return confirm('Mark as Delivered?')"
                                                    title="Confirm Delivered">
                                                <i class="bi bi-check2-circle"></i> Deliver
                                            </button>
                                        </form>
                                    <?php elseif ($r['status'] === 'delivered'): ?>
                                        <button class="btn btn-sm btn-outline-warning"
                                                onclick="openInvoiceModal(<?= $r['delivery_id'] ?>)"
                                                title="Create Invoice + Journal">
                                            <i class="bi bi-receipt"></i> Invoice
                                        </button>
                                    <?php elseif ($r['status'] === 'invoiced'): ?>
                                        <a href="delivery_receiving.php?delivery_id=<?= $r['delivery_id'] ?>"
                                           class="btn btn-sm btn-outline-warning" title="Record Customer Receiving">
                                            <i class="bi bi-clipboard2-check"></i> Receive
                                        </a>
                                        <a href="payment_receives.php"
                                           class="btn btn-sm btn-outline-success" title="Record Payment">
                                            <i class="bi bi-cash-coin"></i>
                                        </a>
                                    <?php elseif ($r['status'] === 'received'): ?>
                                        <a href="delivery_receiving.php" class="btn btn-sm btn-outline-success" title="View Receiving">
                                            <i class="bi bi-clipboard2-check-fill"></i>
                                        </a>
                                        <a href="delivery_complaints.php" class="btn btn-sm btn-outline-danger" title="View/Raise Complaints">
                                            <i class="bi bi-exclamation-triangle"></i>
                                        </a>
                                        <a href="payment_receives.php"
                                           class="btn btn-sm btn-outline-success" title="Record Payment">
                                            <i class="bi bi-cash-coin"></i>
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

<!-- ── Edit Delivery Modal ────────────────────────────────────────────────────── -->
<div class="modal fade" id="editPDModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form method="POST" id="editPdForm">
            <input type="hidden" name="action" value="update_delivery">
            <input type="hidden" name="delivery_id" id="editDelId">
            <div class="modal-content">
                <div class="modal-header bg-pd text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Product Delivery — <span id="editDelNumber"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Delivery Date *</label>
                            <input type="date" name="delivery_date" id="editDelDate" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Company *</label>
                            <select name="company_id" id="editDelCompany" class="form-select form-select-sm" required>
                                <option value="">— Select —</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= $c['company_id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Bill-to Customer *</label>
                            <select name="customer_id" id="editDelCustomer" class="form-select form-select-sm" required>
                                <option value="">— Select —</option>
                                <?php foreach ($customers as $cu): ?>
                                    <option value="<?= $cu['customer_id'] ?>"><?= htmlspecialchars($cu['customer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Product Type *</label>
                            <select name="product_type" id="editDelProduct" class="form-select form-select-sm" required>
                                <option value="">— Select —</option>
                                <option value="CPO">CPO</option>
                                <option value="Kernel">Kernel</option>
                                <option value="FFB">FFB</option>
                                <option value="PKO">PKO</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gross Weight (kg) *</label>
                            <input type="number" step="0.01" name="gross_weight_kg" id="editGross"
                                   class="form-control form-control-sm" required oninput="updateEditNet()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tare Weight (kg) *</label>
                            <input type="number" step="0.01" name="tare_weight_kg" id="editTare"
                                   class="form-control form-control-sm" required oninput="updateEditNet()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Net Weight (kg)</label>
                            <input type="text" id="editNet" class="form-control form-control-sm" readonly
                                   style="background:#f0fff4;" placeholder="Auto-calculated">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Vehicle #</label>
                            <input type="text" name="vehicle_number" id="editVehicle" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Driver Name</label>
                            <input type="text" name="driver_name" id="editDriver" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Seal #</label>
                            <input type="text" name="seal_number" id="editSeal" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Delivery Location</label>
                            <input type="text" name="delivery_location" id="editDelivLoc" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Origin Location</label>
                            <input type="text" name="origin_location" id="editOriginLoc" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="editNotes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 pd-purple"><i class="bi bi-list-columns-reverse"></i> Contract Allocation Lines</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addEditLine()">
                            <i class="bi bi-plus-lg"></i> Add Contract Line
                        </button>
                    </div>
                    <div class="alert alert-warning py-1 mb-2 small">
                        <i class="bi bi-exclamation-triangle"></i>
                        Split the net weight across one or more contracts.
                        <span id="editAllocationNote" class="fw-bold"></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm" id="editLinesTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width:220px">Contract *</th>
                                    <th style="min-width:130px">Qty (kg) *</th>
                                    <th style="min-width:130px">Unit Price (IDR/kg)</th>
                                    <th style="min-width:120px">Line Amount</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="editLinesBody"></tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td>Total Allocated</td>
                                    <td id="editTotalAllocKg" class="text-end">0.00 kg</td>
                                    <td></td>
                                    <td id="editTotalLineAmt" class="text-end">Rp 0</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-pd btn-sm"><i class="bi bi-save"></i> Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Create Delivery Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="createPDModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form method="POST" id="pdForm">
            <input type="hidden" name="action" value="create_delivery">
            <div class="modal-content">
                <div class="modal-header bg-pd text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> New Product Delivery</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <!-- Header fields -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Delivery Date *</label>
                            <input type="date" name="delivery_date" class="form-control form-control-sm"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Company *</label>
                            <select name="company_id" class="form-select form-select-sm" required>
                                <option value="">— Select —</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= $c['company_id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Bill-to Customer *</label>
                            <select name="customer_id" class="form-select form-select-sm" required>
                                <option value="">— Select —</option>
                                <?php foreach ($customers as $cu): ?>
                                    <option value="<?= $cu['customer_id'] ?>"><?= htmlspecialchars($cu['customer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Product Type *</label>
                            <select name="product_type" id="pdProductType" class="form-select form-select-sm" required>
                                <option value="">— Select —</option>
                                <option value="CPO">CPO</option>
                                <option value="Kernel">Kernel</option>
                                <option value="FFB">FFB</option>
                                <option value="PKO">PKO</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gross Weight (kg) *</label>
                            <input type="number" step="0.01" name="gross_weight_kg" id="pdGross"
                                   class="form-control form-control-sm" required oninput="updateNetWeight()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tare Weight (kg) *</label>
                            <input type="number" step="0.01" name="tare_weight_kg" id="pdTare"
                                   class="form-control form-control-sm" required oninput="updateNetWeight()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Net Weight (kg)</label>
                            <input type="text" id="pdNet" class="form-control form-control-sm" readonly
                                   placeholder="Auto-calculated" style="background:#f0fff4;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Vehicle #</label>
                            <input type="text" name="vehicle_number" class="form-control form-control-sm" placeholder="e.g. BM-1234-AB">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Driver Name</label>
                            <input type="text" name="driver_name" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Seal #</label>
                            <input type="text" name="seal_number" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Delivery Location</label>
                            <input type="text" name="delivery_location" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Origin Location</label>
                            <input type="text" name="origin_location" class="form-control form-control-sm" placeholder="Mill / Tank">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>

                    <!-- Contract Lines -->
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 pd-purple"><i class="bi bi-list-columns-reverse"></i> Contract Allocation Lines</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addLine()">
                            <i class="bi bi-plus-lg"></i> Add Contract Line
                        </button>
                    </div>
                    <div class="alert alert-warning py-1 mb-2 small">
                        <i class="bi bi-exclamation-triangle"></i>
                        Split the net weight across one or more contracts.
                        <span id="allocationNote" class="fw-bold"></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm" id="linesTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width:220px">Contract *</th>
                                    <th style="min-width:130px">Qty (kg) *</th>
                                    <th style="min-width:130px">Unit Price (IDR/kg)</th>
                                    <th style="min-width:120px">Line Amount</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="linesBody">
                                <!-- rows injected by JS -->
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td>Total Allocated</td>
                                    <td id="totalAllocKg" class="text-end">0.00 kg</td>
                                    <td></td>
                                    <td id="totalLineAmt" class="text-end">Rp 0</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-pd btn-sm"><i class="bi bi-save"></i> Save Delivery</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Invoice Modal ──────────────────────────────────────────────────────────── -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <input type="hidden" name="action" value="create_invoice">
            <input type="hidden" name="delivery_id" id="invDelId">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-receipt"></i> Create Invoice + Journal Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Generates an invoice for the <strong>entire delivery</strong>
                        (sum of all contract lines) and posts:<br>
                        Dr Accounts Receivable → Cr Revenue
                    </p>
                    <div class="mb-3">
                        <label class="form-label">Invoice Date *</label>
                        <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
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

<!-- ── Lines Detail Modal ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="linesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-pd text-white">
                <h5 class="modal-title"><i class="bi bi-list-ul"></i> Contract Allocation Lines</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="linesModalBody">
                <div class="text-center text-muted py-3"><i class="bi bi-hourglass"></i> Loading…</div>
            </div>
        </div>
    </div>
</div>

<script>
// ── Contracts data for JS auto-fill ───────────────────────────────────────────
const contractsData = <?= json_encode(array_values($contracts_map), JSON_NUMERIC_CHECK) ?>;
const contractsMap  = {};
contractsData.forEach(c => { contractsMap[c.contract_id] = c; });

let lineIndex = 0;

function addLine() {
    const tbody = document.getElementById('linesBody');
    const idx   = lineIndex++;
    const row   = document.createElement('tr');
    row.id = 'line_' + idx;
    row.innerHTML = `
        <td>
            <select name="line_contract_id[]" class="form-select form-select-sm"
                    onchange="fillLineDefaults(this, ${idx})" required>
                <option value="">— Select Contract —</option>
                ${contractsData.map(c =>
                    `<option value="${c.contract_id}"
                        data-price="${c.unit_price}"
                        data-location="${(c.delivery_location||'').replace(/"/g,'&quot;')}"
                        data-product="${c.product_type}">
                        ${c.contract_number} — ${c.customer_name} (${c.product_type})
                    </option>`
                ).join('')}
            </select>
        </td>
        <td>
            <input type="number" step="0.01" name="line_quantity_kg[]"
                   id="lineQty_${idx}" class="form-control form-control-sm"
                   oninput="recalcLine(${idx}); recalcTotals()" required placeholder="0">
        </td>
        <td>
            <input type="number" step="0.01" name="line_unit_price[]"
                   id="linePrice_${idx}" class="form-control form-control-sm"
                   oninput="recalcLine(${idx}); recalcTotals()" placeholder="0">
        </td>
        <td>
            <input type="text" id="lineAmt_${idx}" class="form-control form-control-sm"
                   readonly style="background:#f0fff4;" value="Rp 0">
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="removeLine(${idx})"><i class="bi bi-x-lg"></i></button>
        </td>`;
    tbody.appendChild(row);
}

function fillLineDefaults(sel, idx) {
    const cid = parseInt(sel.value);
    if (!cid || !contractsMap[cid]) return;
    const c = contractsMap[cid];
    document.getElementById('linePrice_' + idx).value = c.unit_price || '';
    recalcLine(idx);
    recalcTotals();
}

function recalcLine(idx) {
    const qty = parseFloat(document.getElementById('lineQty_'  + idx)?.value) || 0;
    const prc = parseFloat(document.getElementById('linePrice_'+ idx)?.value) || 0;
    const amt = qty * prc;
    const amtEl = document.getElementById('lineAmt_' + idx);
    if (amtEl) amtEl.value = 'Rp ' + amt.toLocaleString('id-ID', {maximumFractionDigits:0});
}

function recalcTotals() {
    let totalKg = 0, totalAmt = 0;
    document.querySelectorAll('[name="line_quantity_kg[]"]').forEach(el => { totalKg += parseFloat(el.value)||0; });
    document.querySelectorAll('[name="line_unit_price[]"]').forEach((el, i) => {
        const qty = parseFloat(document.querySelectorAll('[name="line_quantity_kg[]"]')[i]?.value)||0;
        totalAmt += qty * (parseFloat(el.value)||0);
    });
    document.getElementById('totalAllocKg').textContent = totalKg.toLocaleString('id-ID',{maximumFractionDigits:2}) + ' kg';
    document.getElementById('totalLineAmt').textContent = 'Rp ' + totalAmt.toLocaleString('id-ID',{maximumFractionDigits:0});

    const net = parseFloat(document.getElementById('pdNet').value.replace(/[^0-9.]/g,'')) || 0;
    if (net > 0) {
        const diff = net - totalKg;
        document.getElementById('allocationNote').textContent =
            diff === 0 ? '✓ Fully allocated'
            : diff > 0 ? `⚠ Under-allocated by ${diff.toLocaleString('id-ID')} kg`
            : `⚠ Over-allocated by ${Math.abs(diff).toLocaleString('id-ID')} kg`;
    }
}

function removeLine(idx) {
    document.getElementById('line_' + idx)?.remove();
    recalcTotals();
}

function updateNetWeight() {
    const gross = parseFloat(document.getElementById('pdGross').value) || 0;
    const tare  = parseFloat(document.getElementById('pdTare').value)  || 0;
    const net   = gross - tare;
    document.getElementById('pdNet').value = net > 0 ? net.toFixed(2) : '';
    recalcTotals();
}

function openInvoiceModal(delId) {
    document.getElementById('invDelId').value = delId;
    new bootstrap.Modal(document.getElementById('invoiceModal')).show();
}

// ── Edit delivery modal ───────────────────────────────────────────────────────
let editLineIndex = 0;

function addEditLine(cid, qty, price) {
    const tbody = document.getElementById('editLinesBody');
    const idx   = editLineIndex++;
    const row   = document.createElement('tr');
    row.id = 'editline_' + idx;
    row.innerHTML = `
        <td>
            <select name="line_contract_id[]" class="form-select form-select-sm"
                    onchange="fillEditLineDefaults(this, ${idx})" required>
                <option value="">— Select Contract —</option>
                ${contractsData.map(c =>
                    `<option value="${c.contract_id}"
                        data-price="${c.unit_price}">
                        ${c.contract_number} — ${c.customer_name} (${c.product_type})
                    </option>`
                ).join('')}
            </select>
        </td>
        <td>
            <input type="number" step="0.01" name="line_quantity_kg[]"
                   id="editLineQty_${idx}" class="form-control form-control-sm"
                   oninput="recalcEditLine(${idx}); recalcEditTotals()" required placeholder="0">
        </td>
        <td>
            <input type="number" step="0.01" name="line_unit_price[]"
                   id="editLinePrice_${idx}" class="form-control form-control-sm"
                   oninput="recalcEditLine(${idx}); recalcEditTotals()" placeholder="0">
        </td>
        <td>
            <input type="text" id="editLineAmt_${idx}" class="form-control form-control-sm"
                   readonly style="background:#f0fff4;" value="Rp 0">
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="removeEditLine(${idx})"><i class="bi bi-x-lg"></i></button>
        </td>`;
    tbody.appendChild(row);
    // Pre-fill if values provided
    if (cid) {
        const sel = row.querySelector('select');
        sel.value = cid;
        document.getElementById('editLineQty_'   + idx).value = qty   || '';
        document.getElementById('editLinePrice_' + idx).value = price || '';
        recalcEditLine(idx);
        recalcEditTotals();
    }
}

function fillEditLineDefaults(sel, idx) {
    const cid = parseInt(sel.value);
    if (!cid || !contractsMap[cid]) return;
    document.getElementById('editLinePrice_' + idx).value = contractsMap[cid].unit_price || '';
    recalcEditLine(idx);
    recalcEditTotals();
}

function recalcEditLine(idx) {
    const qty = parseFloat(document.getElementById('editLineQty_'   + idx)?.value) || 0;
    const prc = parseFloat(document.getElementById('editLinePrice_' + idx)?.value) || 0;
    const el  = document.getElementById('editLineAmt_' + idx);
    if (el) el.value = 'Rp ' + (qty * prc).toLocaleString('id-ID', {maximumFractionDigits: 0});
}

function removeEditLine(idx) {
    document.getElementById('editline_' + idx)?.remove();
    recalcEditTotals();
}

function recalcEditTotals() {
    let totalKg = 0, totalAmt = 0;
    document.querySelectorAll('#editLinesBody [name="line_quantity_kg[]"]').forEach(el => { totalKg += parseFloat(el.value)||0; });
    document.querySelectorAll('#editLinesBody [name="line_unit_price[]"]').forEach((el, i) => {
        const qty = parseFloat(document.querySelectorAll('#editLinesBody [name="line_quantity_kg[]"]')[i]?.value)||0;
        totalAmt += qty * (parseFloat(el.value)||0);
    });
    document.getElementById('editTotalAllocKg').textContent = totalKg.toLocaleString('id-ID', {maximumFractionDigits: 2}) + ' kg';
    document.getElementById('editTotalLineAmt').textContent = 'Rp ' + totalAmt.toLocaleString('id-ID', {maximumFractionDigits: 0});
    const net = parseFloat(document.getElementById('editNet').value.replace(/[^0-9.]/g, '')) || 0;
    if (net > 0) {
        const diff = net - totalKg;
        document.getElementById('editAllocationNote').textContent =
            diff === 0 ? '✓ Fully allocated'
            : diff > 0 ? `⚠ Under-allocated by ${diff.toLocaleString('id-ID')} kg`
            : `⚠ Over-allocated by ${Math.abs(diff).toLocaleString('id-ID')} kg`;
    }
}

function updateEditNet() {
    const gross = parseFloat(document.getElementById('editGross').value) || 0;
    const tare  = parseFloat(document.getElementById('editTare').value)  || 0;
    const net   = gross - tare;
    document.getElementById('editNet').value = net > 0 ? net.toFixed(2) : '';
    recalcEditTotals();
}

function openEditModal(delId) {
    // Fetch existing lines via AJAX, then populate form
    fetch('ajax_delivery_lines.php?delivery_id=' + delId + '&format=json')
        .then(r => r.json())
        .then(data => {
            const h = data.header;
            document.getElementById('editDelId').value          = h.delivery_id;
            document.getElementById('editDelNumber').textContent = h.delivery_number;
            document.getElementById('editDelDate').value        = h.delivery_date;
            document.getElementById('editDelCompany').value     = h.company_id;
            document.getElementById('editDelCustomer').value    = h.customer_id;
            document.getElementById('editDelProduct').value     = h.product_type;
            document.getElementById('editGross').value          = h.gross_weight_kg || '';
            document.getElementById('editTare').value           = h.tare_weight_kg  || '';
            document.getElementById('editNet').value            = h.net_weight_kg   ? parseFloat(h.net_weight_kg).toFixed(2) : '';
            document.getElementById('editVehicle').value        = h.vehicle_number  || '';
            document.getElementById('editDriver').value         = h.driver_name     || '';
            document.getElementById('editSeal').value           = h.seal_number     || '';
            document.getElementById('editDelivLoc').value       = h.delivery_location || '';
            document.getElementById('editOriginLoc').value      = h.origin_location  || '';
            document.getElementById('editNotes').value          = h.notes            || '';
            // Clear and repopulate lines
            document.getElementById('editLinesBody').innerHTML = '';
            editLineIndex = 0;
            (data.lines || []).forEach(l => addEditLine(l.contract_id, l.quantity_kg, l.unit_price));
            if ((data.lines || []).length === 0) addEditLine();
            recalcEditTotals();
            new bootstrap.Modal(document.getElementById('editPDModal')).show();
        })
        .catch(() => alert('Could not load delivery data.'));
}

// ── Show contract lines via AJAX-like approach (inline data) ──────────────────
function showLines(deliveryId) {
    const modal = new bootstrap.Modal(document.getElementById('linesModal'));
    document.getElementById('linesModalBody').innerHTML =
        '<div class="text-center text-muted py-3"><i class="bi bi-hourglass"></i> Loading…</div>';
    modal.show();
    fetch('ajax_delivery_lines.php?delivery_id=' + deliveryId)
        .then(r => r.text())
        .then(html => { document.getElementById('linesModalBody').innerHTML = html; })
        .catch(() => {
            document.getElementById('linesModalBody').innerHTML =
                '<p class="text-danger p-3">Could not load lines.</p>';
        });
}

// Auto-add one line on modal open
document.getElementById('createPDModal').addEventListener('show.bs.modal', () => {
    if (document.getElementById('linesBody').children.length === 0) addLine();
});
</script>

<?php require_once 'includes/footer.php'; ?>
