<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db         = getDB();
require_once 'includes/functions.php';
require_once 'includes/lang.php';
$page_title = __('pt_cost_plan');
$COMPANY_ID = 1001; // PT Borneo Sawit Mandiri

// ─── Filters ──────────────────────────────────────────────────────────────────
$year = (int) get('year', 2026);
$tab  = get('tab', 'kebun'); // kebun | pks | all

require_once 'includes/header.php';

// ─── Load Assumptions ────────────────────────────────────────────────────────
$asmp_defaults = [
    'daily_wage_kebun'   => 180000,
    'daily_wage_pks'     => 200000,
    'price_urea'         => 3500,
    'price_tsp'          => 6500,
    'price_kcl'          => 7200,
    'price_dolomite'     => 2800,
    'price_herbicide'    => 85000,
    'price_insecticide'  => 120000,
    'price_diesel'       => 7000,
    'price_lubricant'    => 45000,
    'overhead_pct_kebun' => 0.08,
    'overhead_pct_pks'   => 0.10,
    'depreciation_kebun' => 1200000000,
    'depreciation_pks'   => 800000000,
    'updated_at'         => null,
    'updated_by'         => null,
];
try {
    // Ensure tables exist (same DDL as ajax/cost_plan.php)
    $db->exec("CREATE TABLE IF NOT EXISTS cost_plan_assumptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL,
        company_id INT DEFAULT NULL,
        daily_wage_kebun    DECIMAL(12,2) NOT NULL DEFAULT 180000,
        daily_wage_pks      DECIMAL(12,2) NOT NULL DEFAULT 200000,
        price_urea          DECIMAL(12,2) NOT NULL DEFAULT 3500,
        price_tsp           DECIMAL(12,2) NOT NULL DEFAULT 6500,
        price_kcl           DECIMAL(12,2) NOT NULL DEFAULT 7200,
        price_dolomite      DECIMAL(12,2) NOT NULL DEFAULT 2800,
        price_herbicide     DECIMAL(12,2) NOT NULL DEFAULT 85000,
        price_insecticide   DECIMAL(12,2) NOT NULL DEFAULT 120000,
        price_diesel        DECIMAL(12,2) NOT NULL DEFAULT 7000,
        price_lubricant     DECIMAL(12,2) NOT NULL DEFAULT 45000,
        overhead_pct_kebun  DECIMAL(6,4)  NOT NULL DEFAULT 0.0800,
        overhead_pct_pks    DECIMAL(6,4)  NOT NULL DEFAULT 0.1000,
        depreciation_kebun  DECIMAL(15,2) NOT NULL DEFAULT 0,
        depreciation_pks    DECIMAL(15,2) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by VARCHAR(100) DEFAULT NULL,
        UNIQUE KEY uq_year_company (year, company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS cost_plan_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL,
        company_id INT DEFAULT NULL,
        business_unit_id INT DEFAULT NULL,
        unit_type VARCHAR(20) NOT NULL DEFAULT 'kebun',
        cost_category VARCHAR(50) NOT NULL,
        cost_item VARCHAR(150) NOT NULL,
        unit VARCHAR(30) DEFAULT NULL,
        volume DECIMAL(15,2) NOT NULL DEFAULT 0,
        unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        planned_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        actual_amount  DECIMAL(18,2) NOT NULL DEFAULT 0,
        jan DECIMAL(15,2) DEFAULT 0, feb DECIMAL(15,2) DEFAULT 0,
        mar DECIMAL(15,2) DEFAULT 0, apr DECIMAL(15,2) DEFAULT 0,
        mei DECIMAL(15,2) DEFAULT 0, jun DECIMAL(15,2) DEFAULT 0,
        jul DECIMAL(15,2) DEFAULT 0, agu DECIMAL(15,2) DEFAULT 0,
        sep DECIMAL(15,2) DEFAULT 0, okt DECIMAL(15,2) DEFAULT 0,
        nov DECIMAL(15,2) DEFAULT 0, des DECIMAL(15,2) DEFAULT 0,
        notes TEXT DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by VARCHAR(100) DEFAULT NULL,
        KEY idx_year_unit (year, unit_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default assumptions for this company if missing
    $db->prepare("INSERT IGNORE INTO cost_plan_assumptions
        (year, company_id, daily_wage_kebun, daily_wage_pks, price_urea, price_tsp, price_kcl,
         price_dolomite, price_herbicide, price_insecticide, price_diesel, price_lubricant,
         overhead_pct_kebun, overhead_pct_pks, depreciation_kebun, depreciation_pks, updated_by)
        VALUES (?, ?, 180000, 200000, 3500, 6500, 7200, 2800, 85000, 120000, 7000, 45000,
                0.08, 0.10, 1200000000, 800000000, 'system')"
    )->execute([$year, $COMPANY_ID]);

    // Load assumptions for company_id 1001, fall back to NULL (global)
    $s = $db->prepare("SELECT * FROM cost_plan_assumptions
                       WHERE year=? AND company_id=? LIMIT 1");
    $s->execute([$year, $COMPANY_ID]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $asmp = $row ? array_merge($asmp_defaults, $row) : $asmp_defaults;

} catch (PDOException $e) {
    $asmp = $asmp_defaults;
}

// ─── Load lines for company_id 1001 ─────────────────────────────────────────
try {
    $s2 = $db->prepare("SELECT * FROM cost_plan_lines
                        WHERE year=? AND company_id=?
                        ORDER BY unit_type, cost_category, sort_order, id");
    $s2->execute([$year, $COMPANY_ID]);
    $all_lines = $s2->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $all_lines = []; }

// Group lines: unit_type → cost_category → rows[]
$grouped = ['kebun' => [], 'pks' => []];
foreach ($all_lines as $l) {
    $ut  = $l['unit_type'];
    $cat = $l['cost_category'];
    if (!isset($grouped[$ut]))       $grouped[$ut] = [];
    if (!isset($grouped[$ut][$cat])) $grouped[$ut][$cat] = [];
    $grouped[$ut][$cat][] = $l;
}

// KPI totals
$kpi = ['kebun' => ['planned'=>0,'actual'=>0], 'pks' => ['planned'=>0,'actual'=>0]];
foreach ($all_lines as $l) {
    $ut = $l['unit_type'];
    if (isset($kpi[$ut])) {
        $kpi[$ut]['planned'] += (float)$l['planned_amount'];
        $kpi[$ut]['actual']  += (float)$l['actual_amount'];
    }
}
$total_planned = $kpi['kebun']['planned'] + $kpi['pks']['planned'];
$total_actual  = $kpi['kebun']['actual']  + $kpi['pks']['actual'];

// Category meta
$cat_labels = [
    'labor'        => ['label'=>'Tenaga Kerja',  'color'=>'#1a6b3a','icon'=>'bi-people-fill'],
    'fertilizer'   => ['label'=>'Pupuk',          'color'=>'#2e7d32','icon'=>'bi-flower2'],
    'chemical'     => ['label'=>'Bahan Kimia',    'color'=>'#6a1b9a','icon'=>'bi-droplet-fill'],
    'fuel'         => ['label'=>'BBM & Pelumas',  'color'=>'#e65100','icon'=>'bi-fuel-pump'],
    'maintenance'  => ['label'=>'Pemeliharaan',   'color'=>'#1565c0','icon'=>'bi-tools'],
    'depreciation' => ['label'=>'Penyusutan',     'color'=>'#546e7a','icon'=>'bi-hourglass-split'],
    'overhead'     => ['label'=>'Overhead',       'color'=>'#6d4c41','icon'=>'bi-building'],
    'other'        => ['label'=>'Lainnya',        'color'=>'#37474f','icon'=>'bi-three-dots'],
];

$months_id  = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$month_cols = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];

function fmt_b($n)  { return number_format($n/1e9, 2, ',', '.'); }
function fmt_m($n)  { return number_format($n/1e6, 1, ',', '.'); }
function var_pct($plan, $actual) {
    if ($plan <= 0) return 0;
    return round(($actual - $plan) / $plan * 100, 1);
}
function var_badge_cp($plan, $actual) {
    $pct = var_pct($plan, $actual);
    if (abs($pct) < 2) return '<span class="badge bg-success">On Budget</span>';
    if ($pct > 0)      return '<span class="badge bg-danger">+'.number_format($pct,1,',','.').'%</span>';
    return '<span class="badge bg-info text-dark">'.number_format($pct,1,',','.').'%</span>';
}
?>

<style>
.cp-accent   { color:#0d6e8c !important; }
.bg-cp       { background-color:#0d6e8c !important; }
.btn-cp      { background-color:#0d6e8c; color:#fff; border:none; }
.btn-cp:hover{ background-color:#0a5a73; color:#fff; }
.bg-pks      { background-color:#4a148c !important; }

/* KPI */
.kpi-card          { border-left:4px solid #0d6e8c; }
.kpi-card.pks      { border-left-color:#4a148c; }
.kpi-card.total    { border-left-color:#b71c1c; }
.kpi-card.saving   { border-left-color:#1b5e20; }
.kpi-val           { font-size:1.5rem; font-weight:700; }

/* Assumptions panel */
#asumsiPanel       { display:none; }
#asumsiPanel.show  { display:block; }
.asumsi-group-title {
    font-size:.72rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.6px; color:#0d6e8c;
    border-bottom:1px solid #b2dfdb; padding-bottom:4px;
    margin-bottom:8px; margin-top:12px;
}
.asumsi-group-title:first-child { margin-top:0; }
.form-label-sm  { font-size:.78rem; color:#555; margin-bottom:2px; }
.input-asumsi   { font-size:.82rem; }

/* Cost table */
.table-cost thead th { background-color:#0d6e8c; color:#fff; font-weight:600; white-space:nowrap; }
.table-cost .cat-header td {
    background:#e0f2f1; font-weight:700; color:#004d40;
    font-size:.79rem; text-transform:uppercase; letter-spacing:.5px;
    border-top:2px solid #80cbc4 !important;
}
.table-cost .total-row td {
    background:#e8f5e9; font-weight:700; font-size:.88rem;
    border-top:2px solid #a5d6a7;
}
.table-pks thead th   { background-color:#4a148c !important; }
.table-pks .cat-header td { background:#f3e5f5; color:#4a148c; border-color:#ce93d8 !important; }
.table-pks .total-row td  { background:#f3e5f5; border-color:#ce93d8; }

/* Variance colours */
.var-over  { color:#b71c1c; font-weight:600; }
.var-under { color:#1b5e20; font-weight:600; }

/* Monthly mini bars */
.month-bars { display:flex; gap:1px; align-items:flex-end; height:22px; min-width:120px; }
.month-bars .mb { flex:1; background:#4db6ac; border-radius:1px 1px 0 0; min-height:2px; }
.table-pks .month-bars .mb { background:#9c27b0; }

/* Print */
@media print {
    .no-print { display:none !important; }
    .table-cost { font-size:.72rem; }
    .month-bars { display:none; }
}
</style>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center mb-3 no-print">
    <div>
        <h1 class="cp-accent mb-0"><i class="bi bi-calculator"></i> Rencana Biaya <?= $year ?></h1>
        <p class="text-muted mb-0 small">PT Borneo Sawit Mandiri — Biaya Operasional Kebun &amp; PKS</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-secondary btn-sm" id="btnToggleAsumsi">
            <i class="bi bi-sliders"></i> Edit Asumsi
        </button>
        <button class="btn btn-cp btn-sm" id="btnAddLine">
            <i class="bi bi-plus-circle"></i> Tambah Baris
        </button>
        <button class="btn btn-outline-dark btn-sm" onclick="window.print()">
            <i class="bi bi-printer"></i> Cetak
        </button>
        <a href="?year=<?= $year ?>&tab=<?= $tab ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </a>
    </div>
</div>

<!-- ════════════════════ ASSUMPTIONS PANEL ════════════════════════════════════ -->
<div id="asumsiPanel" class="card mb-3 border-info no-print">
    <div class="card-header py-2" style="background:#e0f4f8;border-bottom:1px solid #b2dfdb;">
        <div class="d-flex justify-content-between align-items-center">
            <span class="fw-semibold small cp-accent">
                <i class="bi bi-sliders me-1"></i>Asumsi &amp; Parameter Biaya <?= $year ?>
                <span class="text-muted fw-normal ms-1" style="font-size:.72rem;">(PT Borneo Sawit Mandiri)</span>
            </span>
            <?php if ($asmp['updated_at']): ?>
            <span class="text-muted" style="font-size:.72rem;">
                Diupdate: <?= date('d M Y H:i', strtotime($asmp['updated_at'])) ?>
                <?php if ($asmp['updated_by']): ?> oleh <strong><?= htmlspecialchars($asmp['updated_by']) ?></strong><?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body py-3">
        <form id="formAsumsi" onsubmit="return false;">
            <input type="hidden" name="year"       value="<?= $year ?>">
            <input type="hidden" name="company_id" value="<?= $COMPANY_ID ?>">
            <div class="row g-3">
                <!-- Kolom 1: Tenaga Kerja -->
                <div class="col-sm-6 col-lg-3">
                    <div class="asumsi-group-title"><i class="bi bi-people me-1"></i>Upah Harian (Rp/HK)</div>
                    <div class="mb-2">
                        <label class="form-label-sm">Upah Kebun (Rp/HK)</label>
                        <input type="number" name="daily_wage_kebun" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['daily_wage_kebun'] ?>" step="1000" min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Upah PKS (Rp/HK)</label>
                        <input type="number" name="daily_wage_pks" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['daily_wage_pks'] ?>" step="1000" min="0">
                    </div>
                </div>
                <!-- Kolom 2: Pupuk -->
                <div class="col-sm-6 col-lg-3">
                    <div class="asumsi-group-title"><i class="bi bi-flower2 me-1"></i>Harga Pupuk (Rp/kg)</div>
                    <div class="mb-2">
                        <label class="form-label-sm">Urea</label>
                        <input type="number" name="price_urea" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_urea'] ?>" step="50" min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">TSP / SP-36</label>
                        <input type="number" name="price_tsp" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_tsp'] ?>" step="50" min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">KCl / MOP</label>
                        <input type="number" name="price_kcl" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_kcl'] ?>" step="50" min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Dolomit / Kiserit</label>
                        <input type="number" name="price_dolomite" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_dolomite'] ?>" step="50" min="0">
                    </div>
                </div>
                <!-- Kolom 3: Kimia & BBM -->
                <div class="col-sm-6 col-lg-3">
                    <div class="asumsi-group-title"><i class="bi bi-droplet-fill me-1"></i>Kimia &amp; BBM</div>
                    <div class="mb-2">
                        <label class="form-label-sm">Herbisida (Rp/liter)</label>
                        <input type="number" name="price_herbicide" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_herbicide'] ?>" step="500" min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Insektisida (Rp/liter)</label>
                        <input type="number" name="price_insecticide" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_insecticide'] ?>" step="500" min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Solar / Diesel (Rp/liter)</label>
                        <input type="number" name="price_diesel" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_diesel'] ?>" step="100" min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Pelumas / Oli (Rp/liter)</label>
                        <input type="number" name="price_lubricant" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_lubricant'] ?>" step="500" min="0">
                    </div>
                </div>
                <!-- Kolom 4: Overhead + Tombol -->
                <div class="col-sm-6 col-lg-3">
                    <div class="asumsi-group-title"><i class="bi bi-building me-1"></i>Overhead &amp; Penyusutan</div>
                    <div class="mb-2">
                        <label class="form-label-sm">Overhead Kebun (%)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="overhead_pct_kebun" class="form-control input-asumsi"
                                   value="<?= round($asmp['overhead_pct_kebun']*100,1) ?>" step="0.5" min="0" max="50">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Overhead PKS (%)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="overhead_pct_pks" class="form-control input-asumsi"
                                   value="<?= round($asmp['overhead_pct_pks']*100,1) ?>" step="0.5" min="0" max="50">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Penyusutan Kebun (Rp/th)</label>
                        <input type="number" name="depreciation_kebun" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['depreciation_kebun'] ?>" step="10000000" min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Penyusutan PKS (Rp/th)</label>
                        <input type="number" name="depreciation_pks" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['depreciation_pks'] ?>" step="10000000" min="0">
                    </div>
                    <div class="d-flex flex-column gap-2 mt-3">
                        <button type="button" id="btnSaveAsumsi" class="btn btn-cp btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Simpan &amp; Hitung Ulang
                        </button>
                        <button type="button" id="btnCancelAsumsi" class="btn btn-outline-secondary btn-sm">Batal</button>
                        <span id="asumsiSpinner" class="d-none small text-muted">
                            <span class="spinner-border spinner-border-sm text-info"></span> Menghitung ulang…
                        </span>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Assumptions summary banner (always visible) -->
<div class="alert mb-3 py-2 px-3 small no-print" style="background:#e0f4f8;border:1px solid #b2dfdb;border-radius:6px;">
    <div class="row g-0 align-items-center flex-wrap gap-2">
        <div class="col-auto">
            <i class="bi bi-info-circle" style="color:#0d6e8c;"></i>
            <strong>Asumsi <?= $year ?></strong>
            <span class="text-muted ms-1" style="font-size:.71rem;">(BSM)</span>:
        </div>
        <div class="col-auto">
            <span class="text-muted">Upah:</span>
            <strong><?= number_format($asmp['daily_wage_kebun'],0,',','.') ?></strong> Kebun ·
            <strong><?= number_format($asmp['daily_wage_pks'],0,',','.') ?></strong> PKS (Rp/HK)
        </div>
        <div class="col-auto">
            <span class="text-muted">Solar:</span>
            <strong><?= number_format($asmp['price_diesel'],0,',','.') ?></strong> Rp/L
        </div>
        <div class="col-auto">
            <span class="text-muted">Pupuk:</span>
            Urea <strong><?= number_format($asmp['price_urea'],0,',','.') ?></strong> ·
            TSP <strong><?= number_format($asmp['price_tsp'],0,',','.') ?></strong> ·
            KCl <strong><?= number_format($asmp['price_kcl'],0,',','.') ?></strong> Rp/kg
        </div>
        <div class="col-auto">
            <span class="text-muted">Overhead:</span>
            Kebun <strong><?= round($asmp['overhead_pct_kebun']*100,1) ?>%</strong> ·
            PKS <strong><?= round($asmp['overhead_pct_pks']*100,1) ?>%</strong>
        </div>
        <div class="col-auto ms-auto">
            <button class="btn btn-sm btn-link p-0 cp-accent" id="btnToggleAsumsi2" style="font-size:.78rem;">
                <i class="bi bi-pencil-square"></i> ubah
            </button>
        </div>
    </div>
</div>

<!-- Filter bar -->
<div class="card mb-3 no-print">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-1">Tahun</label>
                <select name="year" class="form-select form-select-sm">
                    <?php foreach ([2025,2026,2027] as $y): ?>
                        <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">Tampilan</label>
                <select name="tab" class="form-select form-select-sm">
                    <option value="kebun" <?= $tab==='kebun'?'selected':'' ?>>🌿 Kebun saja</option>
                    <option value="pks"   <?= $tab==='pks'  ?'selected':'' ?>>⚙️ PKS saja</option>
                    <option value="all"   <?= $tab==='all'  ?'selected':'' ?>>📊 Kebun + PKS</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-cp btn-sm"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card kpi-card h-100">
            <div class="card-body">
                <p class="text-muted small mb-1"><i class="bi bi-tree"></i> Rencana Kebun</p>
                <div class="kpi-val cp-accent">Rp <?= fmt_b($kpi['kebun']['planned']) ?> M</div>
                <small class="text-muted">Aktual: Rp <?= fmt_b($kpi['kebun']['actual']) ?> M</small>
                <div class="mt-1"><?= var_badge_cp($kpi['kebun']['planned'],$kpi['kebun']['actual']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card pks h-100">
            <div class="card-body">
                <p class="text-muted small mb-1"><i class="bi bi-gear-wide-connected"></i> Rencana PKS</p>
                <div class="kpi-val" style="color:#4a148c">Rp <?= fmt_b($kpi['pks']['planned']) ?> M</div>
                <small class="text-muted">Aktual: Rp <?= fmt_b($kpi['pks']['actual']) ?> M</small>
                <div class="mt-1"><?= var_badge_cp($kpi['pks']['planned'],$kpi['pks']['actual']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card total h-100">
            <div class="card-body">
                <p class="text-muted small mb-1"><i class="bi bi-calculator"></i> Total Rencana</p>
                <div class="kpi-val" style="color:#b71c1c">Rp <?= fmt_b($total_planned) ?> M</div>
                <small class="text-muted">Aktual: Rp <?= fmt_b($total_actual) ?> M</small>
                <div class="mt-1"><?= var_badge_cp($total_planned,$total_actual) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <?php $net = $total_planned - $total_actual; ?>
        <div class="card kpi-card saving h-100">
            <div class="card-body">
                <p class="text-muted small mb-1"><i class="bi bi-piggy-bank"></i> Varians Neto</p>
                <div class="kpi-val <?= $net>=0?'var-under':'var-over' ?>">
                    <?= ($net>=0?'–':'+').fmt_b(abs($net)) ?> M
                </div>
                <small class="text-muted"><?= $net>=0?'Under budget ✅':'Over budget ⚠️' ?></small>
            </div>
        </div>
    </div>
</div>

<?php
// ────────────────────────────────────────────────────────────────────────────
// Helper: render one unit_type cost table
// ────────────────────────────────────────────────────────────────────────────
function render_cost_table(
    array $cats, array $cat_labels, array $month_cols,
    array $months_id, string $unit_type
): void {
    if (empty($cats)) {
        echo '<div class="alert alert-info m-3">'
            .'<i class="bi bi-info-circle me-1"></i>Belum ada data biaya '
            .'<strong>'.strtoupper($unit_type).'</strong>. '
            .'Klik <strong>Tambah Baris</strong> atau jalankan '
            .'<a href="setup_cost_plan.php">setup_cost_plan.php</a> untuk seed data awal.'
            .'</div>';
        return;
    }
    $tbl_cls = $unit_type === 'pks' ? 'table-cost table-pks' : 'table-cost';
    echo '<div class="table-responsive">';
    echo '<table class="table table-sm table-bordered table-hover '.$tbl_cls.' mb-0" style="font-size:.81rem;min-width:860px;">';
    echo '<thead><tr>'
        .'<th style="min-width:190px;">Item Biaya</th>'
        .'<th class="text-center" style="width:55px;">Sat.</th>'
        .'<th class="text-end"   style="width:85px;">Volume</th>'
        .'<th class="text-end"   style="width:95px;">Harga/Sat.</th>'
        .'<th class="text-end"   style="width:115px;">Rencana (Jt)</th>'
        .'<th class="text-end"   style="width:115px;">Aktual (Jt)</th>'
        .'<th class="text-end"   style="width:75px;">Varians</th>'
        .'<th class="text-center" style="width:130px;">Distribusi</th>'
        .'<th class="text-center no-print" style="width:65px;">Aksi</th>'
        .'</tr></thead>';
    echo '<tbody>';

    $grand_plan = 0; $grand_act = 0;
    foreach ($cats as $cat => $lines) {
        $cl       = $cat_labels[$cat] ?? ['label'=>ucfirst($cat),'color'=>'#555','icon'=>'bi-dot'];
        $cat_plan = array_sum(array_column($lines,'planned_amount'));
        $cat_act  = array_sum(array_column($lines,'actual_amount'));
        $cat_var  = $cat_plan > 0 ? round(($cat_act-$cat_plan)/$cat_plan*100,1) : 0;
        $grand_plan += $cat_plan; $grand_act += $cat_act;

        // Category header row
        echo '<tr class="cat-header">';
        echo '<td colspan="4"><i class="'.$cl['icon'].'" style="color:'.$cl['color'].'"></i> '
            .htmlspecialchars($cl['label']).'</td>';
        echo '<td class="text-end">'.number_format($cat_plan/1e6,1,',','.').'</td>';
        echo '<td class="text-end">'.number_format($cat_act/1e6,1,',','.').'</td>';
        echo '<td class="text-end '.($cat_var>5?'var-over':($cat_var<-5?'var-under':'')).'">'.
             ($cat_var>=0?'+':'').number_format($cat_var,1,',','.').'%</td>';
        echo '<td></td><td class="no-print"></td>';
        echo '</tr>';

        // Detail rows
        foreach ($lines as $l) {
            $plan = (float)$l['planned_amount'];
            $act  = (float)$l['actual_amount'];
            $var  = $plan > 0 ? round(($act-$plan)/$plan*100,1) : 0;

            // Monthly mini-bars
            $max_m = 0;
            foreach ($month_cols as $mc) $max_m = max($max_m, (float)$l[$mc]);
            $bars = '<div class="month-bars">';
            foreach ($month_cols as $i => $mc) {
                $mv  = (float)$l[$mc];
                $pct = $max_m > 0 ? max(4, round($mv/$max_m*100)) : 4;
                $bars .= '<div class="mb" style="height:'.$pct.'%;" title="'.
                    $months_id[$i].': Rp '.number_format($mv/1e6,1,',','.').'jt"></div>';
            }
            $bars .= '</div>';

            echo '<tr data-id="'.(int)$l['id'].'">';
            echo '<td class="ps-3">'.htmlspecialchars($l['cost_item']);
            if (!empty($l['notes']))
                echo ' <i class="bi bi-info-circle text-muted" style="font-size:.69rem;" title="'.
                     htmlspecialchars($l['notes']).'"></i>';
            echo '</td>';
            echo '<td class="text-center text-muted small">'.htmlspecialchars($l['unit']??'').'</td>';
            echo '<td class="text-end">'.((float)$l['volume']>0 ? number_format($l['volume'],0,',','.') : '—').'</td>';
            echo '<td class="text-end">'.((float)$l['unit_price']>0 ? number_format($l['unit_price'],0,',','.') : '—').'</td>';
            echo '<td class="text-end fw-semibold">'.number_format($plan/1e6,1,',','.').'</td>';
            echo '<td class="text-end">'.number_format($act/1e6,1,',','.').'</td>';
            echo '<td class="text-end '.($var>5?'var-over':($var<-5?'var-under':'')).'">'.
                 ($var>=0?'+':'').number_format($var,1,',','.').'%</td>';
            echo '<td>'.$bars.'</td>';
            echo '<td class="text-center no-print">'
                .'<button class="btn btn-xs btn-outline-primary py-0 px-1 btn-edit-line" data-id="'.(int)$l['id'].'" title="Edit"><i class="bi bi-pencil"></i></button> '
                .'<button class="btn btn-xs btn-outline-danger py-0 px-1 btn-del-line ms-1" data-id="'.(int)$l['id'].'" title="Hapus"><i class="bi bi-trash"></i></button>'
                .'</td>';
            echo '</tr>';
        }
    }

    // Grand total
    $vg = $grand_plan > 0 ? round(($grand_act-$grand_plan)/$grand_plan*100,1) : 0;
    echo '<tr class="total-row">';
    echo '<td colspan="4" class="text-end fw-bold ps-2">TOTAL '.strtoupper($unit_type).'</td>';
    echo '<td class="text-end fw-bold">'.number_format($grand_plan/1e6,1,',','.').'</td>';
    echo '<td class="text-end fw-bold">'.number_format($grand_act/1e6,1,',','.').'</td>';
    echo '<td class="text-end fw-bold '.($vg>2?'var-over':($vg<-2?'var-under':'')).'">'.
         ($vg>=0?'+':'').number_format($vg,1,',','.').'%</td>';
    echo '<td></td><td class="no-print"></td>';
    echo '</tr>';
    echo '</tbody></table></div>';
}
?>

<?php if (in_array($tab,['kebun','all'])): ?>
<!-- Kebun table -->
<div class="card mb-4">
    <div class="card-header py-2 d-flex justify-content-between align-items-center bg-cp text-white">
        <span><i class="bi bi-tree me-1"></i> Biaya Kebun <?= $year ?> — PT Borneo Sawit Mandiri</span>
        <span class="badge bg-light text-dark small">
            Rencana: Rp <?= fmt_b($kpi['kebun']['planned']) ?> M
            &nbsp;|&nbsp; Aktual: Rp <?= fmt_b($kpi['kebun']['actual']) ?> M
        </span>
    </div>
    <div class="card-body p-0">
        <?php render_cost_table($grouped['kebun']??[], $cat_labels, $month_cols, $months_id, 'kebun'); ?>
    </div>
</div>
<?php endif; ?>

<?php if (in_array($tab,['pks','all'])): ?>
<!-- PKS table -->
<div class="card mb-4">
    <div class="card-header py-2 d-flex justify-content-between align-items-center bg-pks text-white">
        <span><i class="bi bi-gear-wide-connected me-1"></i> Biaya PKS <?= $year ?> — PT Borneo Sawit Mandiri</span>
        <span class="badge bg-light text-dark small">
            Rencana: Rp <?= fmt_b($kpi['pks']['planned']) ?> M
            &nbsp;|&nbsp; Aktual: Rp <?= fmt_b($kpi['pks']['actual']) ?> M
        </span>
    </div>
    <div class="card-body p-0">
        <?php render_cost_table($grouped['pks']??[], $cat_labels, $month_cols, $months_id, 'pks'); ?>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'all' && ($total_planned > 0 || $total_actual > 0)): ?>
<!-- Summary by category -->
<div class="card mb-4">
    <div class="card-header bg-secondary text-white py-2">
        <i class="bi bi-table me-1"></i> Ringkasan per Kategori — <?= $year ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" style="font-size:.82rem;">
                <thead class="table-light">
                    <tr>
                        <th>Kategori</th>
                        <th class="text-end">Kebun Rencana (Jt)</th>
                        <th class="text-end">Kebun Aktual (Jt)</th>
                        <th class="text-end">PKS Rencana (Jt)</th>
                        <th class="text-end">PKS Aktual (Jt)</th>
                        <th class="text-end">TOTAL Rencana (Jt)</th>
                        <th class="text-end">Varians</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $all_cats = array_unique(array_merge(
                    array_keys($grouped['kebun'] ?? []),
                    array_keys($grouped['pks']   ?? [])
                ));
                $sum_plan = 0; $sum_act = 0;
                foreach ($all_cats as $cat):
                    $kp = array_sum(array_column($grouped['kebun'][$cat] ?? [], 'planned_amount'));
                    $ka = array_sum(array_column($grouped['kebun'][$cat] ?? [], 'actual_amount'));
                    $pp = array_sum(array_column($grouped['pks'][$cat]   ?? [], 'planned_amount'));
                    $pa = array_sum(array_column($grouped['pks'][$cat]   ?? [], 'actual_amount'));
                    $tp = $kp + $pp; $ta = $ka + $pa;
                    $vp = $tp > 0 ? round(($ta-$tp)/$tp*100,1) : 0;
                    $sum_plan += $tp; $sum_act += $ta;
                    $cl = $cat_labels[$cat] ?? ['label'=>ucfirst($cat),'color'=>'#555','icon'=>'bi-dot'];
                ?>
                <tr>
                    <td><i class="<?= $cl['icon'] ?>" style="color:<?= $cl['color'] ?>"></i> <?= htmlspecialchars($cl['label']) ?></td>
                    <td class="text-end"><?= $kp>0 ? number_format($kp/1e6,1,',','.') : '—' ?></td>
                    <td class="text-end"><?= $ka>0 ? number_format($ka/1e6,1,',','.') : '—' ?></td>
                    <td class="text-end"><?= $pp>0 ? number_format($pp/1e6,1,',','.') : '—' ?></td>
                    <td class="text-end"><?= $pa>0 ? number_format($pa/1e6,1,',','.') : '—' ?></td>
                    <td class="text-end fw-semibold"><?= number_format($tp/1e6,1,',','.') ?></td>
                    <td class="text-end <?= $vp>5?'var-over':($vp<-5?'var-under':'') ?>">
                        <?= ($vp>=0?'+':'').number_format($vp,1,',','.') ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php $vt = $sum_plan>0 ? round(($sum_act-$sum_plan)/$sum_plan*100,1) : 0; ?>
                    <tr class="table-dark">
                        <td class="fw-bold">TOTAL</td>
                        <td class="text-end fw-bold"><?= number_format(array_sum(array_column(array_filter($all_lines,fn($l)=>$l['unit_type']==='kebun'),'planned_amount'))/1e6,1,',','.') ?></td>
                        <td class="text-end"><?= number_format(array_sum(array_column(array_filter($all_lines,fn($l)=>$l['unit_type']==='kebun'),'actual_amount'))/1e6,1,',','.') ?></td>
                        <td class="text-end fw-bold"><?= number_format(array_sum(array_column(array_filter($all_lines,fn($l)=>$l['unit_type']==='pks'),'planned_amount'))/1e6,1,',','.') ?></td>
                        <td class="text-end"><?= number_format(array_sum(array_column(array_filter($all_lines,fn($l)=>$l['unit_type']==='pks'),'actual_amount'))/1e6,1,',','.') ?></td>
                        <td class="text-end fw-bold"><?= number_format($sum_plan/1e6,1,',','.') ?></td>
                        <td class="text-end <?= $vt>2?'text-warning':($vt<-2?'text-info':'') ?>">
                            <?= ($vt>=0?'+':'').number_format($vt,1,',','.') ?>%
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Toast -->
<div id="toastCp" class="toast align-items-center border-0 no-print" role="alert">
    <div class="d-flex">
        <div class="toast-body" id="toastMsg"></div>
        <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
</div>

<!-- Modal: Add / Edit Line -->
<div class="modal fade" id="modalLine" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header py-2 bg-cp text-white" id="modalLineHeader">
                <h6 class="modal-title" id="modalLineTitle"><i class="bi bi-plus-circle me-1"></i>Tambah Baris Biaya</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formLine">
                    <input type="hidden" name="id"         id="lineId"    value="0">
                    <input type="hidden" name="year"       id="lineYear"  value="<?= $year ?>">
                    <input type="hidden" name="company_id" id="lineCo"    value="<?= $COMPANY_ID ?>">
                    <div class="row g-2">
                        <div class="col-sm-3">
                            <label class="form-label-sm">Unit <span class="text-danger">*</span></label>
                            <select name="unit_type" id="lineUnitType" class="form-select form-select-sm">
                                <option value="kebun">🌿 Kebun</option>
                                <option value="pks">⚙️ PKS</option>
                                <option value="nursery">🌱 Nursery</option>
                                <option value="overhead">🏢 Overhead</option>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label-sm">Kategori <span class="text-danger">*</span></label>
                            <select name="cost_category" id="lineCat" class="form-select form-select-sm">
                                <option value="labor">Tenaga Kerja</option>
                                <option value="fertilizer">Pupuk</option>
                                <option value="chemical">Bahan Kimia</option>
                                <option value="fuel">BBM &amp; Pelumas</option>
                                <option value="maintenance">Pemeliharaan</option>
                                <option value="depreciation">Penyusutan</option>
                                <option value="overhead">Overhead</option>
                                <option value="other">Lainnya</option>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label-sm">Item Biaya <span class="text-danger">*</span></label>
                            <input type="text" name="cost_item" id="lineCostItem"
                                   class="form-control form-control-sm" placeholder="e.g. Panen TBS" required>
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label-sm">Urutan</label>
                            <input type="number" name="sort_order" id="lineSortOrder"
                                   class="form-control form-control-sm" value="0">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label-sm">Satuan</label>
                            <input type="text" name="unit" id="lineUnit"
                                   class="form-control form-control-sm" placeholder="HK / kg / liter / ls">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label-sm">Volume</label>
                            <input type="number" name="volume" id="lineVolume"
                                   class="form-control form-control-sm" step="0.01" min="0" value="1">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label-sm">Harga / Satuan (Rp)</label>
                            <input type="number" name="unit_price" id="lineUnitPrice"
                                   class="form-control form-control-sm" step="100" min="0" value="0">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label-sm">Rencana (otomatis: Vol × Harga)</label>
                            <input type="text" id="linePlannedDisplay"
                                   class="form-control form-control-sm bg-light fw-semibold" readonly value="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label-sm">Aktual Realisasi (Rp)</label>
                            <input type="number" name="actual_amount" id="lineActual"
                                   class="form-control form-control-sm" step="1000" min="0" value="0">
                        </div>
                        <!-- Monthly distribution -->
                        <div class="col-12">
                            <label class="form-label-sm text-muted">
                                Distribusi Bulanan (Rp) — kosong = otomatis rata
                                <button type="button" id="btnAutoSpread" class="btn btn-xs btn-outline-secondary ms-2 py-0 px-1" style="font-size:.72rem;">
                                    <i class="bi bi-distribute-horizontal"></i> Rata
                                </button>
                                <button type="button" id="btnPeakSpread" class="btn btn-xs btn-outline-info ms-1 py-0 px-1" style="font-size:.72rem;">
                                    <i class="bi bi-graph-up"></i> Puncak
                                </button>
                            </label>
                            <div class="row g-1">
                                <?php foreach ($month_cols as $i => $mc): ?>
                                <div class="col-6 col-sm-4 col-md-2 col-lg-1" style="min-width:80px;">
                                    <label class="form-label-sm text-muted"><?= $months_id[$i] ?></label>
                                    <input type="number" name="<?= $mc ?>" id="lineM<?= $mc ?>"
                                           class="form-control form-control-sm line-month-input"
                                           step="1000" min="0" value="0">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label-sm">Catatan / Keterangan</label>
                            <input type="text" name="notes" id="lineNotes"
                                   class="form-control form-control-sm" maxlength="255"
                                   placeholder="Opsional — akan ditampilkan sebagai tooltip">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                <button type="button" id="btnSaveLine" class="btn btn-cp btn-sm">
                    <i class="bi bi-save me-1"></i>Simpan
                </button>
                <span id="lineSpinner" class="d-none ms-1">
                    <span class="spinner-border spinner-border-sm text-info"></span>
                </span>
            </div>
        </div>
    </div>
</div>

<?php
echo '</main></div></div>';
echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>';
?>
<script>
(function () {
    const YEAR       = <?= $year ?>;
    const COMPANY_ID = <?= $COMPANY_ID ?>;
    const monthKeys  = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
    // Puncak panen weights (Mar-Jun and Sep-Nov heavy)
    const PEAK_W     = [1,1,3,3,3,3,2,1,3,3,3,2];

    // ── Toast ────────────────────────────────────────────────────────────────
    function showToast(msg, ok = true) {
        const el = document.getElementById('toastMsg');
        el.innerHTML = (ok
            ? '<i class="bi bi-check-circle-fill text-success me-1"></i>'
            : '<i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>') + msg;
        bootstrap.Toast.getOrCreateInstance(document.getElementById('toastCp')).show();
    }

    // ── Assumptions panel ────────────────────────────────────────────────────
    function togglePanel() {
        document.getElementById('asumsiPanel').classList.toggle('show');
    }
    ['btnToggleAsumsi','btnToggleAsumsi2'].forEach(id =>
        document.getElementById(id).addEventListener('click', togglePanel));
    document.getElementById('btnCancelAsumsi').addEventListener('click', () =>
        document.getElementById('asumsiPanel').classList.remove('show'));

    document.getElementById('btnSaveAsumsi').addEventListener('click', function () {
        const spinner = document.getElementById('asumsiSpinner');
        spinner.classList.remove('d-none');
        this.disabled = true;
        const fd = new FormData(document.getElementById('formAsumsi'));
        // pct fields: form sends "8.0" → needs "0.08"
        ['overhead_pct_kebun','overhead_pct_pks'].forEach(k =>
            fd.set(k, (parseFloat(fd.get(k)) / 100).toFixed(4)));
        fd.set('action', 'save_assumptions');
        fetch('ajax/cost_plan.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                spinner.classList.add('d-none');
                this.disabled = false;
                if (!res.ok) { showToast('Error: ' + (res.error || 'Unknown'), false); return; }
                showToast('Asumsi disimpan &amp; biaya dihitung ulang. Memuat ulang…');
                setTimeout(() => window.location.reload(), 1200);
            })
            .catch(err => {
                spinner.classList.add('d-none');
                this.disabled = false;
                showToast('Gagal: ' + err.message, false);
            });
    });

    // ── Line Modal ───────────────────────────────────────────────────────────
    const modalLineEl = document.getElementById('modalLine');
    const modalLine   = new bootstrap.Modal(modalLineEl);

    function recalcPlanned() {
        const vol = parseFloat(document.getElementById('lineVolume').value)    || 0;
        const upr = parseFloat(document.getElementById('lineUnitPrice').value) || 0;
        const pl  = vol * upr;
        document.getElementById('linePlannedDisplay').value =
            pl > 0 ? 'Rp ' + pl.toLocaleString('id-ID', {maximumFractionDigits: 0}) : '0';
    }
    document.getElementById('lineVolume').addEventListener('input',    recalcPlanned);
    document.getElementById('lineUnitPrice').addEventListener('input', recalcPlanned);

    // Auto-spread: even
    document.getElementById('btnAutoSpread').addEventListener('click', () => {
        const pl = parseFloat(document.getElementById('lineVolume').value || 1)
                 * parseFloat(document.getElementById('lineUnitPrice').value || 0);
        const base = Math.round(pl / 12);
        let rem    = Math.round(pl) - base * 12;
        monthKeys.forEach((m, i) => {
            document.getElementById('lineM' + m).value = base + (i === 11 ? rem : 0);
        });
    });
    // Peak-spread
    document.getElementById('btnPeakSpread').addEventListener('click', () => {
        const pl     = Math.round(
            parseFloat(document.getElementById('lineVolume').value || 1)
          * parseFloat(document.getElementById('lineUnitPrice').value || 0));
        const sumW   = PEAK_W.reduce((a, b) => a + b, 0);
        const vals   = PEAK_W.map(w => Math.round(pl * w / sumW));
        vals[11]    += pl - vals.reduce((a,b)=>a+b,0); // rounding fix
        monthKeys.forEach((m, i) => document.getElementById('lineM' + m).value = vals[i]);
    });

    function clearLineForm(unitType) {
        document.getElementById('lineId').value        = '0';
        document.getElementById('lineUnitType').value  = unitType || 'kebun';
        document.getElementById('lineCat').value       = 'labor';
        document.getElementById('lineCostItem').value  = '';
        document.getElementById('lineUnit').value      = '';
        document.getElementById('lineVolume').value    = '1';
        document.getElementById('lineUnitPrice').value = '0';
        document.getElementById('lineActual').value    = '0';
        document.getElementById('lineSortOrder').value = '0';
        document.getElementById('lineNotes').value     = '';
        monthKeys.forEach(m => { document.getElementById('lineM' + m).value = '0'; });
        recalcPlanned();
    }

    // Open for Add — inherit current tab as default unit_type
    document.getElementById('btnAddLine').addEventListener('click', () => {
        const tab = new URLSearchParams(window.location.search).get('tab') || 'kebun';
        document.getElementById('modalLineTitle').innerHTML =
            '<i class="bi bi-plus-circle me-1"></i>Tambah Baris Biaya';
        document.getElementById('modalLineHeader').className = 'modal-header py-2 bg-cp text-white';
        clearLineForm(tab === 'pks' ? 'pks' : 'kebun');
        modalLine.show();
    });

    // Open for Edit
    function openEditLine(id) {
        fetch(`ajax/cost_plan.php?action=get_lines&year=${YEAR}&company_id=${COMPANY_ID}`)
            .then(r => r.json())
            .then(res => {
                if (!res.ok) return;
                const l = res.data.find(x => x.id == id);
                if (!l) return;
                document.getElementById('modalLineTitle').innerHTML =
                    '<i class="bi bi-pencil me-1"></i>Edit Baris Biaya';
                document.getElementById('modalLineHeader').className =
                    'modal-header py-2 bg-warning text-dark';
                document.getElementById('lineId').value        = l.id;
                document.getElementById('lineUnitType').value  = l.unit_type;
                document.getElementById('lineCat').value       = l.cost_category;
                document.getElementById('lineCostItem').value  = l.cost_item;
                document.getElementById('lineUnit').value      = l.unit      || '';
                document.getElementById('lineVolume').value    = l.volume;
                document.getElementById('lineUnitPrice').value = l.unit_price;
                document.getElementById('lineActual').value    = l.actual_amount;
                document.getElementById('lineSortOrder').value = l.sort_order;
                document.getElementById('lineNotes').value     = l.notes     || '';
                monthKeys.forEach(m => {
                    const el = document.getElementById('lineM' + m);
                    if (el) el.value = l[m] || 0;
                });
                recalcPlanned();
                modalLine.show();
            });
    }

    // Save line
    document.getElementById('btnSaveLine').addEventListener('click', function () {
        const spinner = document.getElementById('lineSpinner');
        spinner.classList.remove('d-none');
        this.disabled = true;
        const fd = new FormData(document.getElementById('formLine'));
        fd.set('action', 'save_line');
        fetch('ajax/cost_plan.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                spinner.classList.add('d-none');
                this.disabled = false;
                if (!res.ok) { showToast('Error: ' + (res.error || 'Unknown'), false); return; }
                modalLine.hide();
                showToast('Baris disimpan. Memuat ulang…');
                setTimeout(() => window.location.reload(), 900);
            })
            .catch(err => {
                spinner.classList.add('d-none');
                this.disabled = false;
                showToast('Gagal: ' + err.message, false);
            });
    });

    // Delete line
    function deleteLine(id) {
        if (!confirm('Hapus baris ini? Tindakan tidak dapat dibatalkan.')) return;
        const fd = new FormData();
        fd.set('action', 'delete_line');
        fd.set('id',         id);
        fd.set('year',       YEAR);
        fd.set('company_id', COMPANY_ID);
        fetch('ajax/cost_plan.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.ok) { showToast('Error: ' + (res.error || 'Unknown'), false); return; }
                showToast('Baris dihapus.');
                setTimeout(() => window.location.reload(), 700);
            })
            .catch(err => showToast('Gagal: ' + err.message, false));
    }

    // Bind edit / delete buttons
    document.querySelectorAll('.btn-edit-line').forEach(btn =>
        btn.addEventListener('click', () => openEditLine(btn.dataset.id)));
    document.querySelectorAll('.btn-del-line').forEach(btn =>
        btn.addEventListener('click', () => deleteLine(btn.dataset.id)));

})();
</script>
<?php echo '</body></html>'; ?>
