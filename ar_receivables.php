<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Accounts Receivable";

// ─── Auto-migrate: ensure all required sales columns exist (cloud-safe) ───────
(function(PDO $db) {
    $existing = $db->query("SHOW COLUMNS FROM sales")
                   ->fetchAll(PDO::FETCH_COLUMN);

    // All columns the AR page depends on, with safe defaults
    $needed = [
        // Base sales columns that older cloud schemas may be missing
        'currency'                 => "VARCHAR(3)    NOT NULL DEFAULT 'IDR'",
        'delivery_location'        => "VARCHAR(255)  NULL",
        'delivery_date'            => "DATE          NULL",
        'reference_number'         => "VARCHAR(100)  NULL",
        'notes'                    => "TEXT          NULL",
        'updated_at'               => "TIMESTAMP     NULL ON UPDATE CURRENT_TIMESTAMP",
        'updated_by'               => "VARCHAR(50)   NULL",
        // AR-specific columns
        'due_date'                 => "DATE          NULL COMMENT 'Invoice due date'",
        'expected_collection_date' => "DATE          NULL COMMENT 'Expected collection date'",
        'outstanding_amount'       => "DECIMAL(15,2) NULL COMMENT 'Amount still outstanding'",
        'credit_risk'              => "ENUM('normal','watch','doubtful','bad') NOT NULL DEFAULT 'normal' COMMENT 'Credit risk'",
    ];

    $alters = [];
    foreach ($needed as $col => $def) {
        if (!in_array($col, $existing)) {
            $alters[] = "ADD COLUMN `$col` $def";
        }
    }
    if ($alters) {
        $db->exec("ALTER TABLE sales " . implode(', ', $alters));
    }

    // Back-fill outstanding_amount (only rows where it is still NULL)
    $db->exec("
        UPDATE sales
        SET outstanding_amount = CASE
                WHEN payment_status = 'paid'    THEN 0.00
                WHEN payment_status = 'partial' THEN ROUND(total_amount * 0.50, 2)
                ELSE total_amount
            END
        WHERE outstanding_amount IS NULL
    ");

    // Back-fill due_date (only rows where it is still NULL)
    $db->exec("
        UPDATE sales
        SET due_date = CASE
                WHEN payment_terms LIKE '%7 days%'  THEN DATE_ADD(sale_date, INTERVAL 7  DAY)
                WHEN payment_terms LIKE '%14 days%' THEN DATE_ADD(sale_date, INTERVAL 14 DAY)
                WHEN payment_terms LIKE '%30 days%' THEN DATE_ADD(sale_date, INTERVAL 30 DAY)
                WHEN payment_terms = 'Cash'         THEN sale_date
                ELSE DATE_ADD(sale_date, INTERVAL 30 DAY)
            END
        WHERE due_date IS NULL
    ");
})($db);

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // Update AR fields on a sales invoice
    if ($action === 'update_ar') {
        try {
            $outstanding = (float) post('outstanding_amount');
            $db->prepare("
                UPDATE sales SET
                    due_date                 = ?,
                    expected_collection_date = ?,
                    outstanding_amount       = ?,
                    credit_risk              = ?,
                    payment_status           = ?,
                    payment_date             = ?,
                    notes                    = ?,
                    updated_by               = 'admin'
                WHERE sale_id = ?
            ")->execute([
                post('due_date')                  ?: null,
                post('expected_collection_date')  ?: null,
                $outstanding,
                post('credit_risk')               ?: 'normal',
                post('payment_status')            ?: 'pending',
                post('payment_date')              ?: null,
                post('notes'),
                (int) post('sale_id'),
            ]);
            set_message('success', 'Receivable updated successfully!');
        } catch (PDOException $e) {
            set_message('error', 'Error updating receivable: ' . $e->getMessage());
        }
        redirect('ar_receivables.php?company_id=' . get('company_id_r', '') . '&bucket=' . get('bucket_r', ''));
    }
}

require_once 'includes/header.php';

// ─── Filters ──────────────────────────────────────────────────────────────────
$company_id = (int) get('company_id', 0);
$bucket     = get('bucket', '');            // Current | 1-30 days | 31-60 days | 61-90 days | 90+ days
$risk       = get('credit_risk', '');
$search     = get('search', '');

// ─── Summary stats ────────────────────────────────────────────────────────────
$statsWhere = $company_id ? "AND s.company_id = $company_id" : '';
$stats = $db->query("
    SELECT
        COUNT(*)                                                          AS total_invoices,
        COALESCE(SUM(s.outstanding_amount),0)                            AS total_outstanding,
        COALESCE(SUM(CASE WHEN s.due_date >= CURDATE() THEN s.outstanding_amount ELSE 0 END),0) AS current_amount,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(),s.due_date) BETWEEN 1 AND 30  THEN s.outstanding_amount ELSE 0 END),0) AS overdue_30,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(),s.due_date) BETWEEN 31 AND 60 THEN s.outstanding_amount ELSE 0 END),0) AS overdue_60,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(),s.due_date) BETWEEN 61 AND 90 THEN s.outstanding_amount ELSE 0 END),0) AS overdue_90,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(),s.due_date) > 90              THEN s.outstanding_amount ELSE 0 END),0) AS overdue_90p
    FROM sales s
    WHERE s.payment_status IN ('pending','partial')
      AND s.outstanding_amount > 0
      $statsWhere
