<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "AP Bills";

// ─── Auto-generate bill number ────────────────────────────────────────────────
function gen_bill_number(PDO $db): string {
    $prefix = 'AP-' . date('Ym') . '-';
    $max = $db->query("SELECT MAX(CAST(SUBSTRING(bill_number, " . (strlen($prefix)+1) . ") AS UNSIGNED)) FROM ap_bills WHERE bill_number LIKE '$prefix%'")->fetchColumn();
    return $prefix . str_pad(((int)$max) + 1, 4, '0', STR_PAD_LEFT);
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // Create
    if ($action === 'create_bill') {
        try {
            $amt = (float) post('amount_foreign');
            $fx  = (float) post('exchange_rate') ?: 1;
            $out = (float) post('outstanding_amount') ?: $amt * $fx;
            $db->prepare("
                INSERT INTO ap_bills
                    (bill_number, vendor_name, vendor_ref, company_id, business_unit_id, division_id,
                     currency_code, amount_foreign, exchange_rate, outstanding_amount,
                     bill_date, due_date, planned_payment_date,
                     cf_subcategory_id, bill_category, payment_priority,
                     status, description, notes, created_by)
                VALUES (?,?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?, ?,?,?,?)
            ")->execute([
                gen_bill_number($db),
                post('vendor_name'), post('vendor_ref'),
                (int) post('company_id'),
                post('business_unit_id') ?: null,
                post('division_id')      ?: null,
                post('currency_code') ?: 'IDR', $amt, $fx, $out,
                post('bill_date'), post('due_date'),
                post('planned_payment_date') ?: null,
                post('cf_subcategory_id')    ?: null,
                post('bill_category') ?: 'other',
                post('payment_priority') ?: 'normal',
                'approved',
                post('description'), post('notes'), 'admin',
            ]);
            set_message('success', 'AP Bill created successfully!');
        } catch (PDOException $e) {
            set_message('error', 'Error creating bill: ' . $e->getMessage());
        }
        redirect('ap_bills.php');
    }

    // Update
    if ($action === 'update_bill') {
        try {
            $amt = (float) post('amount_foreign');
            $fx  = (float) post('exchange_rate') ?: 1;
            $db->prepare("
                UPDATE ap_bills SET
                    vendor_name = ?, vendor_ref = ?,
                    currency_code = ?, amount_foreign = ?, exchange_rate = ?,
                    outstanding_amount = ?,
                    bill_date = ?, due_date = ?, planned_payment_date = ?,
                    cf_subcategory_id = ?, bill_category = ?, payment_priority = ?,
                    status = ?, description = ?, notes = ?, updated_by = 'admin'
                WHERE id = ?
            ")->execute([
                post('vendor_name'), post('vendor_ref'),
                post('currency_code') ?: 'IDR', $amt, $fx,
                (float) post('outstanding_amount'),
                post('bill_date'), post('due_date'),
                post('planned_payment_date') ?: null,
                post('cf_subcategory_id')    ?: null,
                post('bill_category') ?: 'other',
                post('payment_priority') ?: 'normal',
                post('status') ?: 'approved',
                post('description'), post('notes'),
                (int) post('bill_id'),
            ]);
            set_message('success', 'AP Bill updated successfully!');
        } catch (PDOException $e) {
            set_message('error', 'Error updating bill: ' . $e->getMessage());
        }
        redirect('ap_bills.php');
    }

    // Mark paid
    if ($action === 'mark_paid') {
        try {
            $db->prepare("
                UPDATE ap_bills SET status='paid', outstanding_amount=0,
                    paid_at=NOW(), updated_by='admin'
                WHERE id=?
            ")->execute([(int) post('bill_id')]);
            set_message('success', 'Bill marked as paid.');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('ap_bills.php');
    }
}

require_once 'includes/header.php';

// ─── Filters ──────────────────────────────────────────────────────────────────
$company_id = (int) get('company_id', 0);
$bucket     = get('bucket', '');
$category   = get('category', '');
$status_f   = get('status', '');
$search     = get('search', '');

// ─── Stat cards ───────────────────────────────────────────────────────────────
$sWhere = $company_id ? "AND company_id = $company_id" : '';
$stats = $db->query("
    SELECT
        COUNT(*)                                                        AS total_bills,
        COALESCE(SUM(outstanding_amount),0)                            AS total_outstanding,
        COALESCE(SUM(CASE WHEN due_date >= CURDATE() THEN outstanding_amount ELSE 0 END),0) AS current_due,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(),due_date) BETWEEN 1 AND 30  THEN outstanding_amount ELSE 0 END),0) AS overdue_30,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(),due_date) BETWEEN 31 AND 60 THEN outstanding_amount ELSE 0 END),0) AS overdue_60,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(),due_date) > 60              THEN outstanding_amount ELSE 0 END),0) AS overdue_60p
    FROM ap_bills
    WHERE status IN ('draft','approved') AND outstanding_amount > 0 $sWhere
