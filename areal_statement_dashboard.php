<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Areal Statement Dashboard";

// ── Filters ──────────────────────────────────────────────────────────────────
$filter_bu        = get('business_unit_id', '');
$filter_div       = get('division_id', '');
$filter_year      = get('planting_year', '');
$filter_status    = get('status', '');
$filter_ownership = get('ownership_type', '');

// ── Reference lists for filters ──────────────────────────────────────────────
$business_units = $db->query("
    SELECT bu.business_unit_id, bu.unit_code, bu.unit_name, c.company_name
    FROM business_units bu
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE bu.business_unit_id IN (
        SELECT DISTINCT d.business_unit_id FROM divisions d
        INNER JOIN blocks b ON b.division_id = d.division_id
    )
    ORDER BY c.company_name, bu.unit_code
")->fetchAll();

$all_divisions = $db->query("
    SELECT d.division_id, d.division_code, d.division_name, bu.unit_code
    FROM divisions d
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    WHERE d.division_id IN (SELECT DISTINCT division_id FROM blocks)
    ORDER BY bu.unit_code, d.division_code
")->fetchAll();

$all_years = $db->query("
    SELECT DISTINCT py.year
    FROM planting_years py
    INNER JOIN blocks b ON b.planting_year_id = py.planting_year_id
    ORDER BY py.year DESC
")->fetchAll(PDO::FETCH_COLUMN);

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where_parts = ['1=1'];
$params      = [];

if ($filter_bu) {
    $where_parts[] = 'bu.business_unit_id = ?';
    $params[] = $filter_bu;
}
if ($filter_div) {
    $where_parts[] = 'd.division_id = ?';
    $params[] = $filter_div;
}
if ($filter_year) {
    $where_parts[] = 'py.year = ?';
    $params[] = (int)$filter_year;
}
if ($filter_status) {
    $where_parts[] = 'b.status = ?';
    $params[] = $filter_status;
}
if ($filter_ownership) {
    $where_parts[] = 'b.ownership_type = ?';
    $params[] = $filter_ownership;
}

$where = implode(' AND ', $where_parts);

// ── Master join (LEFT JOIN planting_years so NULL year blocks are included) ──
$from = "
    FROM blocks b
    LEFT  JOIN planting_years py  ON b.planting_year_id  = py.planting_year_id
    INNER JOIN divisions d        ON b.division_id        = d.division_id
    INNER JOIN business_units bu  ON d.business_unit_id   = bu.business_unit_id
    INNER JOIN companies c        ON bu.company_id        = c.company_id
";

// ── KPI Totals ────────────────────────────────────────────────────────────────
$kpi_stmt = $db->prepare("
    SELECT
        COUNT(DISTINCT b.block_id)                                                       AS total_blocks,
        COUNT(DISTINCT d.division_id)                                                    AS total_divisions,
        COUNT(DISTINCT bu.business_unit_id)                                              AS total_bu,
        COALESCE(SUM(b.area), 0)                                                         AS total_area,
        COALESCE(SUM(b.planted_area), 0)                                                 AS planted_area,
        COALESCE(SUM(b.area - COALESCE(b.planted_area,0)), 0)                            AS non_planted_area,
        COALESCE(SUM(b.total_plants), 0)                                                 AS total_trees,
        COALESCE(AVG(NULLIF(b.plant_density,0)), 0)                                      AS avg_density,
        COALESCE(AVG(NULLIF(b.plant_age,0)), 0)                                          AS avg_age,
        COALESCE(SUM(CASE WHEN b.status='TM'  THEN b.planted_area ELSE 0 END), 0)        AS mature_area,
        COALESCE(SUM(CASE WHEN b.status='TBM' THEN b.planted_area ELSE 0 END), 0)        AS immature_area,
        COALESCE(SUM(CASE WHEN b.status='TR'  THEN b.planted_area ELSE 0 END), 0)        AS replanting_area,
        COALESCE(SUM(NULLIF(b.volume_m3,0)), 0)                                          AS total_volume,
        COALESCE(SUM(NULLIF(b.carbon_stock_ton,0)), 0)                                   AS total_carbon,
        COALESCE(AVG(NULLIF(b.stand_density,0)), 0)                                      AS avg_stand_density,
        COALESCE(AVG(NULLIF(b.average_dbh,0)), 0)                                        AS avg_dbh,
        COALESCE(SUM(vas.road_area), 0)                                                  AS road_area,
        COALESCE(SUM(vas.building_area), 0)                                              AS building_area,
        COALESCE(SUM(vas.water_area + vas.swamp_area), 0)                                AS water_area,
        COALESCE(SUM(vas.conservation_area), 0)                                          AS conservation_area,
        COALESCE(SUM(vas.other_area), 0)                                                 AS other_area
    $from
    LEFT JOIN v_block_areal_statement vas ON b.block_id = vas.block_id
    WHERE $where
");
$kpi_stmt->execute($params);
$kpi = $kpi_stmt->fetch();

// ── By Business Unit ──────────────────────────────────────────────────────────
$by_bu_stmt = $db->prepare("
    SELECT
        bu.unit_code, bu.unit_name,
        COUNT(DISTINCT b.block_id)                                                      AS blocks,
        COALESCE(SUM(b.area), 0)                                                        AS total_area,
        COALESCE(SUM(b.planted_area), 0)                                                AS planted_area,
        COALESCE(SUM(b.total_plants), 0)                                                AS trees,
        COALESCE(SUM(CASE WHEN b.status='TM'  THEN b.planted_area ELSE 0 END), 0)       AS tm_area,
        COALESCE(SUM(CASE WHEN b.status='TBM' THEN b.planted_area ELSE 0 END), 0)       AS tbm_area,
        COALESCE(SUM(CASE WHEN b.status='TR'  THEN b.planted_area ELSE 0 END), 0)       AS tr_area,
        COALESCE(AVG(NULLIF(b.plant_density,0)), 0)                                     AS avg_density
    $from
    WHERE $where
    GROUP BY bu.business_unit_id, bu.unit_code, bu.unit_name
    ORDER BY total_area DESC
");
$by_bu_stmt->execute($params);
$by_bu = $by_bu_stmt->fetchAll();

// ── By Division ───────────────────────────────────────────────────────────────
$by_div_stmt = $db->prepare("
    SELECT
        bu.unit_code                                                                     AS bu_code,
        d.division_code, d.division_name,
        COUNT(DISTINCT b.block_id)                                                      AS blocks,
        COALESCE(SUM(b.area), 0)                                                        AS total_area,
        COALESCE(SUM(b.planted_area), 0)                                                AS planted_area,
        COALESCE(SUM(b.total_plants), 0)                                                AS trees,
        COALESCE(SUM(CASE WHEN b.status='TM'  THEN b.planted_area ELSE 0 END), 0)       AS tm_area,
        COALESCE(SUM(CASE WHEN b.status='TBM' THEN b.planted_area ELSE 0 END), 0)       AS tbm_area,
        COALESCE(SUM(CASE WHEN b.status='TR'  THEN b.planted_area ELSE 0 END), 0)       AS tr_area,
        COALESCE(AVG(NULLIF(b.plant_density,0)), 0)                                     AS avg_density
    $from
    WHERE $where
    GROUP BY d.division_id, d.division_code, d.division_name, bu.unit_code
    ORDER BY bu.unit_code, total_area DESC
");
$by_div_stmt->execute($params);
$by_div = $by_div_stmt->fetchAll();

// ── By Planting Year ──────────────────────────────────────────────────────────
$by_year_stmt = $db->prepare("
    SELECT
        COALESCE(CAST(py.year AS CHAR), 'Not Set')                                      AS year,
        COUNT(DISTINCT b.block_id)                                                      AS blocks,
        COALESCE(SUM(b.area), 0)                                                        AS total_area,
        COALESCE(SUM(b.planted_area), 0)                                                AS planted_area,
        COALESCE(SUM(b.total_plants), 0)                                                AS trees,
        COALESCE(SUM(CASE WHEN b.status='TM'  THEN b.planted_area ELSE 0 END), 0)       AS tm_area,
        COALESCE(SUM(CASE WHEN b.status='TBM' THEN b.planted_area ELSE 0 END), 0)       AS tbm_area,
        COALESCE(SUM(CASE WHEN b.status='TR'  THEN b.planted_area ELSE 0 END), 0)       AS tr_area,
        COALESCE(AVG(NULLIF(b.plant_density,0)), 0)                                     AS avg_density,
        COALESCE(AVG(NULLIF(b.plant_age,0)), 0)                                         AS avg_age
    $from
    WHERE $where
    GROUP BY py.year
    ORDER BY py.year ASC
");
$by_year_stmt->execute($params);
$by_year = $by_year_stmt->fetchAll();

// ── By Status ─────────────────────────────────────────────────────────────────
$by_status_stmt = $db->prepare("
    SELECT
        b.status,
        COUNT(DISTINCT b.block_id)                                                      AS blocks,
        COALESCE(SUM(b.area), 0)                                                        AS total_area,
        COALESCE(SUM(b.planted_area), 0)                                                AS planted_area,
        COALESCE(SUM(b.total_plants), 0)                                                AS trees,
        COALESCE(AVG(NULLIF(b.plant_density,0)), 0)                                     AS avg_density,
        COALESCE(AVG(NULLIF(b.plant_age,0)), 0)                                         AS avg_age
    $from
    WHERE $where
    GROUP BY b.status
    ORDER BY planted_area DESC
");
$by_status_stmt->execute($params);
$by_status = $by_status_stmt->fetchAll();

// ── By Ownership Type ─────────────────────────────────────────────────────────
$by_ownership_stmt = $db->prepare("
    SELECT
        COALESCE(b.ownership_type, 'inti')                                              AS ownership_type,
        COUNT(DISTINCT b.block_id)                                                      AS blocks,
        COUNT(DISTINCT d.division_id)                                                   AS divisions,
        COALESCE(SUM(b.area), 0)                                                        AS total_area,
        COALESCE(SUM(b.planted_area), 0)                                                AS planted_area,
        COALESCE(SUM(b.total_plants), 0)                                                AS trees,
        COALESCE(SUM(CASE WHEN b.status='TM'  THEN b.planted_area ELSE 0 END), 0)       AS tm_area,
        COALESCE(SUM(CASE WHEN b.status='TBM' THEN b.planted_area ELSE 0 END), 0)       AS tbm_area,
        COALESCE(SUM(CASE WHEN b.status='TR'  THEN b.planted_area ELSE 0 END), 0)       AS tr_area,
        COALESCE(AVG(NULLIF(b.plant_density,0)), 0)                                     AS avg_density
    $from
    WHERE $where
    GROUP BY b.ownership_type
    ORDER BY total_area DESC
");
$by_ownership_stmt->execute($params);
$by_ownership = $by_ownership_stmt->fetchAll();

// ── By Plant Variety ──────────────────────────────────────────────────────────
$by_variety_stmt = $db->prepare("
    SELECT
        COALESCE(pv.variety_name, 'Unspecified')                                        AS variety_name,
        COALESCE(pv.variety_code, '-')                                                  AS variety_code,
        COALESCE(pv.category, '-')                                                      AS category,
        COUNT(DISTINCT b.block_id)                                                      AS blocks,
        COALESCE(SUM(b.area), 0)                                                        AS total_area,
        COALESCE(SUM(b.planted_area), 0)                                                AS planted_area,
        COALESCE(SUM(bpv.plant_count), 0)                                               AS trees
    $from
    LEFT JOIN block_plant_varieties bpv ON b.block_id = bpv.block_id
    LEFT JOIN plant_varieties pv        ON bpv.variety_id = pv.variety_id
    WHERE $where
    GROUP BY pv.variety_id, pv.variety_name, pv.variety_code, pv.category
    ORDER BY planted_area DESC
");
$by_variety_stmt->execute($params);
$by_variety = $by_variety_stmt->fetchAll();

// ── Area component breakdown (from view) ─────────────────────────────────────
$area_comp_stmt = $db->prepare("
    SELECT
        COALESCE(SUM(vas.planted_area), 0)       AS comp_planted,
        COALESCE(SUM(vas.road_area), 0)          AS comp_road,
        COALESCE(SUM(vas.building_area), 0)      AS comp_building,
        COALESCE(SUM(vas.water_area), 0)         AS comp_water,
        COALESCE(SUM(vas.swamp_area), 0)         AS comp_swamp,
        COALESCE(SUM(vas.conservation_area), 0)  AS comp_conservation,
        COALESCE(SUM(vas.other_area), 0)         AS comp_other,
        COALESCE(SUM(vas.total_non_planted_area),0) AS comp_non_planted
    $from
    LEFT JOIN v_block_areal_statement vas ON b.block_id = vas.block_id
    WHERE $where
");
$area_comp_stmt->execute($params);
$area_comp = $area_comp_stmt->fetch();

// ── Block detail table ────────────────────────────────────────────────────────
$block_stmt = $db->prepare("
    SELECT
        c.company_name,
        bu.unit_code, bu.unit_name,
        d.division_code, d.division_name,
        COALESCE(CAST(py.year AS CHAR), 'N/A')                                          AS plant_year,
        b.block_code, b.block_name,
        b.area,
        COALESCE(b.planted_area, 0)                                                     AS planted_area,
        COALESCE(b.total_plants, 0)                                                     AS total_plants,
        b.plant_density, b.plant_age,
        b.status, b.harvest_status,
        b.topography, b.soil_type,
        COALESCE(b.ownership_type, 'inti')                                              AS ownership_type,
        b.volume_m3, b.carbon_stock_ton,
        b.stand_density, b.average_dbh,
        COALESCE(vas.planted_area, 0)                                                   AS comp_planted_area,
        COALESCE(vas.total_non_planted_area, 0)                                         AS comp_non_planted_area,
        COALESCE(vas.road_area, 0)                                                      AS road_area,
        COALESCE(vas.conservation_area, 0)                                              AS conservation_area
    $from
    LEFT JOIN v_block_areal_statement vas ON b.block_id = vas.block_id
    WHERE $where
    ORDER BY bu.unit_code, d.division_code, b.block_code
    LIMIT 500
");
$block_stmt->execute($params);
$blocks_data = $block_stmt->fetchAll();

// ── Chart data ────────────────────────────────────────────────────────────────
// By division (horizontal bar)
$chart_div_labels  = array_map(fn($r) => $r['division_code'], $by_div);
$chart_div_area    = array_map(fn($r) => (float)$r['total_area'],   $by_div);
$chart_div_planted = array_map(fn($r) => (float)$r['planted_area'], $by_div);

// By status (doughnut)
$status_labels = array_column($by_status, 'status');
$status_values = array_map(fn($r) => (float)$r['planted_area'], $by_status);
$status_colors = array_map(fn($s) =>
    ['TM'=>'#2e7d32','TBM'=>'#f57f17','TR'=>'#c62828','Replanting'=>'#1565c0'][$s] ?? '#888',
    $status_labels
);

// Area composition (doughnut: planted vs road vs water vs conservation vs other)
$comp_total = (float)$kpi['total_area'] ?: 1;

// Computed helpers
$pct_planted = $kpi['total_area'] > 0 ? round($kpi['planted_area'] / $kpi['total_area'] * 100, 1) : 0;
$pct_mature  = $kpi['planted_area'] > 0 ? round($kpi['mature_area']  / $kpi['planted_area'] * 100, 1) : 0;
$density_eff = $kpi['planted_area'] > 0 ? round($kpi['total_trees'] / $kpi['planted_area']) : 0;

require_once 'includes/header.php';
?>

<style>
/* ── Executive header ────────────────────────────────────── */
.exec-hdr          { background:linear-gradient(135deg,#1b5e20 0%,#388e3c 100%); color:#fff; border-radius:8px; padding:20px 28px; margin-bottom:22px; }
.exec-hdr h1       { font-size:1.55rem; font-weight:700; margin:0 0 3px; }
.exec-hdr p        { margin:0; opacity:.85; font-size:.88rem; }
.exec-hdr .meta    { font-size:.78rem; opacity:.7; margin-top:6px; }

/* ── KPI cards ───────────────────────────────────────────── */
.kpi-card          { border:none; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.07); height:100%; }
.kpi-card .card-body { padding:16px 18px; }
.kpi-value         { font-size:1.75rem; font-weight:700; line-height:1.1; }
.kpi-label         { font-size:.72rem; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; margin-top:3px; }
.kpi-sub           { font-size:.77rem; color:#868e96; margin-top:2px; }
.kpi-icon          { font-size:2.2rem; opacity:.12; position:absolute; right:14px; top:50%; transform:translateY(-50%); }

/* ── Section headers ─────────────────────────────────────── */
.sec-title         { font-size:.92rem; font-weight:700; color:#1b5e20; border-left:4px solid #43a047; padding-left:10px; margin:22px 0 12px; }

/* ── Tables ──────────────────────────────────────────────── */
.tbl-exec          { font-size:.8rem; }
.tbl-exec th       { background:#f1f8e9; color:#33691e; font-weight:600; white-space:nowrap; padding:7px 10px; }
.tbl-exec td       { vertical-align:middle; padding:5px 10px; }
.tbl-exec tfoot td { background:#e8f5e9; font-weight:700; }

/* ── Progress bars ───────────────────────────────────────── */
.prog-thin         { height:5px; border-radius:3px; background:#e9ecef; overflow:hidden; }
.prog-thin .bar    { height:5px; border-radius:3px; background:#2e7d32; }

/* ── Status badges ───────────────────────────────────────── */
.bs-tm   { background:#2e7d32; color:#fff; }
.bs-tbm  { background:#f57f17; color:#fff; }
.bs-tr   { background:#c62828; color:#fff; }
.bs-rep  { background:#1565c0; color:#fff; }
.bs-oth  { background:#607d8b; color:#fff; }

/* ── Chart containers ────────────────────────────────────── */
.chart-wrap        { position:relative; }

/* ── Ownership cards ─────────────────────────────────────── */
.ow-card-inti      { border-left:4px solid #1565c0; }
.ow-card-plasma    { border-left:4px solid #6a1b9a; }

/* ── Filter bar ──────────────────────────────────────────── */
.filter-bar        { background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.07); padding:12px 16px; margin-bottom:18px; }

/* ── Print ───────────────────────────────────────────────── */
@media print {
    .no-print      { display:none !important; }
    .exec-hdr      { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .tbl-exec th   { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>

<div class="container-fluid px-3 py-3">

    <!-- ── Executive Header ──────────────────────────────────────────────────── -->
    <div class="exec-hdr d-flex justify-content-between align-items-start">
        <div>
            <h1><i class="bi bi-map-fill me-2"></i>Areal Statement Dashboard</h1>
            <p>Executive summary — land area, stand status, tree population &amp; density by block, division &amp; business unit</p>
            <div class="meta">
                <i class="bi bi-clock me-1"></i> Generated: <?= date('d M Y, H:i') ?>
                <?php if ($filter_bu || $filter_div || $filter_status || $filter_ownership || $filter_year): ?>
                &nbsp;&bull;&nbsp;<i class="bi bi-funnel-fill me-1"></i> Filtered view active
                <?php endif; ?>
            </div>
        </div>
        <div class="no-print d-flex gap-2 mt-1 flex-wrap justify-content-end">
            <button onclick="window.print()" class="btn btn-light btn-sm">
                <i class="bi bi-printer"></i> Print
            </button>
            <a href="areal_statement_dynamic.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-table"></i> Detail Report
            </a>
        </div>
    </div>

    <!-- ── Filters ───────────────────────────────────────────────────────────── -->
    <div class="filter-bar no-print">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label mb-1 small fw-semibold">Business Unit</label>
                <select name="business_unit_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Business Units</option>
                    <?php foreach ($business_units as $bu): ?>
                    <option value="<?= $bu['business_unit_id'] ?>" <?= $bu['business_unit_id'] == $filter_bu ? 'selected' : '' ?>>
                        <?= htmlspecialchars($bu['unit_code'] . ' – ' . $bu['unit_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-3">
                <label class="form-label mb-1 small fw-semibold">Division</label>
                <select name="division_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Divisions</option>
                    <?php foreach ($all_divisions as $dv): ?>
                    <option value="<?= $dv['division_id'] ?>" <?= $dv['division_id'] == $filter_div ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dv['unit_code'] . ' / ' . $dv['division_code'] . ' – ' . $dv['division_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-md-2 col-xl-1">
                <label class="form-label mb-1 small fw-semibold">Plant Year</label>
                <select name="planting_year" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Years</option>
                    <?php foreach ($all_years as $yr): ?>
                    <option value="<?= $yr ?>" <?= $yr == $filter_year ? 'selected' : '' ?>><?= $yr ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-md-2 col-xl-2">
                <label class="form-label mb-1 small fw-semibold">Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <?php foreach (['TM', 'TBM', 'TR', 'Replanting'] as $st): ?>
                    <option value="<?= $st ?>" <?= $st == $filter_status ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-md-2 col-xl-2">
                <label class="form-label mb-1 small fw-semibold">Ownership</label>
                <select name="ownership_type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="inti"   <?= $filter_ownership == 'inti'   ? 'selected' : '' ?>>Inti (Core)</option>
                    <option value="plasma" <?= $filter_ownership == 'plasma' ? 'selected' : '' ?>>Plasma</option>
                </select>
            </div>
            <div class="col-12 col-md-auto col-xl-auto d-flex gap-1">
                <button type="submit" class="btn btn-success btn-sm px-3"><i class="bi bi-funnel"></i> Apply</button>
                <a href="areal_statement_dashboard.php" class="btn btn-outline-secondary btn-sm px-3"><i class="bi bi-x-circle"></i> Reset</a>
            </div>
        </form>
    </div>

    <!-- ── KPI Row 1: Area ────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-2">
        <!-- Total Area -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden">
                <div class="card-body">
                    <div class="kpi-value text-success"><?= number_format($kpi['total_area'], 2) ?></div>
                    <div class="kpi-label">Total Area (ha)</div>
                    <div class="kpi-sub"><?= $kpi['total_blocks'] ?> blocks &bull; <?= $kpi['total_bu'] ?> BU &bull; <?= $kpi['total_divisions'] ?> Div</div>
                    <i class="bi bi-map kpi-icon text-success"></i>
                </div>
            </div>
        </div>
        <!-- Planted Area -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden">
                <div class="card-body">
                    <div class="kpi-value text-primary"><?= number_format($kpi['planted_area'], 2) ?></div>
                    <div class="kpi-label">Planted Area (ha)</div>
                    <div class="kpi-sub"><?= $pct_planted ?>% of total area</div>
                    <div class="prog-thin mt-2"><div class="bar bg-primary" style="width:<?= $pct_planted ?>%"></div></div>
                    <i class="bi bi-flower1 kpi-icon text-primary"></i>
                </div>
            </div>
        </div>
        <!-- Total Trees -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden">
                <div class="card-body">
                    <div class="kpi-value text-info"><?= number_format($kpi['total_trees']) ?></div>
                    <div class="kpi-label">Total Trees / Stands</div>
                    <div class="kpi-sub">Eff. density <?= number_format($density_eff) ?> /ha</div>
                    <i class="bi bi-tree kpi-icon text-info"></i>
                </div>
            </div>
        </div>
        <!-- Mature (TM) -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden" style="border-top:3px solid #2e7d32;">
                <div class="card-body">
                    <div class="kpi-value" style="color:#2e7d32"><?= number_format($kpi['mature_area'], 2) ?></div>
                    <div class="kpi-label">Mature / TM (ha)</div>
                    <div class="kpi-sub"><?= $pct_mature ?>% of planted</div>
                    <div class="prog-thin mt-2"><div class="bar" style="width:<?= $pct_mature ?>%;background:#2e7d32;"></div></div>
                    <i class="bi bi-check-circle kpi-icon" style="color:#2e7d32"></i>
                </div>
            </div>
        </div>
        <!-- Immature (TBM) -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden" style="border-top:3px solid #f57f17;">
                <div class="card-body">
                    <div class="kpi-value text-warning"><?= number_format($kpi['immature_area'], 2) ?></div>
                    <div class="kpi-label">Immature / TBM (ha)</div>
                    <div class="kpi-sub">
                        <?= $kpi['planted_area'] > 0 ? round($kpi['immature_area'] / $kpi['planted_area'] * 100, 1) : 0 ?>% of planted
                    </div>
                    <i class="bi bi-hourglass-split kpi-icon text-warning"></i>
                </div>
            </div>
        </div>
        <!-- Replanting / TR -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden" style="border-top:3px solid #c62828;">
                <div class="card-body">
                    <div class="kpi-value text-danger"><?= number_format($kpi['replanting_area'], 2) ?></div>
                    <div class="kpi-label">Replanting / TR (ha)</div>
                    <div class="kpi-sub">Avg age <?= round($kpi['avg_age'], 1) ?> yrs</div>
                    <i class="bi bi-arrow-repeat kpi-icon text-danger"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- ── KPI Row 2: Forestry / Technical ───────────────────────────────────── -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden">
                <div class="card-body">
                    <div class="kpi-value" style="color:#37474f"><?= $kpi['avg_density'] > 0 ? round($kpi['avg_density']) : '–' ?></div>
                    <div class="kpi-label">Avg Plant Density (t/ha)</div>
                    <div class="kpi-sub">Block-level average</div>
                    <i class="bi bi-grid-3x3-gap kpi-icon" style="color:#607d8b"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden">
                <div class="card-body">
                    <div class="kpi-value" style="color:#37474f"><?= $kpi['avg_stand_density'] > 0 ? round($kpi['avg_stand_density']) : '–' ?></div>
                    <div class="kpi-label">Avg Stand Density</div>
                    <div class="kpi-sub">Trees per hectare</div>
                    <i class="bi bi-bar-chart kpi-icon" style="color:#607d8b"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden">
                <div class="card-body">
                    <div class="kpi-value" style="color:#37474f"><?= $kpi['avg_dbh'] > 0 ? number_format($kpi['avg_dbh'], 1) : '–' ?></div>
                    <div class="kpi-label">Avg DBH (cm)</div>
                    <div class="kpi-sub">Diameter at breast height</div>
                    <i class="bi bi-circle kpi-icon" style="color:#607d8b"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden">
                <div class="card-body">
                    <div class="kpi-value" style="color:#1a237e"><?= $kpi['total_volume'] > 0 ? number_format($kpi['total_volume'], 0) : '–' ?></div>
                    <div class="kpi-label">Total Volume (m³)</div>
                    <div class="kpi-sub">Standing timber volume</div>
                    <i class="bi bi-box kpi-icon" style="color:#3949ab"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden">
                <div class="card-body">
                    <div class="kpi-value" style="color:#004d40"><?= $kpi['total_carbon'] > 0 ? number_format($kpi['total_carbon'], 0) : '–' ?></div>
                    <div class="kpi-label">Carbon Stock (ton)</div>
                    <div class="kpi-sub">Estimated sequestration</div>
                    <i class="bi bi-cloud kpi-icon" style="color:#00695c"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <div class="card kpi-card position-relative overflow-hidden">
                <div class="card-body">
                    <div class="kpi-value text-secondary"><?= number_format($kpi['total_area'] - $kpi['planted_area'], 2) ?></div>
                    <div class="kpi-label">Non-Planted (ha)</div>
                    <div class="kpi-sub"><?= $kpi['total_area'] > 0 ? round(($kpi['total_area'] - $kpi['planted_area']) / $kpi['total_area'] * 100, 1) : 0 ?>% of total</div>
                    <i class="bi bi-slash-circle kpi-icon text-secondary"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Charts Row ─────────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-3 no-print">
        <!-- Horizontal bar: area by division -->
        <div class="col-lg-7">
            <div class="card kpi-card">
                <div class="card-body">
                    <div class="sec-title mb-3" style="margin-top:0">Area by Division (ha)</div>
                    <div class="chart-wrap" style="height:<?= max(180, count($by_div) * 28) ?>px;">
                        <canvas id="chartDivision"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <!-- Doughnut: status split -->
        <div class="col-lg-5">
            <div class="card kpi-card">
                <div class="card-body">
                    <div class="sec-title mb-3" style="margin-top:0">Planted Area by Status</div>
                    <div class="chart-wrap" style="height:200px;">
                        <canvas id="chartStatus"></canvas>
                    </div>
                    <!-- Legend -->
                    <div class="d-flex flex-wrap gap-2 justify-content-center mt-3" style="font-size:.77rem;">
                        <?php foreach ($by_status as $row):
                            $cls = ['TM'=>'#2e7d32','TBM'=>'#f57f17','TR'=>'#c62828','Replanting'=>'#1565c0'][$row['status']] ?? '#888';
                            $pct_s = $kpi['planted_area'] > 0 ? round($row['planted_area']/$kpi['planted_area']*100,1) : 0;
                        ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;">
                            <span style="width:10px;height:10px;border-radius:50%;background:<?= $cls ?>;display:inline-block;"></span>
                            <strong><?= htmlspecialchars($row['status']) ?></strong>
                            <?= number_format($row['planted_area'],2) ?> ha (<?= $pct_s ?>%)
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Area Composition (from block area components) ─────────────────────── -->
    <?php
    $comp_has_data = ($area_comp['comp_planted'] + $area_comp['comp_road'] + $area_comp['comp_building']
                    + $area_comp['comp_water'] + $area_comp['comp_swamp']
                    + $area_comp['comp_conservation'] + $area_comp['comp_other']) > 0;
    if ($comp_has_data):
    ?>
    <div class="row g-3 mb-3 no-print">
        <div class="col-12">
            <div class="card kpi-card">
                <div class="card-body">
                    <div class="sec-title mb-3" style="margin-top:0">Land Use Composition (Area Components)</div>
                    <div class="row g-0">
                        <?php
                        $comps = [
                            ['label'=>'Planted',      'val'=>(float)$area_comp['comp_planted'],      'color'=>'#2e7d32'],
                            ['label'=>'Roads',         'val'=>(float)$area_comp['comp_road'],         'color'=>'#78909c'],
                            ['label'=>'Buildings',     'val'=>(float)$area_comp['comp_building'],     'color'=>'#8d6e63'],
                            ['label'=>'Water/River',   'val'=>(float)$area_comp['comp_water'] + (float)$area_comp['comp_swamp'], 'color'=>'#1976d2'],
                            ['label'=>'Conservation',  'val'=>(float)$area_comp['comp_conservation'], 'color'=>'#388e3c'],
                            ['label'=>'Other',         'val'=>(float)$area_comp['comp_other'],        'color'=>'#9e9e9e'],
                        ];
                        $comp_sum = array_sum(array_column($comps,'val')) ?: 1;
                        foreach ($comps as $co):
                            if ($co['val'] <= 0) continue;
                            $pct_c = round($co['val'] / $comp_sum * 100, 1);
                        ?>
                        <div class="col-6 col-md-4 col-lg-2 text-center py-2 px-1">
                            <div style="font-size:1.2rem;font-weight:700;color:<?= $co['color'] ?>">
                                <?= number_format($co['val'], 2) ?>
                            </div>
                            <div style="font-size:.7rem;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;"><?= $co['label'] ?></div>
                            <div class="prog-thin mt-1"><div class="bar" style="width:<?= $pct_c ?>%;background:<?= $co['color'] ?>;"></div></div>
                            <div style="font-size:.7rem;color:#999;"><?= $pct_c ?>%</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── By Business Unit ──────────────────────────────────────────────────── -->
    <div class="sec-title">Summary by Business Unit</div>
    <div class="card kpi-card mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover tbl-exec mb-0">
                    <thead>
                        <tr>
                            <th>Business Unit</th>
                            <th class="text-end">Blocks</th>
                            <th class="text-end">Total Area (ha)</th>
                            <th class="text-end">Planted (ha)</th>
                            <th class="text-end">% Planted</th>
                            <th class="text-end">TM (ha)</th>
                            <th class="text-end">TBM (ha)</th>
                            <th class="text-end">TR (ha)</th>
                            <th class="text-end">Trees</th>
                            <th class="text-end">Avg Density</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_bu as $row):
                            $pct = $row['total_area'] > 0 ? round($row['planted_area'] / $row['total_area'] * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['unit_code']) ?></strong> <span class="text-muted"><?= htmlspecialchars($row['unit_name']) ?></span></td>
                            <td class="text-end"><?= $row['blocks'] ?></td>
                            <td class="text-end"><?= number_format($row['total_area'], 2) ?></td>
                            <td class="text-end"><?= number_format($row['planted_area'], 2) ?></td>
                            <td class="text-end">
                                <div class="prog-thin mb-1"><div class="bar" style="width:<?= $pct ?>%"></div></div>
                                <?= $pct ?>%
                            </td>
                            <td class="text-end fw-semibold" style="color:#2e7d32"><?= number_format($row['tm_area'], 2) ?></td>
                            <td class="text-end text-warning"><?= number_format($row['tbm_area'], 2) ?></td>
                            <td class="text-end text-danger"><?= number_format($row['tr_area'], 2) ?></td>
                            <td class="text-end"><?= number_format($row['trees']) ?></td>
                            <td class="text-end"><?= $row['avg_density'] > 0 ? round($row['avg_density']) : '–' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><strong>TOTAL</strong></td>
                            <td class="text-end"><?= $kpi['total_blocks'] ?></td>
                            <td class="text-end"><?= number_format($kpi['total_area'], 2) ?></td>
                            <td class="text-end"><?= number_format($kpi['planted_area'], 2) ?></td>
                            <td class="text-end"><?= $pct_planted ?>%</td>
                            <td class="text-end" style="color:#2e7d32"><?= number_format($kpi['mature_area'], 2) ?></td>
                            <td class="text-end text-warning"><?= number_format($kpi['immature_area'], 2) ?></td>
                            <td class="text-end text-danger"><?= number_format($kpi['replanting_area'], 2) ?></td>
                            <td class="text-end"><?= number_format($kpi['total_trees']) ?></td>
                            <td class="text-end"><?= $density_eff > 0 ? $density_eff : '–' ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ── By Division ───────────────────────────────────────────────────────── -->
    <div class="sec-title">Summary by Division (Afdeling)</div>
    <div class="card kpi-card mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover tbl-exec mb-0">
                    <thead>
                        <tr>
                            <th>BU</th>
                            <th>Division</th>
                            <th class="text-end">Blocks</th>
                            <th class="text-end">Total (ha)</th>
                            <th class="text-end">Planted (ha)</th>
                            <th class="text-end">% Planted</th>
                            <th class="text-end">TM (ha)</th>
                            <th class="text-end">TBM (ha)</th>
                            <th class="text-end">TR (ha)</th>
                            <th class="text-end">Trees</th>
                            <th class="text-end">Density</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_div as $row):
                            $pct = $row['total_area'] > 0 ? round($row['planted_area'] / $row['total_area'] * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['bu_code']) ?></strong></td>
                            <td><?= htmlspecialchars($row['division_code']) ?> <span class="text-muted">– <?= htmlspecialchars($row['division_name']) ?></span></td>
                            <td class="text-end"><?= $row['blocks'] ?></td>
                            <td class="text-end"><?= number_format($row['total_area'], 2) ?></td>
                            <td class="text-end"><?= number_format($row['planted_area'], 2) ?></td>
                            <td class="text-end">
                                <div class="prog-thin mb-1"><div class="bar" style="width:<?= $pct ?>%"></div></div>
                                <?= $pct ?>%
                            </td>
                            <td class="text-end fw-semibold" style="color:#2e7d32"><?= number_format($row['tm_area'], 2) ?></td>
                            <td class="text-end text-warning"><?= number_format($row['tbm_area'], 2) ?></td>
                            <td class="text-end text-danger"><?= number_format($row['tr_area'], 2) ?></td>
                            <td class="text-end"><?= number_format($row['trees']) ?></td>
                            <td class="text-end"><?= $row['avg_density'] > 0 ? round($row['avg_density']) : '–' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2"><strong>TOTAL</strong></td>
                            <td class="text-end"><?= $kpi['total_blocks'] ?></td>
                            <td class="text-end"><?= number_format($kpi['total_area'], 2) ?></td>
                            <td class="text-end"><?= number_format($kpi['planted_area'], 2) ?></td>
                            <td class="text-end"><?= $pct_planted ?>%</td>
                            <td class="text-end" style="color:#2e7d32"><?= number_format($kpi['mature_area'], 2) ?></td>
                            <td class="text-end text-warning"><?= number_format($kpi['immature_area'], 2) ?></td>
                            <td class="text-end text-danger"><?= number_format($kpi['replanting_area'], 2) ?></td>
                            <td class="text-end"><?= number_format($kpi['total_trees']) ?></td>
                            <td class="text-end"><?= $density_eff > 0 ? $density_eff : '–' ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ── By Status + By Planting Year (2 col) ──────────────────────────────── -->
    <div class="row g-3 mb-3">
        <!-- By Status -->
        <div class="col-lg-5">
            <div class="sec-title">By Plant Status</div>
            <div class="card kpi-card">
                <div class="card-body p-0">
                    <table class="table table-bordered tbl-exec mb-0">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th class="text-end">Blocks</th>
                                <th class="text-end">Total (ha)</th>
                                <th class="text-end">Planted (ha)</th>
                                <th class="text-end">Trees</th>
                                <th class="text-end">Density</th>
                                <th class="text-end">Avg Age</th>
                                <th class="text-end">% Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($by_status as $row):
                                $pct_s = $kpi['planted_area'] > 0 ? round($row['planted_area'] / $kpi['planted_area'] * 100, 1) : 0;
                                $s_col = ['TM'=>'#2e7d32','TBM'=>'#f57f17','TR'=>'#c62828','Replanting'=>'#1565c0'][$row['status']] ?? '#607d8b';
                            ?>
                            <tr>
                                <td>
                                    <span class="badge" style="background:<?= $s_col ?>;font-size:.72rem;">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end"><?= $row['blocks'] ?></td>
                                <td class="text-end"><?= number_format($row['total_area'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['planted_area'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['trees']) ?></td>
                                <td class="text-end"><?= $row['avg_density'] > 0 ? round($row['avg_density']) : '–' ?></td>
                                <td class="text-end"><?= $row['avg_age'] > 0 ? round($row['avg_age'], 1) : '–' ?></td>
                                <td class="text-end">
                                    <div class="prog-thin mb-1"><div class="bar" style="width:<?= $pct_s ?>%;background:<?= $s_col ?>;"></div></div>
                                    <?= $pct_s ?>%
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td><strong>TOTAL</strong></td>
                                <td class="text-end"><?= $kpi['total_blocks'] ?></td>
                                <td class="text-end"><?= number_format($kpi['total_area'], 2) ?></td>
                                <td class="text-end"><?= number_format($kpi['planted_area'], 2) ?></td>
                                <td class="text-end"><?= number_format($kpi['total_trees']) ?></td>
                                <td class="text-end"><?= $density_eff > 0 ? $density_eff : '–' ?></td>
                                <td class="text-end"><?= $kpi['avg_age'] > 0 ? round($kpi['avg_age'], 1) : '–' ?></td>
                                <td class="text-end">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- By Planting Year -->
        <div class="col-lg-7">
            <div class="sec-title">By Planting Year</div>
            <div class="card kpi-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered tbl-exec mb-0">
                            <thead>
                                <tr>
                                    <th>Planting Year</th>
                                    <th class="text-end">Blocks</th>
                                    <th class="text-end">Total (ha)</th>
                                    <th class="text-end">Planted (ha)</th>
                                    <th class="text-end">TM (ha)</th>
                                    <th class="text-end">TBM (ha)</th>
                                    <th class="text-end">TR (ha)</th>
                                    <th class="text-end">Trees</th>
                                    <th class="text-end">Avg Age</th>
                                    <th class="text-end">Density</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($by_year as $row): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['year']) ?></strong></td>
                                    <td class="text-end"><?= $row['blocks'] ?></td>
                                    <td class="text-end"><?= number_format($row['total_area'], 2) ?></td>
                                    <td class="text-end"><?= number_format($row['planted_area'], 2) ?></td>
                                    <td class="text-end fw-semibold" style="color:#2e7d32"><?= number_format($row['tm_area'], 2) ?></td>
                                    <td class="text-end text-warning"><?= number_format($row['tbm_area'], 2) ?></td>
                                    <td class="text-end text-danger"><?= number_format($row['tr_area'], 2) ?></td>
                                    <td class="text-end"><?= number_format($row['trees']) ?></td>
                                    <td class="text-end"><?= $row['avg_age'] > 0 ? round($row['avg_age'], 1) : '–' ?></td>
                                    <td class="text-end"><?= $row['avg_density'] > 0 ? round($row['avg_density']) : '–' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td><strong>TOTAL</strong></td>
                                    <td class="text-end"><?= $kpi['total_blocks'] ?></td>
                                    <td class="text-end"><?= number_format($kpi['total_area'], 2) ?></td>
                                    <td class="text-end"><?= number_format($kpi['planted_area'], 2) ?></td>
                                    <td class="text-end" style="color:#2e7d32"><?= number_format($kpi['mature_area'], 2) ?></td>
                                    <td class="text-end text-warning"><?= number_format($kpi['immature_area'], 2) ?></td>
                                    <td class="text-end text-danger"><?= number_format($kpi['replanting_area'], 2) ?></td>
                                    <td class="text-end"><?= number_format($kpi['total_trees']) ?></td>
                                    <td class="text-end"><?= $kpi['avg_age'] > 0 ? round($kpi['avg_age'], 1) : '–' ?></td>
                                    <td class="text-end"><?= $density_eff > 0 ? $density_eff : '–' ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Ownership Type ────────────────────────────────────────────────────── -->
    <?php if (count($by_ownership) > 0): ?>
    <div class="sec-title">Area by Ownership Type</div>
    <div class="row g-3 mb-3">
        <?php
        $ow_colors = ['inti' => '#1565c0', 'plasma' => '#6a1b9a'];
        $ow_labels = ['inti' => 'Inti (Core Estate)', 'plasma' => 'Plasma (Smallholder)'];
        $ow_icons  = ['inti' => 'bi-building', 'plasma' => 'bi-people'];
        foreach ($by_ownership as $ow):
            $ot       = $ow['ownership_type'];
            $col      = $ow_colors[$ot]  ?? '#555';
            $lbl      = $ow_labels[$ot]  ?? ucfirst($ot);
            $icon     = $ow_icons[$ot]   ?? 'bi-circle';
            $pct_p    = $ow['total_area'] > 0 ? round($ow['planted_area']   / $ow['total_area'] * 100, 1) : 0;
            $pct_tot  = $kpi['total_area'] > 0 ? round($ow['total_area']    / $kpi['total_area'] * 100, 1) : 0;
            $pct_mat  = $ow['planted_area'] > 0 ? round($ow['tm_area']       / $ow['planted_area'] * 100, 1) : 0;
        ?>
        <div class="col-md-4">
            <div class="card kpi-card h-100" style="border-left:4px solid <?= $col ?>;">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="<?= $icon ?> fs-4" style="color:<?= $col ?>"></i>
                        <span class="fw-bold fs-6" style="color:<?= $col ?>"><?= $lbl ?></span>
                        <span class="ms-auto badge" style="background:<?= $col ?>;"><?= $pct_tot ?>% of estate</span>
                    </div>
                    <div class="row g-2 text-center mb-2">
                        <div class="col-4">
                            <div class="fw-bold" style="font-size:1.25rem;color:<?= $col ?>"><?= $ow['blocks'] ?></div>
                            <div class="text-muted" style="font-size:.7rem;">Blocks</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold" style="font-size:1.25rem;color:<?= $col ?>"><?= number_format($ow['total_area'], 1) ?></div>
                            <div class="text-muted" style="font-size:.7rem;">Total ha</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold" style="font-size:1.25rem;color:<?= $col ?>"><?= number_format($ow['trees']) ?></div>
                            <div class="text-muted" style="font-size:.7rem;">Trees</div>
                        </div>
                    </div>
                    <div style="font-size:.78rem;" class="mb-2">
                        <div class="d-flex justify-content-between">
                            <span>Planted: <strong><?= number_format($ow['planted_area'], 2) ?> ha</strong></span>
                            <span class="text-muted"><?= $pct_p ?>%</span>
                        </div>
                        <div class="prog-thin mb-1"><div class="bar" style="width:<?= $pct_p ?>%;background:<?= $col ?>;"></div></div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap" style="font-size:.74rem;">
                        <span style="color:#2e7d32">&#9632; TM <?= number_format($ow['tm_area'], 2) ?> ha</span>
                        <span style="color:#f57f17">&#9632; TBM <?= number_format($ow['tbm_area'], 2) ?> ha</span>
                        <span style="color:#c62828">&#9632; TR <?= number_format($ow['tr_area'], 2) ?> ha</span>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── By Plant Variety ───────────────────────────────────────────────────── -->
    <?php if (count($by_variety) > 0 && !($by_variety[0]['variety_name'] === 'Unspecified' && count($by_variety) === 1 && $by_variety[0]['trees'] == 0)): ?>
    <div class="sec-title">Area by Plant Variety</div>
    <div class="card kpi-card mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover tbl-exec mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Variety Name</th>
                            <th>Category</th>
                            <th class="text-end">Blocks</th>
                            <th class="text-end">Total Area (ha)</th>
                            <th class="text-end">Planted (ha)</th>
                            <th class="text-end">Trees</th>
                            <th class="text-end">% of Planted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_variety as $row):
                            $pct_v = $kpi['planted_area'] > 0 ? round($row['planted_area'] / $kpi['planted_area'] * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['variety_code']) ?></strong></td>
                            <td><?= htmlspecialchars($row['variety_name']) ?></td>
                            <td><?= htmlspecialchars($row['category']) ?></td>
                            <td class="text-end"><?= $row['blocks'] ?></td>
                            <td class="text-end"><?= number_format($row['total_area'], 2) ?></td>
                            <td class="text-end"><?= number_format($row['planted_area'], 2) ?></td>
                            <td class="text-end"><?= number_format($row['trees']) ?></td>
                            <td class="text-end">
                                <div class="prog-thin mb-1"><div class="bar" style="width:<?= $pct_v ?>%"></div></div>
                                <?= $pct_v ?>%
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Block Detail Table ─────────────────────────────────────────────────── -->
    <div class="sec-title d-flex align-items-center justify-content-between">
        <span>Block Detail</span>
        <span class="text-muted fw-normal" style="font-size:.78rem;"><?= count($blocks_data) ?> block<?= count($blocks_data) != 1 ? 's' : '' ?></span>
    </div>
    <div class="card kpi-card mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover tbl-exec mb-0" id="blockTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>BU</th>
                            <th>Division</th>
                            <th>Plt Year</th>
                            <th>Block Code</th>
                            <th>Block Name</th>
                            <th class="text-end">Area (ha)</th>
                            <th class="text-end">Planted (ha)</th>
                            <th class="text-end">Non-Planted (ha)</th>
                            <th class="text-end">Trees</th>
                            <th class="text-end">Density</th>
                            <th class="text-end">Age (yr)</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Harvest</th>
                            <th class="text-center">Ownership</th>
                            <th>Topography</th>
                            <th>Soil Type</th>
                            <th class="text-end">Volume (m³)</th>
                            <th class="text-end">Carbon (ton)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $row_no = 0;
                        foreach ($blocks_data as $b):
                            $row_no++;
                            $s_col    = ['TM'=>'#2e7d32','TBM'=>'#f57f17','TR'=>'#c62828','Replanting'=>'#1565c0'][$b['status']] ?? '#607d8b';
                            $h_cls    = ['Harvesting'=>'success','Ready'=>'primary','Not Ready'=>'secondary'][$b['harvest_status']] ?? 'secondary';
                            $ow_col   = ['inti'=>'#1565c0','plasma'=>'#6a1b9a'][$b['ownership_type']] ?? '#555';
                            $non_pltd = round($b['area'] - $b['planted_area'], 2);
                        ?>
                        <tr>
                            <td class="text-muted" style="font-size:.72rem;"><?= $row_no ?></td>
                            <td><strong><?= htmlspecialchars($b['unit_code']) ?></strong></td>
                            <td style="white-space:nowrap"><?= htmlspecialchars($b['division_code']) ?></td>
                            <td class="text-center"><?= htmlspecialchars($b['plant_year']) ?></td>
                            <td><strong><?= htmlspecialchars($b['block_code']) ?></strong></td>
                            <td><?= htmlspecialchars($b['block_name']) ?></td>
                            <td class="text-end"><?= number_format($b['area'], 2) ?></td>
                            <td class="text-end"><?= number_format($b['planted_area'], 2) ?></td>
                            <td class="text-end text-muted"><?= number_format(max(0, $non_pltd), 2) ?></td>
                            <td class="text-end"><?= number_format($b['total_plants']) ?></td>
                            <td class="text-end"><?= $b['plant_density'] ?? '–' ?></td>
                            <td class="text-end"><?= $b['plant_age'] ?? '–' ?></td>
                            <td class="text-center">
                                <span class="badge" style="background:<?= $s_col ?>;font-size:.72rem;"><?= htmlspecialchars($b['status']) ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($b['harvest_status']): ?>
                                <span class="badge bg-<?= $h_cls ?>" style="font-size:.72rem;"><?= htmlspecialchars($b['harvest_status']) ?></span>
                                <?php else: ?><span class="text-muted">–</span><?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge" style="background:<?= $ow_col ?>;font-size:.72rem;"><?= ucfirst($b['ownership_type']) ?></span>
                            </td>
                            <td><?= htmlspecialchars($b['topography'] ?? '–') ?></td>
                            <td><?= htmlspecialchars($b['soil_type'] ?? '–') ?></td>
                            <td class="text-end"><?= $b['volume_m3'] > 0 ? number_format($b['volume_m3'], 1) : '–' ?></td>
                            <td class="text-end"><?= $b['carbon_stock_ton'] > 0 ? number_format($b['carbon_stock_ton'], 1) : '–' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6"><strong>TOTAL</strong></td>
                            <td class="text-end"><strong><?= number_format($kpi['total_area'], 2) ?></strong></td>
                            <td class="text-end"><strong><?= number_format($kpi['planted_area'], 2) ?></strong></td>
                            <td class="text-end"><?= number_format(max(0, $kpi['total_area'] - $kpi['planted_area']), 2) ?></td>
                            <td class="text-end"><strong><?= number_format($kpi['total_trees']) ?></strong></td>
                            <td class="text-end"><?= $density_eff > 0 ? $density_eff : '–' ?></td>
                            <td class="text-end"><?= $kpi['avg_age'] > 0 ? round($kpi['avg_age'], 1) : '–' ?></td>
                            <td colspan="7"></td>
                            <td class="text-end"><?= $kpi['total_volume'] > 0 ? number_format($kpi['total_volume'], 0) : '–' ?></td>
                            <td class="text-end"><?= $kpi['total_carbon'] > 0 ? number_format($kpi['total_carbon'], 0) : '–' ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php if (count($blocks_data) >= 500): ?>
            <div class="alert alert-info m-2 py-1 px-2 small mb-0 no-print">
                Showing first 500 blocks. Use filters to narrow results.
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /container -->

<?php
// ── Chart JSON ────────────────────────────────────────────────────────────────
$json_div_labels  = json_encode($chart_div_labels);
$json_div_area    = json_encode($chart_div_area);
$json_div_planted = json_encode($chart_div_planted);

$json_s_labels = json_encode($status_labels);
$json_s_values = json_encode($status_values);
$json_s_colors = json_encode($status_colors);

$extra_js = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Horizontal bar: Total area vs Planted area by Division ────────────────
(function(){
    var ctx = document.getElementById('chartDivision');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: {$json_div_labels},
            datasets: [
                {
                    label: 'Total Area (ha)',
                    data:  {$json_div_area},
                    backgroundColor: 'rgba(46,125,50,0.18)',
                    borderColor: '#2e7d32',
                    borderWidth: 1
                },
                {
                    label: 'Planted (ha)',
                    data:  {$json_div_planted},
                    backgroundColor: 'rgba(46,125,50,0.75)',
                    borderColor: '#1b5e20',
                    borderWidth: 1
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: function(c) { return c.dataset.label + ': ' + parseFloat(c.raw).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' ha'; }
                    }
                }
            },
            scales: {
                x: { title: { display: true, text: 'Area (ha)' }, grid: { color: 'rgba(0,0,0,.05)' } },
                y: { ticks: { font: { size: 10 } } }
            }
        }
    });
})();

// ── Doughnut: Planted area by status ─────────────────────────────────────
(function(){
    var ctx = document.getElementById('chartStatus');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: {$json_s_labels},
            datasets: [{ data: {$json_s_values}, backgroundColor: {$json_s_colors}, borderWidth: 2 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(c) {
                            var total = c.dataset.data.reduce(function(a,b){ return a+b; },0);
                            var pct = total > 0 ? (c.raw / total * 100).toFixed(1) : 0;
                            return c.label + ': ' + parseFloat(c.raw).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' ha (' + pct + '%)';
                        }
                    }
                }
            },
            cutout: '58%'
        }
    });
})();
</script>
JS;
?>

<?php require_once 'includes/footer.php'; ?>
