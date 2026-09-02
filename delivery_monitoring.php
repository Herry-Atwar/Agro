<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_delivery_monitoring');

// ─── Filters ──────────────────────────────────────────────────────────────────
$year           = get('year', date('Y'));
$month          = get('month', '');
$company_filter = get('company_id', '');
$product_filter = get('product_type', '');

// ─── Reference data ───────────────────────────────────────────────────────────
$companies = $db->query("SELECT * FROM companies ORDER BY company_code")->fetchAll(PDO::FETCH_ASSOC);

// ─── KPI: Contracts ──────────────────────────────────────────────────────────
$kpi_contracts = $db->prepare("
    SELECT
        COUNT(*)                                         AS total,
        SUM(CASE WHEN status='active'              THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status='partially_delivered' THEN 1 ELSE 0 END) AS partial,
        SUM(CASE WHEN status='fully_delivered'     THEN 1 ELSE 0 END) AS full,
        SUM(quantity_mt)                                 AS total_contracted_mt,
        SUM(total_contract_value)                        AS total_contract_value
    FROM sales_contracts
    WHERE YEAR(contract_date) = ?
    " . ($company_filter ? " AND company_id = ?" : "")
);
$kpi_params = $company_filter ? [$year, $company_filter] : [$year];
$kpi_contracts->execute($kpi_params);
$kpi_c = $kpi_contracts->fetch(PDO::FETCH_ASSOC);

// ─── KPI: Deliveries ─────────────────────────────────────────────────────────
$kpi_del = $db->prepare("
    SELECT
        COUNT(*)                                          AS total,
        SUM(CASE WHEN status='draft'     THEN 1 ELSE 0 END) AS draft_cnt,
        SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) AS delivered_cnt,
        SUM(CASE WHEN status='invoiced'  THEN 1 ELSE 0 END) AS invoiced_cnt,
        SUM(pd.net_weight_kg) / 1000                      AS total_net_mt
    FROM product_deliveries pd
    WHERE YEAR(pd.delivery_date) = ?
    " . ($company_filter ? " AND pd.company_id = ?" : "")
);
$kpi_del->execute($company_filter ? [$year, $company_filter] : [$year]);
$kpi_d = $kpi_del->fetch(PDO::FETCH_ASSOC);

// ─── KPI: Receivings & Complaints ────────────────────────────────────────────
try {
    $kpi_rec = $db->prepare("
        SELECT
            COUNT(*)                                                       AS total_rec,
            SUM(CASE WHEN gr.quality_status != 'accepted' THEN 1 ELSE 0 END) AS quality_issues,
            SUM(CASE WHEN gr.quantity_status != 'accepted' THEN 1 ELSE 0 END) AS qty_issues,
            SUM(CASE WHEN gr.status = 'disputed'           THEN 1 ELSE 0 END) AS disputed
        FROM delivery_receivings gr
        WHERE YEAR(gr.receiving_date) = ?
        " . ($company_filter ? " AND gr.company_id = ?" : "")
    );
    $kpi_rec->execute($company_filter ? [$year, $company_filter] : [$year]);
    $kpi_r = $kpi_rec->fetch(PDO::FETCH_ASSOC);

    $kpi_cmp = $db->prepare("
        SELECT
            COUNT(*)                                                     AS total,
            SUM(CASE WHEN status='open'         THEN 1 ELSE 0 END)      AS open_cnt,
            SUM(CASE WHEN status='under_review' THEN 1 ELSE 0 END)      AS review_cnt,
            SUM(claimed_deduction)                                       AS total_claimed,
            SUM(agreed_deduction)                                        AS total_agreed
        FROM delivery_complaints
        WHERE YEAR(complaint_date) = ?
        " . ($company_filter ? " AND company_id = ?" : "")
    );
    $kpi_cmp->execute($company_filter ? [$year, $company_filter] : [$year]);
    $kpi_cm = $kpi_cmp->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $kpi_r  = ['total_rec'=>0,'quality_issues'=>0,'qty_issues'=>0,'disputed'=>0];
    $kpi_cm = ['total'=>0,'open_cnt'=>0,'review_cnt'=>0,'total_claimed'=>0,'total_agreed'=>0];
}