")->fetch();

// ─── Companies for filter ──────────────────────────────────────────────────────
$companies = $db->query("SELECT company_id, company_name FROM companies ORDER BY company_name")->fetchAll();

// ─── Main invoice list ─────────────────────────────────────────────────────────
$where  = ["s.payment_status IN ('pending','partial')", "s.outstanding_amount > 0"];
$params = [];

if ($company_id) { $where[] = "s.company_id = ?"; $params[] = $company_id; }
if ($risk)       { $where[] = "s.credit_risk = ?"; $params[] = $risk; }
if ($search)     { $where[] = "(s.invoice_number LIKE ? OR c.customer_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($bucket === 'Current')     { $where[] = "s.due_date >= CURDATE()"; }
elseif ($bucket === '1-30 days')   { $where[] = "DATEDIFF(CURDATE(),s.due_date) BETWEEN 1 AND 30"; }
elseif ($bucket === '31-60 days')  { $where[] = "DATEDIFF(CURDATE(),s.due_date) BETWEEN 31 AND 60"; }
elseif ($bucket === '61-90 days')  { $where[] = "DATEDIFF(CURDATE(),s.due_date) BETWEEN 61 AND 90"; }
elseif ($bucket === '90+ days')    { $where[] = "DATEDIFF(CURDATE(),s.due_date) > 90"; }

$sql = "
    SELECT s.sale_id, s.invoice_number, s.sale_date, s.due_date,
           s.expected_collection_date, s.outstanding_amount,
           s.total_amount, s.payment_status, s.payment_terms,
           s.credit_risk, s.notes,
           c.customer_name, c.customer_type,
           co.company_name,
           DATEDIFF(CURDATE(), s.due_date) AS days_overdue
    FROM sales s
    JOIN customers c  ON s.customer_id = c.customer_id
    JOIN companies co ON s.company_id  = co.company_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.due_date ASC, s.outstanding_amount DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();
?>

<div class="content-wrapper">
    <!-- Page header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-receipt-cutoff"></i> Accounts Receivable</h1>
                <p class="text-muted mb-0">Outstanding sales invoices · collection schedule · ageing analysis</p>
            </div>
            <a href="cash_forecast.php" class="btn btn-outline-success">
                <i class="bi bi-graph-up-arrow"></i> Cash Forecast
            </a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card stat-card text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Total Outstanding</h6>
                    <h4 class="mb-0 text-primary">Rp <?= number_format($stats['total_outstanding'],0) ?></h4>
                    <small class="text-muted"><?= $stats['total_invoices'] ?> invoice(s)</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-success border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Current</h6>
                    <h5 class="mb-0 text-success">Rp <?= number_format($stats['current_amount'],0) ?></h5>
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
        <div class="col-md-2">
            <div class="card stat-card border-start border-orange border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">31–60 days overdue</h6>
                    <h5 class="mb-0" style="color:#f97316;">Rp <?= number_format($stats['overdue_60'],0) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-danger border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">61–90 days overdue</h6>
                    <h5 class="mb-0 text-danger">Rp <?= number_format($stats['overdue_90'],0) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-dark border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">&gt;90 days overdue</h6>
                    <h5 class="mb-0 text-dark">Rp <?= number_format($stats['overdue_90p'],0) ?></h5>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Company</label>
                    <select name="company_id" class="form-select form-select-sm">
                        <option value="">All Companies</option>
                        <?php foreach ($companies as $co): ?>
                            <option value="<?= $co['company_id'] ?>" <?= $company_id == $co['company_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($co['company_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Ageing Bucket</label>
                    <select name="bucket" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach (['Current','1-30 days','31-60 days','61-90 days','90+ days'] as $b): ?>
                            <option value="<?= $b ?>" <?= $bucket === $b ? 'selected' : '' ?>><?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Credit Risk</label>
                    <select name="credit_risk" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach (['normal','watch','doubtful','bad'] as $r): ?>
                            <option value="<?= $r ?>" <?= $risk === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Invoice # or customer…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm me-1"><i class="bi bi-search"></i> Filter</button>
                    <a href="ar_receivables.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Invoice table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table"></i> Outstanding Receivables (<?= count($invoices) ?>)</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Invoice Date</th>
                            <th>Due Date</th>
                            <th>Expected Collection</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-end">Outstanding</th>
                            <th>Status</th>
                            <th>Risk</th>
                            <th>Overdue</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invoices)): ?>
                            <tr><td colspan="11" class="text-center text-muted py-4">No outstanding receivables found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $inv): ?>
                                <?php
                                    $overdue = (int) $inv['days_overdue'];
                                    $rowClass = '';
                                    if ($overdue > 90)     $rowClass = 'table-danger';
                                    elseif ($overdue > 60) $rowClass = 'table-warning';
                                    elseif ($overdue > 30) $rowClass = 'table-secondary';
                                ?>
                                <tr class="<?= $rowClass ?>">
                                    <td><code><?= htmlspecialchars($inv['invoice_number']) ?></code></td>
                                    <td><?= htmlspecialchars($inv['customer_name']) ?><br>
                                        <small class="text-muted"><?= $inv['customer_type'] ?></small></td>
                                    <td><?= format_date($inv['sale_date']) ?></td>
                                    <td><?= $inv['due_date'] ? format_date($inv['due_date']) : '<span class="text-muted">—</span>' ?></td>
                                    <td><?= $inv['expected_collection_date'] ? format_date($inv['expected_collection_date']) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-end">Rp <?= number_format($inv['total_amount'],0) ?></td>
                                    <td class="text-end fw-bold">Rp <?= number_format($inv['outstanding_amount'],0) ?></td>
                                    <td>
                                        <?php
                                        $sBadge = ['pending'=>'warning','partial'=>'info','paid'=>'success'];
                                        $sc = $sBadge[$inv['payment_status']] ?? 'secondary';
                                        echo "<span class='badge bg-$sc'>" . ucfirst($inv['payment_status']) . "</span>";
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $rBadge = ['normal'=>'success','watch'=>'warning','doubtful'=>'danger','bad'=>'dark'];
                                        $rc = $rBadge[$inv['credit_risk']] ?? 'secondary';
                                        echo "<span class='badge bg-$rc'>" . ucfirst($inv['credit_risk']) . "</span>";
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($overdue > 0): ?>
                                            <span class="badge bg-danger"><?= $overdue ?> days</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">In time</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal" data-bs-target="#editArModal"
                                                data-sale_id="<?= $inv['sale_id'] ?>"
                                                data-invoice_number="<?= htmlspecialchars($inv['invoice_number']) ?>"
                                                data-customer_name="<?= htmlspecialchars($inv['customer_name']) ?>"
                                                data-due_date="<?= $inv['due_date'] ?>"
                                                data-expected_collection_date="<?= $inv['expected_collection_date'] ?>"
                                                data-outstanding_amount="<?= $inv['outstanding_amount'] ?>"
                                                data-payment_status="<?= $inv['payment_status'] ?>"
                                                data-payment_date="<?= $inv['payment_date'] ?? '' ?>"
                                                data-credit_risk="<?= $inv['credit_risk'] ?>"
                                                data-notes="<?= htmlspecialchars($inv['notes'] ?? '') ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
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

