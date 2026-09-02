<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_complaint_report');

// ─── Filters ──────────────────────────────────────────────────────────────────
$year           = get('year', date('Y'));
$company_filter = get('company_id', '');
$type_filter    = get('complaint_type', '');
$product_filter = get('product_type', '');

$companies = $db->query("SELECT company_id, company_name FROM companies ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);

// ─── Base WHERE ───────────────────────────────────────────────────────────────
$where  = ["YEAR(cmp.complaint_date) = ?"];
$params = [$year];
if ($company_filter) { $where[] = "cmp.company_id = ?";           $params[] = $company_filter; }
if ($type_filter)    { $where[] = "cmp.complaint_type = ?";       $params[] = $type_filter; }
if ($product_filter) { $where[] = "pd.product_type = ?";          $params[] = $product_filter; }
$w = implode(' AND ', $where);

// ─── All complaints (for table + computations) ────────────────────────────────
$stmt = $db->prepare("
    SELECT cmp.*,
           cu.customer_name, c.company_name,
           pd.delivery_number, pd.product_type,
           gr.receiving_number,
           DATEDIFF(COALESCE(cmp.resolved_at, CURDATE()), cmp.complaint_date) AS days_to_resolve
    FROM delivery_complaints cmp
    JOIN delivery_receivings gr ON cmp.receiving_id = gr.receiving_id
    JOIN product_deliveries  pd ON cmp.delivery_id  = pd.delivery_id
    JOIN customers           cu ON cmp.customer_id  = cu.customer_id
    JOIN companies            c ON cmp.company_id   = c.company_id
    WHERE $w
    ORDER BY cmp.complaint_date DESC, cmp.complaint_id DESC
");
$stmt->execute($params);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── KPI ─────────────────────────────────────────────────────────────────────
$total     = count($all);
$open_cnt  = count(array_filter($all, fn($r) => in_array($r['status'], ['open','under_review'])));
$res_cnt   = count(array_filter($all, fn($r) => $r['status'] === 'resolved'));
$rej_cnt   = count(array_filter($all, fn($r) => $r['status'] === 'rejected'));
$closed_cnt= count(array_filter($all, fn($r) => $r['status'] === 'closed'));

$total_claimed = array_sum(array_column($all, 'claimed_deduction'));
$total_agreed  = array_sum(array_column($all, 'agreed_deduction'));

// Avg resolution days (resolved/closed only)
$resolved_rows = array_filter($all, fn($r) => in_array($r['status'], ['resolved','closed']));
$avg_days = count($resolved_rows)
    ? round(array_sum(array_column($resolved_rows, 'days_to_resolve')) / count($resolved_rows), 1)
    : null;

$acceptance_rate = ($res_cnt + $closed_cnt + $rej_cnt) > 0
    ? round(($res_cnt + $closed_cnt) / ($res_cnt + $closed_cnt + $rej_cnt) * 100, 1)
    : null;

// ─── By complaint type ────────────────────────────────────────────────────────
$by_type = [];
foreach ($all as $r) {
    $t = $r['complaint_type'];
    if (!isset($by_type[$t])) $by_type[$t] = ['count'=>0,'claimed'=>0,'agreed'=>0];
    $by_type[$t]['count']++;
    $by_type[$t]['claimed'] += $r['claimed_deduction'];
    $by_type[$t]['agreed']  += $r['agreed_deduction'];
}
arsort($by_type);

// ─── By product ───────────────────────────────────────────────────────────────
$by_product = [];
foreach ($all as $r) {
    $p = $r['product_type'];
    if (!isset($by_product[$p])) $by_product[$p] = ['count'=>0,'claimed'=>0,'agreed'=>0];
    $by_product[$p]['count']++;
    $by_product[$p]['claimed'] += $r['claimed_deduction'];
    $by_product[$p]['agreed']  += $r['agreed_deduction'];
}

// ─── By customer ──────────────────────────────────────────────────────────────
$by_customer = [];
foreach ($all as $r) {
    $k = $r['customer_name'] . '|' . $r['company_name'];
    if (!isset($by_customer[$k])) $by_customer[$k] = ['customer_name'=>$r['customer_name'],'company_name'=>$r['company_name'],'count'=>0,'claimed'=>0,'agreed'=>0,'open'=>0];
    $by_customer[$k]['count']++;
    $by_customer[$k]['claimed'] += $r['claimed_deduction'];
    $by_customer[$k]['agreed']  += $r['agreed_deduction'];
    if (in_array($r['status'], ['open','under_review'])) $by_customer[$k]['open']++;
}
usort($by_customer, fn($a,$b) => $b['count'] <=> $a['count']);

// ─── Monthly trend ────────────────────────────────────────────────────────────
$monthly = array_fill(1, 12, ['count'=>0,'claimed'=>0,'agreed'=>0]);
foreach ($all as $r) {
    $m = (int)date('n', strtotime($r['complaint_date']));
    $monthly[$m]['count']++;
    $monthly[$m]['claimed'] += $r['claimed_deduction'];
    $monthly[$m]['agreed']  += $r['agreed_deduction'];
}
$max_count = max(1, max(array_column($monthly, 'count')));

$status_colours  = ['open'=>'danger','under_review'=>'warning','resolved'=>'success','rejected'=>'secondary','closed'=>'dark'];
$type_colours    = ['quantity'=>'info','quality'=>'warning','quantity_quality'=>'danger','packaging'=>'primary','other'=>'secondary'];
$product_colours = ['FFB'=>'success','CPO'=>'warning','Kernel'=>'info','PKO'=>'primary','Other'=>'secondary'];
$months_en       = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

require_once 'includes/header.php';
?>

<style>
    .rpt-blue   { color: #1d4ed8 !important; }
    .bg-rpt     { background-color: #1d4ed8 !important; }
    .btn-rpt    { background-color: #1d4ed8; color:#fff; border:none; }
    .btn-rpt:hover { background-color: #1e40af; color:#fff; }
    .bar-col { display:inline-block; vertical-align:bottom; border-radius:3px 3px 0 0; min-height:4px; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="rpt-blue"><i class="bi bi-bar-chart-line-fill"></i> Complaint Report</h1>
            <p class="text-muted mb-0">
                <a href="delivery_complaints.php" class="text-decoration-none">Complaints</a>
                &rsaquo; <b>Report &amp; Analytics</b>
                &rsaquo; <a href="delivery_monitoring.php" class="text-decoration-none">Monitoring</a>
            </p>
        </div>
        <div class="col-auto">
            <a href="delivery_complaints.php" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-exclamation-triangle"></i> Back to Complaints
            </a>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-rpt text-white py-2"><i class="bi bi-funnel"></i> Report Filters</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = date('Y')-2; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="company_id" class="form-select form-select-sm">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $co): ?>
                        <option value="<?= $co['company_id'] ?>" <?= $co['company_id'] == $company_filter ? 'selected' : '' ?>>
                            <?= htmlspecialchars($co['company_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="complaint_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach (array_keys($type_colours) as $t): ?>
                        <option value="<?= $t ?>" <?= $t === $type_filter ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$t)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="product_type" class="form-select form-select-sm">
                    <option value="">All Products</option>
                    <?php foreach (['FFB','CPO','Kernel','PKO','Other'] as $pt): ?>
                        <option value="<?= $pt ?>" <?= $pt === $product_filter ? 'selected' : '' ?>><?= $pt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-rpt btn-sm"><i class="bi bi-search"></i> Apply</button>
                <a href="complaint_report.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── KPI Cards ──────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100 text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Total</div>
                <div class="fw-bold fs-3 rpt-blue"><?= $total ?></div>
                <div class="small text-muted"><?= $year ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100 text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Open / In Review</div>
                <div class="fw-bold fs-3 text-danger"><?= $open_cnt ?></div>
                <div class="small text-muted"><?= $total > 0 ? round($open_cnt / $total * 100) : 0 ?>%</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100 text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Resolved / Closed</div>
                <div class="fw-bold fs-3 text-success"><?= $res_cnt + $closed_cnt ?></div>
                <div class="small text-muted"><?= $acceptance_rate !== null ? $acceptance_rate . '% accept rate' : '—' ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100 text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Avg. Resolution</div>
                <div class="fw-bold fs-3"><?= $avg_days !== null ? $avg_days . 'd' : '—' ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100 text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Total Claimed (Rp)</div>
                <div class="fw-bold text-danger" style="font-size:1rem"><?= number_format($total_claimed / 1000000, 1) ?>M</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100 text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Agreed Deduction (Rp)</div>
                <div class="fw-bold text-warning" style="font-size:1rem"><?= number_format($total_agreed / 1000000, 1) ?>M</div>
                <div class="small text-muted"><?= $total_claimed > 0 ? round($total_agreed / $total_claimed * 100, 1) . '% of claimed' : '—' ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Monthly Trend Chart ─────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header bg-rpt text-white py-2">
        <i class="bi bi-bar-chart"></i> Monthly Complaint Trend — <?= $year ?>
    </div>
    <div class="card-body">
        <div class="d-flex align-items-end gap-1" style="height:110px;">
            <?php for ($m = 1; $m <= 12; $m++):
                $cnt = $monthly[$m]['count'];
                $pct = $max_count > 0 ? round($cnt / $max_count * 100) : 0;
                $cl  = $monthly[$m]['claimed'];
            ?>
                <div class="flex-fill text-center" title="<?= $months_en[$m] ?>: <?= $cnt ?> complaint(s), Rp <?= number_format($cl / 1000000, 1) ?>M claimed">
                    <div style="height:<?= max($pct, 2) ?>%; min-height:4px;
                                background:<?= $m == (int)date('m') ? '#1d4ed8' : '#93c5fd' ?>;
                                border-radius:3px 3px 0 0; margin:0 1px;"
                         class="w-100"></div>
                </div>
            <?php endfor; ?>
        </div>
        <div class="d-flex gap-1 mt-1">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <div class="flex-fill text-center" style="font-size:10px;color:#6b7280"><?= $months_en[$m] ?></div>
            <?php endfor; ?>
        </div>
        <div class="d-flex gap-1">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <div class="flex-fill text-center" style="font-size:10px;font-weight:600;color:#374151">
                    <?= $monthly[$m]['count'] ?: '' ?>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- ── Breakdown row: By Type | By Product ────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-rpt text-white py-2"><i class="bi bi-pie-chart"></i> By Complaint Type</div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Type</th><th class="text-end">Count</th><th class="text-end">Claimed (Rp)</th><th class="text-end">Agreed (Rp)</th></tr></thead>
                    <tbody>
                        <?php if (empty($by_type)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No data</td></tr>
                        <?php else: ?>
                            <?php foreach ($by_type as $t => $d): ?>
                            <tr>
                                <td><span class="badge bg-<?= $type_colours[$t] ?? 'secondary' ?>"><?= ucwords(str_replace('_',' ',$t)) ?></span></td>
                                <td class="text-end fw-bold"><?= $d['count'] ?></td>
                                <td class="text-end text-danger"><?= number_format($d['claimed'] / 1000000, 2) ?>M</td>
                                <td class="text-end text-warning"><?= $d['agreed'] > 0 ? number_format($d['agreed'] / 1000000, 2) . 'M' : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-rpt text-white py-2"><i class="bi bi-box-seam"></i> By Product Type</div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Product</th><th class="text-end">Count</th><th class="text-end">Claimed (Rp)</th><th class="text-end">Agreed (Rp)</th></tr></thead>
                    <tbody>
                        <?php if (empty($by_product)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No data</td></tr>
                        <?php else: ?>
                            <?php foreach ($by_product as $p => $d): ?>
                            <tr>
                                <td><span class="badge bg-<?= $product_colours[$p] ?? 'secondary' ?>"><?= $p ?></span></td>
                                <td class="text-end fw-bold"><?= $d['count'] ?></td>
                                <td class="text-end text-danger"><?= number_format($d['claimed'] / 1000000, 2) ?>M</td>
                                <td class="text-end text-warning"><?= $d['agreed'] > 0 ? number_format($d['agreed'] / 1000000, 2) . 'M' : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── By Customer ────────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header bg-rpt text-white py-2"><i class="bi bi-people"></i> By Customer</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Customer</th>
                        <th>Company</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Open</th>
                        <th class="text-end">Claimed (Rp)</th>
                        <th class="text-end">Agreed (Rp)</th>
                        <th style="min-width:120px">Acceptance Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($by_customer)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No data.</td></tr>
                    <?php else: ?>
                        <?php foreach ($by_customer as $cu): ?>
                        <?php $rate = $cu['claimed'] > 0 ? round($cu['agreed'] / $cu['claimed'] * 100) : 0; ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($cu['customer_name']) ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($cu['company_name']) ?></small></td>
                            <td class="text-end"><?= $cu['count'] ?></td>
                            <td class="text-end <?= $cu['open'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= $cu['open'] ?: '—' ?></td>
                            <td class="text-end text-danger"><?= number_format($cu['claimed'] / 1000000, 2) ?>M</td>
                            <td class="text-end"><?= $cu['agreed'] > 0 ? number_format($cu['agreed'] / 1000000, 2) . 'M' : '—' ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <div class="progress flex-fill" style="height:8px;border-radius:4px">
                                        <div class="progress-bar bg-<?= $rate >= 80 ? 'success' : ($rate >= 40 ? 'warning' : 'danger') ?>"
                                             style="width:<?= $rate ?>%"></div>
                                    </div>
                                    <small style="min-width:34px"><?= $rate ?>%</small>
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

<!-- ── Full Complaint List ────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header bg-rpt text-white py-2 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table"></i> All Complaints — <?= count($all) ?> record(s)</span>
        <small class="opacity-75"><?= $year ?> · <?= $type_filter ? ucwords(str_replace('_',' ',$type_filter)) : 'All types' ?> · <?= $product_filter ?: 'All products' ?></small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>CMP #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th>Subject</th>
                        <th class="text-end">Claimed (Rp)</th>
                        <th class="text-end">Agreed (Rp)</th>
                        <th>Credit Note</th>
                        <th class="text-center">Days</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all)): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4">No complaints found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($all as $r): ?>
                        <tr>
                            <td class="fw-bold">
                                <a href="delivery_complaints.php?search=<?= urlencode($r['complaint_number']) ?>"
                                   class="text-decoration-none"><?= htmlspecialchars($r['complaint_number']) ?></a>
                            </td>
                            <td><?= date('d/m/Y', strtotime($r['complaint_date'])) ?></td>
                            <td><?= htmlspecialchars($r['customer_name']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($r['company_name']) ?></small></td>
                            <td><span class="badge bg-<?= $product_colours[$r['product_type']] ?? 'secondary' ?>"><?= $r['product_type'] ?></span></td>
                            <td><span class="badge bg-<?= $type_colours[$r['complaint_type']] ?? 'secondary' ?>"><?= ucwords(str_replace('_',' ',$r['complaint_type'])) ?></span></td>
                            <td><?= htmlspecialchars($r['subject']) ?></td>
                            <td class="text-end text-danger"><?= number_format($r['claimed_deduction'], 0) ?></td>
                            <td class="text-end <?= $r['agreed_deduction'] > 0 ? 'text-warning' : '' ?>">
                                <?= $r['agreed_deduction'] > 0 ? number_format($r['agreed_deduction'], 0) : '—' ?>
                            </td>
                            <td>
                                <?php if ($r['credit_note_number']): ?>
                                    <span class="badge bg-success"><?= htmlspecialchars($r['credit_note_number']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if (!in_array($r['status'], ['resolved','closed','rejected'])): ?>
                                    <span class="badge <?= (int)$r['days_to_resolve'] > 14 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                        <?= $r['days_to_resolve'] ?>d
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small"><?= $r['days_to_resolve'] ?>d</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-<?= $status_colours[$r['status']] ?? 'secondary' ?>">
                                <?= ucwords(str_replace('_',' ',$r['status'])) ?>
                            </span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
