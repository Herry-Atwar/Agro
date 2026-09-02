<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db         = getDB();
$page_title = __('pt_grn');

// ─── Filters ──────────────────────────────────────────────────────────────────
if (get('year') !== '') {
    $year = (int)get('year');
} else {
    try {
        $year = (int)$db->query("SELECT COALESCE(MAX(YEAR(grn_date)), YEAR(CURDATE())) FROM grn_headers")->fetchColumn();
    } catch (PDOException $e) { $year = (int)date('Y'); }
}
$month           = (int)(get('month')     ?: 0);
$status_filter   = get('status') ?: '';
$division_filter = (int)(get('division_id') ?: 0);

// ─── Reference data ───────────────────────────────────────────────────────────
try {
    $divisions = $db->query("SELECT division_id, division_name FROM divisions WHERE status='active' ORDER BY division_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $divisions = []; }

// ─── Fetch GRNs ───────────────────────────────────────────────────────────────
$grn_fetch_error = null;
try {
    $sql = "
        SELECT grn_id, grn_number, grn_date, po_id, po_number, supplier_name,
               pr_number, division_id, division_name, received_by, supplier_do_no,
               journal_entry_id, journal_ref, journal_status,
               status, item_count, total_value, notes, created_at
        FROM vw_grn_summary
        WHERE YEAR(grn_date) = ?
    ";
    $params = [$year];
    if ($month > 0)          { $sql .= " AND MONTH(grn_date) = ?";  $params[] = $month; }
    if ($division_filter > 0){ $sql .= " AND division_id = ?";      $params[] = $division_filter; }
    if ($status_filter !== ''){ $sql .= " AND status = ?";           $params[] = $status_filter; }
    $sql .= " ORDER BY grn_date DESC, grn_id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $grns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $grns = [];
    $grn_fetch_error = $e->getMessage();
}

// ─── KPIs ─────────────────────────────────────────────────────────────────────
$total_grns   = count($grns);
$total_value  = array_sum(array_column($grns, 'total_value'));
$with_journal = count(array_filter($grns, fn($r) => !empty($r['journal_entry_id'])));
$cancelled    = count(array_filter($grns, fn($r) => $r['status'] === 'cancelled'));

require_once 'includes/header.php';
?>

<style>
    .grn-teal  { color: #0d9488 !important; }
    .bg-grn    { background-color: #0d9488 !important; }
    .btn-grn   { background-color: #0d9488; color:#fff; border:none; }
    .btn-grn:hover { background-color: #0f766e; color:#fff; }
    .detail-row td { background: #f8f9fa; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="grn-teal"><i class="bi bi-box-seam-fill"></i> Goods Receipt Notes</h1>
            <p class="text-muted mb-0">
                <a href="purchase_requisitions.php" class="text-decoration-none">Purchase Requisitions</a>
                &rsaquo; <a href="purchase_orders.php" class="text-decoration-none">Purchase Orders</a>
                &rsaquo; <span class="text-body">GRN</span>
                &rsaquo; <a href="journal_entries.php" class="text-decoration-none">Journal Entries</a>
            </p>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- ── Filters ──────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-grn text-white py-2"><i class="bi bi-funnel"></i> Filter</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = min((int)date('Y')-2, $year-1); $y <= max((int)date('Y')+1, $year); $y++): ?>
                        <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <option value="">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-1">Division</label>
                <select name="division_id" class="form-select form-select-sm">
                    <option value="">All Divisions</option>
                    <?php foreach ($divisions as $d): ?>
                        <option value="<?= $d['division_id'] ?>" <?= $d['division_id']==$division_filter?'selected':'' ?>>
                            <?= htmlspecialchars($d['division_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="confirmed"  <?= $status_filter==='confirmed'?'selected':''  ?>>Confirmed</option>
                    <option value="cancelled"  <?= $status_filter==='cancelled'?'selected':''  ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-grn btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="grn.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── KPI cards ────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Total GRNs</div>
                <div class="fw-bold fs-4"><?= $total_grns ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">With Journal Entry</div>
                <div class="fw-bold fs-4 text-success"><?= $with_journal ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Cancelled</div>
                <div class="fw-bold fs-4 text-danger"><?= $cancelled ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Total Received Value</div>
                <div class="fw-bold fs-5">Rp <?= number_format($total_value, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ── GRN table ─────────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header bg-grn text-white py-2">
        <i class="bi bi-table"></i> <?= $total_grns ?> GRN(s) — <?= $year ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:32px;"></th>
                        <th>GRN Number</th>
                        <th>Date</th>
                        <th>PO Number</th>
                        <th>Supplier</th>
                        <th>Division</th>
                        <th class="text-center">Items</th>
                        <th class="text-end">Total Value (Rp)</th>
                        <th>Journal</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($grn_fetch_error): ?>
                    <tr><td colspan="11" class="text-center text-danger py-4">
                        <i class="bi bi-exclamation-triangle"></i>
                        Database error: <?= htmlspecialchars($grn_fetch_error) ?>
                    </td></tr>
                <?php elseif (empty($grns)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No GRNs found for the selected filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($grns as $g): ?>
                    <tr>
                        <td>
                            <button class="btn btn-link btn-sm p-0 text-muted toggle-detail"
                                    data-grn="<?= $g['grn_id'] ?>" title="Show items">
                                <i class="bi bi-chevron-right" id="icon-<?= $g['grn_id'] ?>"></i>
                            </button>
                        </td>
                        <td class="fw-bold"><?= htmlspecialchars($g['grn_number']) ?></td>
                        <td><?= date('d/m/Y', strtotime($g['grn_date'])) ?></td>
                        <td>
                            <a href="purchase_orders.php" class="text-decoration-none small">
                                <?= htmlspecialchars($g['po_number']) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($g['supplier_name']) ?></td>
                        <td><span class="small"><?= htmlspecialchars($g['division_name']) ?></span></td>
                        <td class="text-center"><?= (int)$g['item_count'] ?></td>
                        <td class="text-end fw-semibold"><?= number_format((float)$g['total_value'], 0, ',', '.') ?></td>
                        <td>
                            <?php if ($g['journal_ref']): ?>
                                <span class="badge bg-success" title="Journal posted">
                                    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($g['journal_ref']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">No JE</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $g['status']==='confirmed' ? 'success' : 'danger' ?>">
                                <?= ucfirst($g['status']) ?>
                            </span>
                        </td>
                        <td>
                            <a href="grn_print.php?grn_id=<?= $g['grn_id'] ?>"
                               target="_blank"
                               class="btn btn-outline-secondary btn-sm py-0 px-2"
                               title="Print GRN">
                                <i class="bi bi-printer"></i> Print
                            </a>
                            <?php if ($g['journal_entry_id']): ?>
                            <a href="journal_entry_detail.php?id=<?= $g['journal_entry_id'] ?>"
                               class="btn btn-outline-success btn-sm py-0 px-2"
                               title="View Journal Entry">
                                <i class="bi bi-journal-text"></i> JE
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Expandable detail row -->
                    <tr class="detail-row d-none" id="detail-<?= $g['grn_id'] ?>">
                        <td colspan="11" class="p-0">
                            <div class="px-4 py-2" id="detail-content-<?= $g['grn_id'] ?>">
                                <span class="text-muted small"><i class="bi bi-hourglass-split"></i> Loading…</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const loadedDetails = {};
document.querySelectorAll('.toggle-detail').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const grnId  = this.dataset.grn;
        const row    = document.getElementById('detail-' + grnId);
        const icon   = document.getElementById('icon-' + grnId);
        const content= document.getElementById('detail-content-' + grnId);

        if (!row.classList.contains('d-none')) {
            row.classList.add('d-none');
            icon.className = 'bi bi-chevron-right';
            return;
        }
        row.classList.remove('d-none');
        icon.className = 'bi bi-chevron-down';

        if (loadedDetails[grnId]) return;
        fetch('ajax/grn_items.php?grn_id=' + grnId)
            .then(r => r.text())
            .then(html => { content.innerHTML = html; loadedDetails[grnId] = true; })
            .catch(() => { content.innerHTML = '<span class="text-danger small">Failed to load items.</span>'; });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