")->fetch();

// ─── Dropdowns ────────────────────────────────────────────────────────────────
$companies   = $db->query("SELECT company_id, company_name FROM companies ORDER BY company_name")->fetchAll();
$cfCats      = $db->query("SELECT id, code, name FROM cf_subcategories WHERE cf_category LIKE 'operating_payment' ORDER BY display_order")->fetchAll();

// ─── Bill list ────────────────────────────────────────────────────────────────
$where  = ["b.status IN ('draft','approved')"];
$params = [];
if ($company_id) { $where[] = "b.company_id = ?"; $params[] = $company_id; }
if ($category)   { $where[] = "b.bill_category = ?"; $params[] = $category; }
if ($status_f)   { $where[] = "b.status = ?"; $params[] = $status_f; }
if ($search)     { $where[] = "(b.vendor_name LIKE ? OR b.bill_number LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($bucket === 'Current')   { $where[] = "b.due_date >= CURDATE()"; }
elseif ($bucket === '1-30 days')  { $where[] = "DATEDIFF(CURDATE(),b.due_date) BETWEEN 1 AND 30"; }
elseif ($bucket === '31-60 days') { $where[] = "DATEDIFF(CURDATE(),b.due_date) BETWEEN 31 AND 60"; }
elseif ($bucket === '60+ days')   { $where[] = "DATEDIFF(CURDATE(),b.due_date) > 60"; }

$sql = "
    SELECT b.*, co.company_name, bu.unit_name, cs.code AS cf_code
    FROM ap_bills b
    JOIN  companies co     ON b.company_id        = co.company_id
    LEFT JOIN business_units bu ON b.business_unit_id = bu.business_unit_id
    LEFT JOIN cf_subcategories cs ON b.cf_subcategory_id = cs.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.due_date ASC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$bills = $stmt->fetchAll();
?>

<div class="content-wrapper">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-file-earmark-text"></i> AP Bills</h1>
                <p class="text-muted mb-0">Accounts Payable · supplier bills · payment schedule</p>
            </div>
            <div>
                <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#createBillModal">
                    <i class="bi bi-plus-circle"></i> New Bill
                </button>
                <a href="cash_forecast.php" class="btn btn-outline-success">
                    <i class="bi bi-graph-up-arrow"></i> Cash Forecast
                </a>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card stat-card text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Total Outstanding</h6>
                    <h4 class="mb-0 text-danger">Rp <?= number_format($stats['total_outstanding'],0) ?></h4>
                    <small class="text-muted"><?= $stats['total_bills'] ?> bill(s)</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-success border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Not Yet Due</h6>
                    <h5 class="mb-0 text-success">Rp <?= number_format($stats['current_due'],0) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-warning border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">1–30 days overdue</h6>
                    <h5 class="mb-0 text-warning">Rp <?= number_format($stats['overdue_30'],0) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-start border-danger border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">31–60 days overdue</h6>
                    <h5 class="mb-0 text-danger">Rp <?= number_format($stats['overdue_60'],0) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-start border-dark border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">&gt;60 days overdue</h6>
                    <h5 class="mb-0 text-dark">Rp <?= number_format($stats['overdue_60p'],0) ?></h5>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <select name="company_id" class="form-select form-select-sm">
                        <option value="">All Companies</option>
                        <?php foreach ($companies as $co): ?>
                            <option value="<?= $co['company_id'] ?>" <?= $company_id == $co['company_id'] ? 'selected' : '' ?>><?= htmlspecialchars($co['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="bucket" class="form-select form-select-sm">
                        <option value="">All Buckets</option>
                        <?php foreach (['Current','1-30 days','31-60 days','60+ days'] as $b): ?>
                            <option value="<?= $b ?>" <?= $bucket === $b ? 'selected' : '' ?>><?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="category" class="form-select form-select-sm">
                        <option value="">All Categories</option>
                        <?php foreach (['materials','fertilizer','labor_contractor','equipment','capex','tax','interest','other'] as $cat): ?>
                            <option value="<?= $cat ?>" <?= $category === $cat ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$cat)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Vendor or bill #…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary btn-sm me-1"><i class="bi bi-search"></i> Filter</button>
                    <a href="ap_bills.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bills table -->
    <div class="card">
        <div class="card-header"><i class="bi bi-table"></i> AP Bills (<?= count($bills) ?>)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Bill #</th>
                            <th>Vendor</th>
                            <th>Category</th>
                            <th>CF Code</th>
                            <th>Bill Date</th>
                            <th>Due Date</th>
                            <th>Planned Pay</th>
                            <th class="text-end">Amount IDR</th>
                            <th class="text-end">Outstanding</th>
                            <th>Priority</th>
                            <th>Overdue</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bills)): ?>
                            <tr><td colspan="12" class="text-center text-muted py-4">No outstanding AP bills found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($bills as $b): ?>
                                <?php
                                $overdue = (int)($b['due_date'] ? round((strtotime('today') - strtotime($b['due_date'])) / 86400) : 0);
                                $rowClass = $overdue > 60 ? 'table-danger' : ($overdue > 30 ? 'table-warning' : '');
                                $priColor = ['high'=>'danger','normal'=>'secondary','low'=>'light'][$b['payment_priority']] ?? 'secondary';
                                ?>
                                <tr class="<?= $rowClass ?>">
                                    <td><code><?= htmlspecialchars($b['bill_number']) ?></code></td>
                                    <td><?= htmlspecialchars($b['vendor_name']) ?><br>
                                        <small class="text-muted"><?= htmlspecialchars($b['vendor_ref'] ?? '') ?></small></td>
                                    <td><small><?= ucfirst(str_replace('_',' ',$b['bill_category'])) ?></small></td>
                                    <td><?= $b['cf_code'] ? "<code>{$b['cf_code']}</code>" : '<span class="text-muted">—</span>' ?></td>
                                    <td><?= format_date($b['bill_date']) ?></td>
                                    <td><?= format_date($b['due_date']) ?></td>
                                    <td><?= $b['planned_payment_date'] ? format_date($b['planned_payment_date']) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-end">Rp <?= number_format($b['amount_idr'],0) ?></td>
                                    <td class="text-end fw-bold">Rp <?= number_format($b['outstanding_amount'],0) ?></td>
                                    <td><span class="badge bg-<?= $priColor ?>"><?= ucfirst($b['payment_priority']) ?></span></td>
                                    <td>
                                        <?= $overdue > 0
                                            ? "<span class='badge bg-danger'>$overdue days</span>"
                                            : "<span class='badge bg-success'>In time</span>" ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary me-1"
                                                data-bs-toggle="modal" data-bs-target="#editBillModal"
                                                data-bill='<?= htmlspecialchars(json_encode($b), ENT_QUOTES) ?>'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Mark this bill as fully paid?')">
                                            <input type="hidden" name="action" value="mark_paid">
                                            <input type="hidden" name="bill_id" value="<?= $b['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-check-circle"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Bill Modal -->