// ─── Contract Fulfilment Table ────────────────────────────────────────────────
$sql_contracts = "
    SELECT
        sc.contract_id, sc.contract_number, sc.contract_date,
        cu.customer_name, c.company_name,
        sc.product_type,
        sc.quantity_mt                                         AS contracted_mt,
        sc.unit_price, sc.total_contract_value,
        sc.delivery_start_date, sc.delivery_end_date,
        sc.status,
        COUNT(DISTINCT dcl.delivery_id)                        AS delivery_count,
        COALESCE(SUM(dcl.quantity_kg),0) / 1000               AS delivered_mt,
        sc.quantity_mt - COALESCE(SUM(dcl.quantity_kg),0)/1000 AS remaining_mt,
        ROUND(
            COALESCE(SUM(dcl.quantity_kg),0)/1000
            / NULLIF(sc.quantity_mt, 0) * 100
        , 1)                                                   AS fulfilment_pct,
        COALESCE(SUM(dcl.line_amount), 0)                      AS invoiced_amount
    FROM sales_contracts sc
    JOIN customers cu ON sc.customer_id = cu.customer_id
    JOIN companies  c ON sc.company_id  = c.company_id
    LEFT JOIN delivery_contract_lines dcl ON dcl.contract_id = sc.contract_id
    LEFT JOIN product_deliveries pd
           ON pd.delivery_id = dcl.delivery_id
          AND pd.status NOT IN ('draft','cancelled')
    WHERE YEAR(sc.contract_date) = ?
