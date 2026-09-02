<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_sales_contracts');

// ─── Auto-generate contract number ────────────────────────────────────────────
function gen_contract_number(PDO $db): string {
    $ym = date('Ym');
    $prefix = 'SC-' . $ym . '-';
    $stmt = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(contract_number,'-',-1) AS UNSIGNED)),0)+1
        FROM sales_contracts WHERE contract_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    $seq = (int) $stmt->fetchColumn();
    return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'create_contract') {
        try {
            $db->beginTransaction();
            $num = gen_contract_number($db);
            $db->prepare("
                INSERT INTO sales_contracts
                    (contract_number, contract_date, company_id, customer_id, product_type,
                     quantity_mt, unit_price, currency, delivery_start_date, delivery_end_date,
                     delivery_location, payment_terms, validity_date, status, notes, created_by)
                VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?,?,'active',?,'admin')
            ")->execute([
                $num, post('contract_date'), post('company_id'), post('customer_id'), post('product_type'),
                (float)post('quantity_mt'), (float)post('unit_price'), post('currency') ?: 'IDR',
                post('delivery_start_date'), post('delivery_end_date'),
                post('delivery_location'), post('payment_terms'),
                post('validity_date') ?: null,
                post('notes'),
            ]);
            $db->commit();
            set_message('success', "Contract <b>$num</b> created successfully!");
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('sales_contracts.php');
    }

    if ($action === 'update_contract') {
        try {
            $db->prepare("
                UPDATE sales_contracts SET
                    contract_date=?, company_id=?, customer_id=?, product_type=?,
                    quantity_mt=?, unit_price=?, currency=?,
                    delivery_start_date=?, delivery_end_date=?,
                    delivery_location=?, payment_terms=?, validity_date=?,
                    status=?, notes=?, updated_by='admin'
                WHERE contract_id=?
            ")->execute([
                post('contract_date'), post('company_id'), post('customer_id'), post('product_type'),
                (float)post('quantity_mt'), (float)post('unit_price'), post('currency') ?: 'IDR',
                post('delivery_start_date'), post('delivery_end_date'),
                post('delivery_location'), post('payment_terms'),
                post('validity_date') ?: null,
                post('status'), post('notes'),
                (int)post('contract_id'),
            ]);
            set_message('success', 'Contract updated successfully!');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('sales_contracts.php');
    }

    if ($action === 'delete_contract') {
        try {
            $db->prepare("DELETE FROM sales_contracts WHERE contract_id=?")->execute([(int)post('contract_id')]);
            set_message('success', 'Contract deleted.');
        } catch (PDOException $e) {
            set_message('error', 'Cannot delete: ' . $e->getMessage());
        }
        redirect('sales_contracts.php');
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

// ─── Contract list ────────────────────────────────────────────────────────────
try {
    $sql = "SELECT sc.*, c.company_name, cu.customer_name,
                   COALESCE(del.delivered_mt,0)       AS delivered_mt,
                   COALESCE(del.do_count,0)           AS do_count,
                   COALESCE(pay.paid_amount,0)        AS paid_amount
            FROM sales_contracts sc
            JOIN companies  c  ON sc.company_id  = c.company_id
            JOIN customers  cu ON sc.customer_id = cu.customer_id
            LEFT JOIN (
                SELECT contract_id,
                       SUM(quantity_mt) AS delivered_mt,
                       COUNT(*)         AS do_count
                FROM delivery_orders
                WHERE status NOT IN ('draft','cancelled')
                GROUP BY contract_id
            ) del ON del.contract_id = sc.contract_id
            LEFT JOIN (
                SELECT do2.contract_id,
                       SUM(pr.payment_amount) AS paid_amount
                FROM payment_receives pr
                JOIN delivery_orders do2 ON pr.do_id = do2.do_id
                GROUP BY do2.contract_id
            ) pay ON pay.contract_id = sc.contract_id
            WHERE YEAR(sc.contract_date)=?";
    $p = [$year];
    if ($company_filter) { $sql .= " AND sc.company_id=?"; $p[] = $company_filter; }
    if ($status_filter)  { $sql .= " AND sc.status=?";     $p[] = $status_filter; }
    if ($search) {
        $sql .= " AND (sc.contract_number LIKE ? OR cu.customer_name LIKE ?)";
        $t = "%$search%"; $p[] = $t; $p[] = $t;
    }
    $sql .= " ORDER BY sc.contract_date DESC, sc.contract_id DESC";
    $stmt = $db->prepare($sql); $stmt->execute($p);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $contracts = []; }

$status_colours = [
    'draft'               => 'secondary',
    'active'              => 'primary',
    'partially_delivered' => 'warning',
    'fully_delivered'     => 'success',
    'cancelled'           => 'danger',
];
$product_colours = ['FFB'=>'success','CPO'=>'warning','Kernel'=>'info','PKO'=>'primary','Other'=>'secondary'];

require_once 'includes/header.php';
?>

<style>
    .sc-blue { color: #0d6efd !important; }
    .bg-sc   { background-color: #0d6efd !important; }
    .btn-sc  { background-color: #0d6efd; color:#fff; border:none; }
    .btn-sc:hover { background-color: #0b5ed7; color:#fff; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="sc-blue"><i class="bi bi-file-earmark-text"></i> Sales Contracts</h1>
            <p class="text-muted mb-0">
                <a href="sales_contracts.php" class="text-decoration-none">Sales Contracts</a>
                &rsaquo; <a href="delivery_orders.php" class="text-decoration-none">Delivery Orders</a>
                &rsaquo; <a href="payment_receives.php" class="text-decoration-none">Payment Receives</a>
            </p>
        </div>
        <div class="col-auto">
            <button class="btn btn-sc" data-bs-toggle="modal" data-bs-target="#createModal">
                <i class="bi bi-plus-circle"></i> New Contract
            </button>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-header bg-sc text-white py-2"><i class="bi bi-funnel"></i> Filter Contracts</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="Contract # / customer…" value="<?= htmlspecialchars($search) ?>">
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
                        <option value="<?= $s ?>" <?= $s===$status_filter?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sc btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="sales_contracts.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- KPI strip -->
<?php
$total_value     = array_sum(array_column($contracts,'total_contract_value'));
$total_delivered = array_sum(array_column($contracts,'delivered_mt'));
$total_paid      = array_sum(array_column($contracts,'paid_amount'));
$active_cnt      = count(array_filter($contracts, fn($r)=>$r['status']==='active'));
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Total Contracts</div>
                <div class="fw-bold fs-4"><?= count($contracts) ?></div>
                <div class="small text-primary"><?= $active_cnt ?> active</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Contract Value</div>
                <div class="fw-bold fs-5">Rp <?= number_format($total_value/1000000,1) ?>M</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Delivered (MT)</div>
                <div class="fw-bold fs-5"><?= number_format($total_delivered,1) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Paid Amount</div>
                <div class="fw-bold fs-5 text-success">Rp <?= number_format($total_paid/1000000,1) ?>M</div>
            </div>
        </div>
    </div>
</div>

<!-- Contract table -->
<div class="card">
    <div class="card-header bg-sc text-white py-2">
        <i class="bi bi-table"></i> <?= count($contracts) ?> Contract(s) — <?= $year ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Contract #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th class="text-end">Qty (MT)</th>
                        <th class="text-end">Price/kg</th>
                        <th class="text-end">Value (Rp)</th>
                        <th class="text-end">Delivered%</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($contracts)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-3">No contracts found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($contracts as $r): ?>
                            <?php
                                $pct = $r['quantity_mt'] > 0
                                     ? round($r['delivered_mt'] / $r['quantity_mt'] * 100, 1)
                                     : 0;
                                $bar_col = $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-warning' : 'bg-info');
                            ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($r['contract_number']) ?></td>
                                <td><?= date('d/m/Y', strtotime($r['contract_date'])) ?></td>
                                <td><?= htmlspecialchars($r['customer_name']) ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($r['company_name']) ?></small></td>
                                <td><span class="badge bg-<?= $product_colours[$r['product_type']] ?? 'secondary' ?>"><?= $r['product_type'] ?></span></td>
                                <td class="text-end"><?= number_format($r['quantity_mt'],1) ?></td>
                                <td class="text-end"><?= number_format($r['unit_price'],0) ?></td>
                                <td class="text-end"><?= number_format($r['total_contract_value'],0) ?></td>
                                <td class="text-end">
                                    <div class="progress" style="height:6px;width:70px;margin-left:auto">
                                        <div class="progress-bar <?= $bar_col ?>" style="width:<?= min($pct,100) ?>%"></div>
                                    </div>
                                    <small><?= $pct ?>%</small>
                                </td>
                                <td><span class="badge bg-<?= $status_colours[$r['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
                                <td>
                                    <a href="delivery_orders.php?contract_id=<?= $r['contract_id'] ?>"
                                       class="btn btn-outline-primary btn-sm" title="View DOs">
                                        <i class="bi bi-truck"></i>
                                    </a>
                                    <button class="btn btn-outline-warning btn-sm"
                                            onclick="editContract(<?= htmlspecialchars(json_encode($r)) ?>)"
                                            title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($r['do_count'] == 0): ?>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete contract <?= htmlspecialchars($r['contract_number']) ?>?')">
                                        <input type="hidden" name="action" value="delete_contract">
                                        <input type="hidden" name="contract_id" value="<?= $r['contract_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                                    </form>
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

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <input type="hidden" name="action" value="create_contract">
            <div class="modal-content">
                <div class="modal-header bg-sc text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> New Sales Contract</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Contract Date *</label>
                            <input type="date" name="contract_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Company *</label>
                            <select name="company_id" class="form-select form-select-sm" required>
                                <option value="">— Select —</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= $c['company_id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Customer *</label>
                            <select name="customer_id" class="form-select form-select-sm" required>
                                <option value="">— Select —</option>
                                <?php foreach ($customers as $cu): ?>
                                    <option value="<?= $cu['customer_id'] ?>"><?= htmlspecialchars($cu['customer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Product *</label>
                            <select name="product_type" class="form-select form-select-sm" required>
                                <?php foreach (['FFB','CPO','Kernel','PKO','Other'] as $p): ?>
                                    <option value="<?= $p ?>"><?= $p ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Qty (MT) *</label>
                            <input type="number" step="0.001" name="quantity_mt" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit Price (IDR/kg) *</label>
                            <input type="number" step="0.01" name="unit_price" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Currency</label>
                            <select name="currency" class="form-select form-select-sm">
                                <option value="IDR">IDR</option>
                                <option value="USD">USD</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Delivery Start *</label>
                            <input type="date" name="delivery_start_date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Delivery End *</label>
                            <input type="date" name="delivery_end_date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Validity Date</label>
                            <input type="date" name="validity_date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Delivery Location</label>
                            <input type="text" name="delivery_location" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Terms</label>
                            <input type="text" name="payment_terms" class="form-control form-control-sm" placeholder="e.g. Credit 30 days">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sc btn-sm"><i class="bi bi-save"></i> Save Contract</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="update_contract">
            <input type="hidden" name="contract_id" id="eContractId">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Contract <span id="eContractNum"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Contract Date *</label>
                            <input type="date" name="contract_date" id="eContractDate" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Company *</label>
                            <select name="company_id" id="eCompanyId" class="form-select form-select-sm" required>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= $c['company_id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Customer *</label>
                            <select name="customer_id" id="eCustomerId" class="form-select form-select-sm" required>
                                <?php foreach ($customers as $cu): ?>
                                    <option value="<?= $cu['customer_id'] ?>"><?= htmlspecialchars($cu['customer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Product *</label>
                            <select name="product_type" id="eProductType" class="form-select form-select-sm" required>
                                <?php foreach (['FFB','CPO','Kernel','PKO','Other'] as $p): ?>
                                    <option value="<?= $p ?>"><?= $p ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Qty (MT) *</label>
                            <input type="number" step="0.001" name="quantity_mt" id="eQtyMt" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit Price (IDR/kg) *</label>
                            <input type="number" step="0.01" name="unit_price" id="eUnitPrice" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="eStatus" class="form-select form-select-sm">
                                <?php foreach (array_keys($status_colours) as $s): ?>
                                    <option value="<?= $s ?>"><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Delivery Start *</label>
                            <input type="date" name="delivery_start_date" id="eDelivStart" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Delivery End *</label>
                            <input type="date" name="delivery_end_date" id="eDelivEnd" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Validity Date</label>
                            <input type="date" name="validity_date" id="eValidity" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Delivery Location</label>
                            <input type="text" name="delivery_location" id="eDelivLoc" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Terms</label>
                            <input type="text" name="payment_terms" id="ePayTerms" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="eNotes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-save"></i> Update</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Bootstrap + JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editContract(r) {
    document.getElementById('eContractId').value   = r.contract_id;
    document.getElementById('eContractNum').textContent = r.contract_number;
    document.getElementById('eContractDate').value = r.contract_date;
    document.getElementById('eCompanyId').value    = r.company_id;
    document.getElementById('eCustomerId').value   = r.customer_id;
    document.getElementById('eProductType').value  = r.product_type;
    document.getElementById('eQtyMt').value        = r.quantity_mt;
    document.getElementById('eUnitPrice').value    = r.unit_price;
    document.getElementById('eStatus').value       = r.status;
    document.getElementById('eDelivStart').value   = r.delivery_start_date;
    document.getElementById('eDelivEnd').value     = r.delivery_end_date;
    document.getElementById('eValidity').value     = r.validity_date || '';
    document.getElementById('eDelivLoc').value     = r.delivery_location || '';
    document.getElementById('ePayTerms').value     = r.payment_terms || '';
    document.getElementById('eNotes').value        = r.notes || '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