<div class="modal fade" id="createBillModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_bill">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> New AP Bill</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Vendor Name <span class="text-danger">*</span></label>
                            <input type="text" name="vendor_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Vendor Invoice Ref</label>
                            <input type="text" name="vendor_ref" class="form-control" placeholder="Supplier's invoice number">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company <span class="text-danger">*</span></label>
                            <select name="company_id" class="form-select" required>
                                <option value="">— Select —</option>
                                <?php foreach ($companies as $co): ?>
                                    <option value="<?= $co['company_id'] ?>"><?= htmlspecialchars($co['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Bill Date <span class="text-danger">*</span></label>
                            <input type="date" name="bill_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Due Date <span class="text-danger">*</span></label>
                            <input type="date" name="due_date" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Planned Payment Date</label>
                            <input type="date" name="planned_payment_date" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Currency</label>
                            <select name="currency_code" class="form-select">
                                <?php foreach (['IDR','USD','EUR','MYR','SGD'] as $cur): ?>
                                    <option value="<?= $cur ?>"><?= $cur ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" name="amount_foreign" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Exchange Rate</label>
                            <input type="number" name="exchange_rate" class="form-control" step="0.000001" value="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select name="bill_category" class="form-select">
                                <?php foreach (['materials','fertilizer','labor_contractor','equipment','capex','tax','interest','other'] as $cat): ?>
                                    <option value="<?= $cat ?>"><?= ucfirst(str_replace('_',' ',$cat)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">CF Code (Cash Flow Line)</label>
                            <select name="cf_subcategory_id" class="form-select">
                                <option value="">— None —</option>
                                <?php foreach ($cfCats as $cf): ?>
                                    <option value="<?= $cf['id'] ?>"><?= htmlspecialchars($cf['code'] . ' – ' . $cf['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Payment Priority</label>
                            <select name="payment_priority" class="form-select">
                                <option value="normal" selected>Normal</option>
                                <option value="high">High</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create Bill</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Bill Modal -->
<div class="modal fade" id="editBillModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_bill">
                <input type="hidden" name="bill_id" id="eb_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit AP Bill</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Vendor Name</label>
                            <input type="text" name="vendor_name" id="eb_vendor_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Vendor Ref</label>
                            <input type="text" name="vendor_ref" id="eb_vendor_ref" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bill Date</label>
                            <input type="date" name="bill_date" id="eb_bill_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="due_date" id="eb_due_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Planned Payment</label>
                            <input type="date" name="planned_payment_date" id="eb_planned_date" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Currency</label>
                            <input type="text" name="currency_code" id="eb_currency" class="form-control" maxlength="3">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Amount</label>
                            <input type="number" name="amount_foreign" id="eb_amount" class="form-control" step="0.01">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Exchange Rate</label>
                            <input type="number" name="exchange_rate" id="eb_rate" class="form-control" step="0.000001">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Outstanding (IDR)</label>
                            <input type="number" name="outstanding_amount" id="eb_outstanding" class="form-control" step="0.01">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select name="bill_category" id="eb_category" class="form-select">
                                <?php foreach (['materials','fertilizer','labor_contractor','equipment','capex','tax','interest','other'] as $cat): ?>
                                    <option value="<?= $cat ?>"><?= ucfirst(str_replace('_',' ',$cat)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Priority</label>
                            <select name="payment_priority" id="eb_priority" class="form-select">
                                <option value="normal">Normal</option>
                                <option value="high">High</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" id="eb_status" class="form-select">
                                <option value="draft">Draft</option>
                                <option value="approved">Approved</option>
                                <option value="paid">Paid</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="eb_description" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<JS
<script>
document.getElementById('editBillModal').addEventListener('show.bs.modal', function(e) {
    var b = JSON.parse(e.relatedTarget.dataset.bill);
    document.getElementById('eb_id').value          = b.id;
    document.getElementById('eb_vendor_name').value = b.vendor_name;
    document.getElementById('eb_vendor_ref').value  = b.vendor_ref  || '';
    document.getElementById('eb_bill_date').value   = b.bill_date;
    document.getElementById('eb_due_date').value    = b.due_date;
    document.getElementById('eb_planned_date').value= b.planned_payment_date || '';
    document.getElementById('eb_currency').value    = b.currency_code;
    document.getElementById('eb_amount').value      = b.amount_foreign;
    document.getElementById('eb_rate').value        = b.exchange_rate;
    document.getElementById('eb_outstanding').value = b.outstanding_amount;
    document.getElementById('eb_description').value = b.description || '';
    ['eb_category','eb_priority','eb_status'].forEach(function(id) {
        var map = {eb_category:'bill_category', eb_priority:'payment_priority', eb_status:'status'};
        var sel = document.getElementById(id);
        for (var i=0;i<sel.options.length;i++) if (sel.options[i].value === b[map[id]]) { sel.selectedIndex=i; break; }
    });
});
</script>
JS;
require_once 'includes/footer.php';
?>