";
$p2 = [$year];
if ($company_filter) { $sql_contracts .= " AND sc.company_id = ?"; $p2[] = $company_filter; }
if ($product_filter) { $sql_contracts .= " AND sc.product_type = ?"; $p2[] = $product_filter; }
if ($month)          { $sql_contracts .= " AND MONTH(sc.contract_date) = ?"; $p2[] = (int)$month; }
$sql_contracts .= " GROUP BY sc.contract_id ORDER BY sc.contract_date DESC, sc.contract_id DESC";
$stmt = $db->prepare($sql_contracts); $stmt->execute($p2);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Recent Deliveries ────────────────────────────────────────────────────────
$recent_del = $db->prepare("
    SELECT pd.delivery_number, pd.delivery_date, pd.product_type,
           pd.net_weight_kg/1000  AS net_mt,
           cu.customer_name,      c.company_name,
           pd.vehicle_number,     pd.status,
           COUNT(dcl.line_id)     AS contract_count,
           COALESCE(SUM(dcl.line_amount),0) AS total_amt
    FROM product_deliveries pd
    JOIN customers cu ON pd.customer_id = cu.customer_id
    JOIN companies  c ON pd.company_id  = c.company_id
    LEFT JOIN delivery_contract_lines dcl ON dcl.delivery_id = pd.delivery_id
    WHERE YEAR(pd.delivery_date) = ?
    " . ($company_filter ? " AND pd.company_id = ?" : "") . "
    GROUP BY pd.delivery_id
    ORDER BY pd.delivery_date DESC, pd.delivery_id DESC
    LIMIT 20
");
$recent_del->execute($company_filter ? [$year, $company_filter] : [$year]);
$recent = $recent_del->fetchAll(PDO::FETCH_ASSOC);

// ─── Monthly Summary ──────────────────────────────────────────────────────────
$monthly = $db->prepare("
    SELECT
        MONTH(pd.delivery_date)             AS mon,
        SUM(pd.net_weight_kg)/1000          AS net_mt,
        COUNT(DISTINCT pd.delivery_id)      AS del_count,
        COALESCE(SUM(dcl.line_amount), 0)   AS amount
    FROM product_deliveries pd
    LEFT JOIN delivery_contract_lines dcl ON dcl.delivery_id = pd.delivery_id
    WHERE YEAR(pd.delivery_date) = ?
      AND pd.status NOT IN ('draft','cancelled')
    " . ($company_filter ? " AND pd.company_id = ?" : "") . "
    GROUP BY MONTH(pd.delivery_date)
    ORDER BY mon
");
$monthly->execute($company_filter ? [$year, $company_filter] : [$year]);
$monthly_rows = $monthly->fetchAll(PDO::FETCH_ASSOC);
// index by month number
$monthly_map = [];
foreach ($monthly_rows as $mr) { $monthly_map[(int)$mr['mon']] = $mr; }

$status_colours  = ['draft'=>'secondary','confirmed'=>'primary','delivered'=>'info','invoiced'=>'success','cancelled'=>'danger'];
$product_colours = ['FFB'=>'success','CPO'=>'warning','Kernel'=>'info','PKO'=>'primary','Other'=>'secondary'];
$contract_status_colours = [
    'draft'=>'secondary','active'=>'primary',
    'partially_delivered'=>'warning','fully_delivered'=>'success','cancelled'=>'danger'
];
$months_en = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

require_once 'includes/header.php';
?>

<style>
    .mon-green  { color: #059669 !important; }
    .bg-mon     { background-color: #059669 !important; }
    .btn-mon    { background-color: #059669; color:#fff; border:none; }
    .btn-mon:hover { background-color: #047857; color:#fff; }
    .progress-bar-striped.bg-success { animation: progress-bar-stripes 1s linear infinite; }
    .fulfilment-bar { height: 8px; border-radius: 4px; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="mon-green"><i class="bi bi-graph-up-arrow"></i> Delivery Monitoring</h1>
            <p class="text-muted mb-0">
                <a href="sales_contracts.php"   class="text-decoration-none">Sales Contracts</a>
                &rsaquo; <a href="product_deliveries.php" class="text-decoration-none">Product Deliveries</a>
                &rsaquo; <b>Monitoring</b>
            </p>
        </div>
        <div class="col-auto">
            <a href="product_deliveries.php" class="btn btn-mon btn-sm">
                <i class="bi bi-box-seam"></i> New Delivery
            </a>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- ── Filters ──────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-mon text-white py-2"><i class="bi bi-funnel"></i> Monitoring Filters</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = date('Y')-2; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y==$year?'selected':''?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="month" class="form-select form-select-sm">
                    <option value="">All Months</option>
                    <?php for ($m=1; $m<=12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m==$month?'selected':''?>><?= $months_en[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="company_id" class="form-select form-select-sm">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= $c['company_id'] ?>" <?= $c['company_id']==$company_filter?'selected':''?>>
                            <?= htmlspecialchars($c['company_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="product_type" class="form-select form-select-sm">
                    <option value="">All Products</option>
                    <?php foreach (['FFB','CPO','Kernel','PKO','Other'] as $pt): ?>
                        <option value="<?= $pt ?>" <?= $pt===$product_filter?'selected':''?>><?= $pt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-mon btn-sm"><i class="bi bi-search"></i> Apply</button>
                <a href="delivery_monitoring.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── KPI Cards ──────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <div class="card stat-card text-center h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Total Contracts</div>
                <div class="fw-bold fs-3 mon-green"><?= number_format($kpi_c['total']) ?></div>
                <div class="small text-muted"><?= $year ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card text-center h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Contracted (MT)</div>
                <div class="fw-bold fs-4"><?= number_format($kpi_c['total_contracted_mt'],1) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card text-center h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Total Deliveries</div>
                <div class="fw-bold fs-3 text-primary"><?= number_format($kpi_d['total']) ?></div>
                <div class="small text-muted"><?= number_format($kpi_d['invoiced_cnt']) ?> invoiced</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card text-center h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Delivered (MT)</div>
                <div class="fw-bold fs-4 text-success"><?= number_format($kpi_d['total_net_mt'],1) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card text-center h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Active Contracts</div>
                <div class="fw-bold fs-3 text-warning"><?= number_format($kpi_c['active']) ?></div>
                <div class="small text-muted"><?= number_format($kpi_c['partial']) ?> partial</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card text-center h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Contract Value</div>
                <div class="fw-bold fs-5 mon-green">Rp <?= number_format($kpi_c['total_contract_value']/1000000000,1) ?>B</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Receiving & Complaints KPI row ──────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card text-center h-100 border-warning">
            <div class="card-body py-3">
                <div class="text-muted small">Receivings Recorded</div>
                <div class="fw-bold fs-3"><?= number_format($kpi_r['total_rec']) ?></div>
                <div class="small text-muted">
                    <a href="delivery_receiving.php?year=<?= $year ?>" class="text-decoration-none">View →</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card text-center h-100 border-danger">
            <div class="card-body py-3">
                <div class="text-muted small">Receivings with Issues</div>
                <div class="fw-bold fs-3 text-danger">
                    <?= number_format(max($kpi_r['quality_issues'], $kpi_r['qty_issues'])) ?>
                </div>
                <div class="small text-muted"><?= $kpi_r['qty_issues'] ?> qty &nbsp;|&nbsp; <?= $kpi_r['quality_issues'] ?> quality</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card text-center h-100 border-danger">
            <div class="card-body py-3">
                <div class="text-muted small">Open Complaints</div>
                <div class="fw-bold fs-3 text-danger"><?= number_format($kpi_cm['open_cnt'] + $kpi_cm['review_cnt']) ?></div>
                <div class="small">
                    <a href="delivery_complaints.php?year=<?= $year ?>" class="text-decoration-none">View all →</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card text-center h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Claimed Deduction</div>
                <div class="fw-bold text-danger" style="font-size:1.1rem">
                    Rp <?= number_format($kpi_cm['total_claimed']/1000000,1) ?>M
                </div>
                <div class="small text-muted">Agreed: Rp <?= number_format($kpi_cm['total_agreed']/1000000,1) ?>M</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Monthly Bar Chart (CSS only) ─────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header bg-mon text-white py-2">
        <i class="bi bi-bar-chart-line"></i> Monthly Delivery Volume (MT) — <?= $year ?>
    </div>
    <div class="card-body">
        <?php
        $max_mt = 0;
        for ($m=1;$m<=12;$m++) { $max_mt = max($max_mt, (float)($monthly_map[$m]['net_mt']??0)); }
        ?>
        <div class="d-flex align-items-end gap-1" style="height:120px;">
            <?php for ($m=1; $m<=12; $m++): ?>
                <?php
                $mt  = (float)($monthly_map[$m]['net_mt'] ?? 0);
                $pct = $max_mt > 0 ? round($mt / $max_mt * 100) : 0;
                $cnt = (int)($monthly_map[$m]['del_count'] ?? 0);
                ?>
                <div class="flex-fill text-center" title="<?= $months_en[$m] ?>: <?= number_format($mt,1) ?> MT (<?= $cnt ?> deliveries)">
                    <div style="height:<?= max($pct,2) ?>%; min-height:4px;
                                background:<?= $m==(int)date('m')?'#059669':'#6ee7b7'?>;
                                border-radius:3px 3px 0 0;
                                margin:0 1px;"
                         class="w-100"></div>
                </div>
            <?php endfor; ?>
        </div>
        <div class="d-flex gap-1 mt-1">
            <?php for ($m=1;$m<=12;$m++): ?>
                <div class="flex-fill text-center" style="font-size:10px;color:#6b7280"><?= $months_en[$m] ?></div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- ── Contract Fulfilment Table ──────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header bg-mon text-white py-2 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clipboard2-check"></i> Contract Fulfilment — <?= count($contracts) ?> Contracts</span>
        <small class="opacity-75">Delivery allocations via product_deliveries</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Contract</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th class="text-end">Contracted (MT)</th>
                        <th class="text-end">Delivered (MT)</th>
                        <th class="text-end">Remaining (MT)</th>
                        <th style="min-width:120px">Fulfilment</th>
                        <th class="text-center">Deliveries</th>
                        <th class="text-end">Invoiced (Rp)</th>
                        <th>Delivery Window</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($contracts)): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4">No contracts found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($contracts as $r): ?>
                            <?php
                            $pct     = min(100, (float)($r['fulfilment_pct'] ?? 0));
                            $bar_cls = $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-warning' : 'bg-danger');
                            ?>
                            <tr>
                                <td>
                                    <a href="sales_contracts.php" class="fw-bold text-decoration-none">
                                        <?= htmlspecialchars($r['contract_number']) ?>
                                    </a><br>
                                    <small class="text-muted"><?= date('d/m/Y', strtotime($r['contract_date'])) ?></small>
                                </td>
                                <td><?= htmlspecialchars($r['customer_name']) ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($r['company_name']) ?></small></td>
                                <td><span class="badge bg-<?= $product_colours[$r['product_type']] ?? 'secondary' ?>"><?= $r['product_type'] ?></span></td>
                                <td class="text-end fw-bold"><?= number_format($r['contracted_mt'], 1) ?></td>
                                <td class="text-end text-success"><?= number_format($r['delivered_mt'], 1) ?></td>
                                <td class="text-end <?= $r['remaining_mt'] > 0 ? 'text-warning' : 'text-success' ?>">
                                    <?= number_format($r['remaining_mt'], 1) ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-1">
                                        <div class="progress flex-fill fulfilment-bar">
                                            <div class="progress-bar <?= $bar_cls ?>"
                                                 style="width:<?= $pct ?>%"></div>
                                        </div>
                                        <small style="min-width:36px"><?= $pct ?>%</small>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($r['delivery_count'] > 0): ?>
                                        <a href="product_deliveries.php" class="badge bg-primary text-decoration-none">
                                            <?= $r['delivery_count'] ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?= $r['invoiced_amount'] > 0 ? 'Rp '.number_format($r['invoiced_amount'],0) : '—' ?>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= date('d/m', strtotime($r['delivery_start_date'])) ?>
                                        – <?= date('d/m/Y', strtotime($r['delivery_end_date'])) ?>
                                    </small>
                                    <?php if (strtotime($r['delivery_end_date']) < time() && $r['status'] !== 'fully_delivered' && $r['status'] !== 'cancelled'): ?>
                                        <span class="badge bg-danger ms-1">Overdue</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $contract_status_colours[$r['status']] ?? 'secondary' ?>">
                                        <?= ucwords(str_replace('_', ' ', $r['status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Recent Deliveries ──────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header bg-mon text-white py-2">
        <i class="bi bi-clock-history"></i> Recent Deliveries (last 20)
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
                        <th class="text-end">Net (MT)</th>
                        <th class="text-center">Contracts</th>
                        <th class="text-end">Inv Amount (Rp)</th>
                        <th>Vehicle</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">No deliveries yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td class="fw-bold">
                                    <a href="product_deliveries.php" class="text-decoration-none">
                                        <?= htmlspecialchars($r['delivery_number']) ?>
                                    </a>
                                </td>
                                <td><?= date('d/m/Y', strtotime($r['delivery_date'])) ?></td>
                                <td><?= htmlspecialchars($r['customer_name']) ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($r['company_name']) ?></small></td>
                                <td><span class="badge bg-<?= $product_colours[$r['product_type']] ?? 'secondary' ?>"><?= $r['product_type'] ?></span></td>
                                <td class="text-end"><?= number_format($r['net_mt'], 3) ?></td>
                                <td class="text-center">
                                    <?php if ((int)$r['contract_count'] > 1): ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="bi bi-diagram-2"></i> <?= $r['contract_count'] ?> contracts
                                        </span>
                                    <?php elseif ((int)$r['contract_count'] == 1): ?>
                                        <span class="badge bg-light text-dark border">1</span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?= $r['total_amt'] > 0 ? number_format($r['total_amt'], 0) : '—' ?>
                                </td>
                                <td><small><?= htmlspecialchars($r['vehicle_number'] ?? '—') ?></small></td>
                                <td>
                                    <span class="badge bg-<?= $status_colours[$r['status']] ?? 'secondary' ?>">
                                        <?= ucfirst($r['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