<!-- Edit AR Modal -->
<div class="modal fade" id="editArModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_ar">
                <input type="hidden" name="sale_id" id="modal_sale_id">
                <input type="hidden" name="company_id_r" value="<?= $company_id ?>">
                <input type="hidden" name="bucket_r" value="<?= htmlspecialchars($bucket) ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Update Receivable</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3" id="modal_invoice_label"></p>
                    <div class="mb-3">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="due_date" id="modal_due_date" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expected Collection Date</label>
                        <input type="date" name="expected_collection_date" id="modal_expected" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Outstanding Amount (IDR)</label>
                        <input type="number" name="outstanding_amount" id="modal_outstanding" class="form-control" step="0.01" min="0">
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Payment Status</label>
                            <select name="payment_status" id="modal_payment_status" class="form-select">
                                <option value="pending">Pending</option>
                                <option value="partial">Partial</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Credit Risk</label>
                            <select name="credit_risk" id="modal_credit_risk" class="form-select">
                                <option value="normal">Normal</option>
                                <option value="watch">Watch</option>
                                <option value="doubtful">Doubtful</option>
                                <option value="bad">Bad</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Payment Date</label>
                        <input type="date" name="payment_date" id="modal_payment_date" class="form-control">
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="modal_notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<JS
<script>
document.getElementById('editArModal').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('modal_sale_id').value          = btn.dataset.sale_id;
    document.getElementById('modal_invoice_label').textContent = btn.dataset.invoice_number + ' — ' + btn.dataset.customer_name;
    document.getElementById('modal_due_date').value         = btn.dataset.due_date        || '';
    document.getElementById('modal_expected').value         = btn.dataset.expected_collection_date || '';
    document.getElementById('modal_outstanding').value      = btn.dataset.outstanding_amount;
    document.getElementById('modal_payment_date').value     = btn.dataset.payment_date   || '';
    document.getElementById('modal_notes').value            = btn.dataset.notes           || '';
    var ps = document.getElementById('modal_payment_status');
    for (var i=0;i<ps.options.length;i++) if (ps.options[i].value===btn.dataset.payment_status) ps.selectedIndex=i;
    var cr = document.getElementById('modal_credit_risk');
    for (var i=0;i<cr.options.length;i++) if (cr.options[i].value===btn.dataset.credit_risk) cr.selectedIndex=i;
});
</script>
JS;
require_once 'includes/footer.php';
?>
