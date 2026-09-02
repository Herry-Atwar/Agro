<?php
/**
 * Taksasi Panen (Production Estimate — FFB Harvest)
 * erpAgro - Agrobusiness Solution
 *
 * Two scopes:
 *   Monthly — taksasi per blok per bulan  (RKAP decomposition)
 *   Daily   — taksasi per blok per tanggal (pre-harvest morning meeting)
 *
 * Actuals pulled live from harvest_realizations via vw_taksasi_vs_realisasi.
 */
require_once 'config/database.php';
require_once 'includes/functions.php';

$db  = getDB();
$me  = $_SESSION['username'] ?? $_SESSION['name'] ?? 'system';

// ── Schema readiness check ────────────────────────────────────────────────────
$schema_ok = false;
try {
    $db->query("SELECT 1 FROM taksasi_panen LIMIT 1");
    $schema_ok = true;
} catch (PDOException $e) { /* not yet migrated */ }

// ── POST actions ──────────────────────────────────────────────────────────────
if (is_post() && $schema_ok) {
    $action = post('action');
    $scope  = post('scope', 'monthly');

    if ($action === 'add') {
        try {
            $block_id = (int) post('block_id');
            $yr       = (int) post('period_year');
            $mo       = (int) post('period_month');
            $day      = ($scope === 'daily') ? (post('taksasi_date') ?: null) : null;
            $round    = post('harvesting_round') ?: null;
            $area     = (float) post('area_ha');
            $yf       = post('yield_factor') !== '' ? (float) post('yield_factor') : null;
            $est_kg   = (float) post('estimated_kg');
            $abw      = post('avg_bunch_weight_kg') !== '' ? (float) post('avg_bunch_weight_kg') : null;
            $est_bun  = ($abw && $abw > 0 && $est_kg > 0) ? (int) round($est_kg / $abw) : null;

            // Snapshot division + BU from block
            $brow = $db->prepare("
                SELECT b.division_id, d.business_unit_id, b.plant_age
                FROM blocks b
                LEFT JOIN divisions d ON b.division_id = d.division_id
                WHERE b.block_id = ? LIMIT 1
            ");
            $brow->execute([$block_id]);
            $br = $brow->fetch() ?: [];

            $db->prepare("
                INSERT INTO taksasi_panen
                    (block_id, division_id, business_unit_id,
                     estimate_scope, period_year, period_month, taksasi_date, harvesting_round,
                     area_ha, plant_age_years, yield_factor, estimated_kg, estimated_bunches,
                     avg_bunch_weight_kg, status, supervisor, assigned_team, notes, created_by)
                VALUES (?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?)
            ")->execute([
                $block_id, $br['division_id'] ?? null, $br['business_unit_id'] ?? null,
                $scope, $yr, $mo, $day, $round,
                $area, $br['plant_age'] ?? null, $yf, $est_kg, $est_bun,
                $abw, post('status') ?: 'Draft',
                post('supervisor') ?: null, post('assigned_team') ?: null,
                post('notes') ?: null, $me,
            ]);
            set_message('success', 'Taksasi berhasil disimpan.');
        } catch (PDOException $e) {
            set_message('error', 'Gagal simpan: ' . $e->getMessage());
        }
        header('Location: taksasi_panen.php?tab=' . $scope . '&year=' . ($yr ?? date('Y')) . '&month=' . ($mo ?? date('m')));
        exit;
    }

    if ($action === 'approve') {
        try {
            $db->prepare("
                UPDATE taksasi_panen
                SET status='Approved', approved_by=?, approved_at=NOW()
                WHERE taksasi_id=?
            ")->execute([$me, (int) post('taksasi_id')]);
            set_message('success', 'Taksasi disetujui.');
        } catch (PDOException $e) { set_message('error', $e->getMessage()); }
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    }

    if ($action === 'delete') {
        try {
            $db->prepare("DELETE FROM taksasi_panen WHERE taksasi_id=?")
               ->execute([(int) post('taksasi_id')]);
            set_message('success', 'Taksasi dihapus.');
        } catch (PDOException $e) { set_message('error', $e->getMessage()); }
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$f_tab   = get('tab', 'monthly');
$f_year  = (int) get('year', date('Y'));
$f_month = (int) get('month', date('m'));
$f_bu    = get('bu', '');
$f_day   = get('day', '');

// ── Session company/BU filter ─────────────────────────────────────────────────
$session_company_id = $_SESSION['company_id'] ?? null;
$session_bu_id      = $_SESSION['business_unit_id'] ?? null;

// Bangun kondisi company untuk query
$co_where_bu  = '';   // untuk tabel business_units
$co_params_bu = [];
if ($session_bu_id) {
    $co_where_bu  = ' AND bu.business_unit_id = ?';
    $co_params_bu = [$session_bu_id];
} elseif ($session_company_id) {
    $co_where_bu  = ' AND bu.company_id = ?';
    $co_params_bu = [$session_company_id];
}

// ── Dropdowns ─────────────────────────────────────────────────────────────────
$bus_stmt = $db->prepare("
    SELECT bu.business_unit_id, bu.unit_name
    FROM business_units bu
    WHERE bu.status='Active' $co_where_bu
    ORDER BY bu.unit_name
");
$bus_stmt->execute($co_params_bu);
$bus = $bus_stmt->fetchAll();

$divs_stmt = $db->prepare("
    SELECT d.division_id, d.division_name, d.business_unit_id
    FROM divisions d
    JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    WHERE d.status='Active' $co_where_bu
    ORDER BY d.division_name
");
$divs_stmt->execute($co_params_bu);
$divs = $divs_stmt->fetchAll();

// Blocks for Add modal (TM status preferred)
try {
    $blk_stmt = $db->prepare("
        SELECT b.block_id, b.block_code, b.block_name, b.area, b.plant_age, b.status AS block_status,
               d.division_id, d.division_name, bu.business_unit_id, bu.unit_name
        FROM blocks b
        LEFT JOIN divisions d       ON b.division_id       = d.division_id
        LEFT JOIN business_units bu ON d.business_unit_id  = bu.business_unit_id
        WHERE 1=1 $co_where_bu
        ORDER BY bu.unit_name, d.division_name, b.block_code
    ");
    $blk_stmt->execute($co_params_bu);
    $blocks = $blk_stmt->fetchAll();
} catch (PDOException $e) { $blocks = []; }

// ── Data queries (only when schema ready) ─────────────────────────────────────
$monthly = $daily = $avail_days = [];
$m_est = $m_act = $d_est = $d_act = 0;

if ($schema_ok) {
    // Bangun kondisi company untuk taksasi_panen (join lewat blocks → divisions → business_units)
    $co_where_tp  = '';   // kondisi pakai alias "t" (untuk query yang ada JOIN taksasi_panen t)
    $co_where_tp2 = '';   // kondisi pakai alias "tp" tanpa alias (untuk query langsung ke taksasi_panen)
    $co_params_tp = [];
    if ($session_bu_id) {
        $co_where_tp  = ' AND t.business_unit_id = ?';
        $co_where_tp2 = ' AND business_unit_id = ?';
        $co_params_tp = [$session_bu_id];
    } elseif ($session_company_id) {
        $co_where_tp  = ' AND t.business_unit_id IN (SELECT business_unit_id FROM business_units WHERE company_id = ' . (int)$session_company_id . ')';
        $co_where_tp2 = ' AND business_unit_id IN (SELECT business_unit_id FROM business_units WHERE company_id = ' . (int)$session_company_id . ')';
        $co_params_tp = [];
    }

    $m_p  = [$f_year, $f_month];
    $m_bu = $co_where_tp;
    array_push($m_p, ...$co_params_tp);
    if ($f_bu) { $m_bu .= ' AND t.business_unit_id=?'; $m_p[] = (int)$f_bu; }

    $monthly = $db->prepare("
        SELECT v.* FROM vw_taksasi_vs_realisasi v
        JOIN taksasi_panen t ON t.taksasi_id = v.taksasi_id
        WHERE t.estimate_scope='monthly' AND t.period_year=? AND t.period_month=? $m_bu
        ORDER BY v.unit_name, v.division_name, v.block_code
    ");
    $monthly->execute($m_p);
    $monthly = $monthly->fetchAll();

    $m_est = array_sum(array_column($monthly, 'estimated_kg'));
    $m_act = array_sum(array_column($monthly, 'actual_kg'));

    $d_p   = [$f_year, $f_month];
    $d_day = '';
    $d_bux = $co_where_tp;
    array_push($d_p, ...$co_params_tp);
    if ($f_day) { $d_day = ' AND t.taksasi_date=?'; $d_p[] = $f_day; }
    if ($f_bu)  { $d_bux .= ' AND t.business_unit_id=?'; $d_p[] = (int)$f_bu; }

    $daily = $db->prepare("
        SELECT v.* FROM vw_taksasi_vs_realisasi v
        JOIN taksasi_panen t ON t.taksasi_id = v.taksasi_id
        WHERE t.estimate_scope='daily' AND t.period_year=? AND t.period_month=? $d_day $d_bux
        ORDER BY v.taksasi_date, v.unit_name, v.block_code
    ");
    $daily->execute($d_p);
    $daily = $daily->fetchAll();

    $d_est = array_sum(array_column($daily, 'estimated_kg'));
    $d_act = array_sum(array_column($daily, 'actual_kg'));

    $avail_days_p = [$f_year, $f_month];
    array_push($avail_days_p, ...$co_params_tp);
    $avail_days = $db->prepare("
        SELECT DISTINCT taksasi_date FROM taksasi_panen
        WHERE estimate_scope='daily' AND period_year=? AND period_month=? $co_where_tp2
        ORDER BY taksasi_date
    ");
    $avail_days->execute($avail_days_p);
    $avail_days = $avail_days->fetchAll(PDO::FETCH_COLUMN);
}

$month_names = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'];
$status_color = [
    'Draft'=>'secondary','Approved'=>'success',
    'In Progress'=>'primary','Completed'=>'dark','Cancelled'=>'danger',
];
$round_color = [
    'Round 1'=>'success','Round 2'=>'primary','Round 3'=>'warning','Round 4'=>'info',
];

$page_title = __('pt_taksasi_panen');
require_once 'includes/header.php';
?>

<style>
.tk-hdr  { border-bottom-color: #2e7d32 !important; }
.btn-tk  { background: #2e7d32; color: #fff; }
.btn-tk:hover { background: #1b5e20; color: #fff; }
.section-hdr  { background: #2e7d32; color: #fff; font-size: 0.78rem; font-weight: 700; padding: 6px 12px; }
.section-hdr-d{ background: #5d4037; color: #fff; font-size: 0.78rem; font-weight: 700; padding: 6px 12px; }
.pct-good { color: #166534; font-weight: 700; }
.pct-warn { color: #854d0e; font-weight: 700; }
.pct-bad  { color: #991b1b; font-weight: 700; }
.var-pos  { color: #166534; }
.var-neg  { color: #991b1b; }
.nav-tabs .nav-link.active { border-bottom: 3px solid #2e7d32; font-weight: 700; }
@media print { .no-print { display: none !important; } }
</style>

<div class="content-wrapper">

    <div class="page-header tk-hdr d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1 style="color:#2e7d32;"><i class="bi bi-clipboard2-data me-2"></i>Taksasi Panen</h1>
            <p class="text-muted mb-0">Estimasi produksi FFB per blok — bulanan &amp; harian</p>
        </div>
        <div class="d-flex gap-2 no-print">
            <?php if ($schema_ok): ?>
            <button class="btn btn-tk" data-bs-toggle="modal" data-bs-target="#modalAdd">
                <i class="bi bi-plus-circle me-1"></i>Tambah Taksasi
            </button>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <?php display_message(); ?>

    <?php if (!$schema_ok): ?>
    <div class="alert alert-warning d-flex align-items-start gap-3">
        <i class="bi bi-exclamation-triangle-fill fs-4 mt-1"></i>
        <div>
            <strong>Migrasi database belum dijalankan.</strong><br>
            Tabel <code>taksasi_panen</code> belum ada di database.<br>
            Jalankan via phpMyAdmin:
            <code class="d-block mt-1">agro/database/taksasi_schema.sql</code>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="get" class="row g-2 mb-3 no-print align-items-end">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($f_tab); ?>">
        <div class="col-auto">
            <select name="year" class="form-select form-select-sm">
                <?php for ($y = date('Y')+1; $y >= 2024; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y===$f_year?'selected':''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="month" class="form-select form-select-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m===$f_month?'selected':''; ?>><?php echo $month_names[$m]; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="bu" class="form-select form-select-sm">
                <option value="">— Semua Estate —</option>
                <?php foreach ($bus as $bu): ?>
                    <option value="<?php echo $bu['business_unit_id']; ?>" <?php echo $bu['business_unit_id']==$f_bu?'selected':''; ?>>
                        <?php echo htmlspecialchars($bu['unit_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($f_tab === 'daily'): ?>
        <div class="col-auto">
            <select name="day" class="form-select form-select-sm">
                <option value="">— Semua Hari —</option>
                <?php foreach ($avail_days as $ad): ?>
                    <option value="<?php echo $ad; ?>" <?php echo $ad===$f_day?'selected':''; ?>>
                        <?php echo date('d M Y', strtotime($ad)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-auto">
            <button class="btn btn-sm btn-secondary"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <?php if ($f_bu || $f_day): ?>
        <div class="col-auto">
            <a href="taksasi_panen.php?tab=<?php echo $f_tab; ?>&year=<?php echo $f_year; ?>&month=<?php echo $f_month; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a>
        </div>
        <?php endif; ?>
    </form>

    <!-- KPI Cards -->
    <?php
    $tab_est = $f_tab === 'monthly' ? $m_est : $d_est;
    $tab_act = $f_tab === 'monthly' ? $m_act : $d_act;
    $tab_pct = $tab_est > 0 ? round($tab_act / $tab_est * 100, 1) : null;
    $tab_var = $tab_act - $tab_est;
    $cnt     = $f_tab === 'monthly' ? count($monthly) : count($daily);
    ?>
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card text-center" style="border-left:4px solid #2e7d32;">
                <div class="card-body py-2">
                    <div class="fw-bold" style="color:#2e7d32;font-size:1.3rem;"><?php echo number_format($tab_est/1000, 1); ?> <small class="fs-6 fw-normal text-muted">ton</small></div>
                    <small class="text-muted">Taksasi <?php echo $f_tab==='monthly'?'Bulanan':'Harian'; ?></small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center" style="border-left:4px solid #1565c0;">
                <div class="card-body py-2">
                    <div class="fw-bold" style="color:#1565c0;font-size:1.3rem;"><?php echo number_format($tab_act/1000, 1); ?> <small class="fs-6 fw-normal text-muted">ton</small></div>
                    <small class="text-muted">Realisasi</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center" style="border-left:4px solid <?php echo $tab_var>=0?'#2e7d32':'#c62828'; ?>;">
                <div class="card-body py-2">
                    <div class="fw-bold" style="color:<?php echo $tab_var>=0?'#2e7d32':'#c62828'; ?>;font-size:1.3rem;"><?php echo ($tab_var>=0?'+':'').number_format($tab_var/1000,1); ?> <small class="fs-6 fw-normal text-muted">ton</small></div>
                    <small class="text-muted">Variance</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center" style="border-left:4px solid #f57c00;">
                <div class="card-body py-2">
                    <?php if ($tab_pct !== null): $pc = $tab_pct>=100?'#2e7d32':($tab_pct>=85?'#f57c00':'#c62828'); ?>
                    <div class="fw-bold" style="color:<?php echo $pc; ?>;font-size:1.3rem;"><?php echo $tab_pct; ?>%</div>
                    <?php else: ?><div class="fw-bold text-muted" style="font-size:1.3rem;">—</div><?php endif; ?>
                    <small class="text-muted">Pencapaian &nbsp;|&nbsp; <?php echo $cnt; ?> blok</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-0 no-print">
        <li class="nav-item">
            <a class="nav-link <?php echo $f_tab==='monthly'?'active':''; ?>"
               href="?tab=monthly&year=<?php echo $f_year; ?>&month=<?php echo $f_month; ?>&bu=<?php echo $f_bu; ?>">
                <i class="bi bi-calendar3 me-1"></i>Taksasi Bulanan
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $f_tab==='daily'?'active':''; ?>"
               href="?tab=daily&year=<?php echo $f_year; ?>&month=<?php echo $f_month; ?>&bu=<?php echo $f_bu; ?>">
                <i class="bi bi-calendar-day me-1"></i>Taksasi Harian
            </a>
        </li>
    </ul>

    <!-- ══ MONTHLY TAB ══════════════════════════════════════════════════════════ -->
    <?php if ($f_tab === 'monthly'): ?>
    <div class="card mb-3" style="border-top:none;border-top-left-radius:0;">
        <div class="section-hdr">
            <i class="bi bi-table me-1"></i>Taksasi Bulanan — <?php echo $month_names[$f_month].' '.$f_year; ?>
            <span class="badge bg-light text-dark ms-2"><?php echo count($monthly); ?> blok</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover table-bordered mb-0" style="font-size:0.8rem;">
                <thead class="table-dark">
                    <tr>
                        <th>Afdeling</th><th>Blok</th><th>Status</th>
                        <th class="text-end">Luas (ha)</th>
                        <th class="text-end">Umur (th)</th>
                        <th class="text-end">YF (ton/ha)</th>
                        <th class="text-end">Taksasi (kg)</th>
                        <th class="text-end">Est. Janjang</th>
                        <th class="text-end">Realisasi (kg)</th>
                        <th class="text-end">Variance</th>
                        <th class="text-end">%</th>
                        <th>Status Taksasi</th>
                        <th class="no-print">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($monthly)): ?>
                    <tr><td colspan="13" class="text-center text-muted py-4">
                        Belum ada taksasi bulanan untuk <?php echo $month_names[$f_month].' '.$f_year; ?>.
                        <?php if ($schema_ok): ?><a href="#" data-bs-toggle="modal" data-bs-target="#modalAdd" class="ms-2">Tambah sekarang</a><?php endif; ?>
                    </td></tr>
                <?php else: foreach ($monthly as $r):
                    $pct = $r['achievement_pct'];
                    $var = (float)$r['variance_kg'];
                    $pc  = $pct===null?'pct-na':($pct>=100?'pct-good':($pct>=85?'pct-warn':'pct-bad'));
                    $vc  = $var>=0?'var-pos':'var-neg';
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['division_name']??'—'); ?></td>
                        <td><strong><?php echo htmlspecialchars($r['block_code']); ?></strong>
                            <small class="text-muted d-block"><?php echo htmlspecialchars($r['block_name']); ?></small>
                        </td>
                        <td><span class="badge bg-<?php echo ['TM'=>'success','TBM'=>'warning','TR'=>'danger'][$r['block_status']]??'secondary'; ?>"><?php echo $r['block_status']; ?></span></td>
                        <td class="text-end text-muted"><?php echo number_format($r['area_ha'],2); ?></td>
                        <td class="text-end text-muted"><?php echo $r['plant_age_years']??'—'; ?></td>
                        <td class="text-end text-muted"><?php echo $r['yield_factor']?number_format($r['yield_factor'],3):'—'; ?></td>
                        <td class="text-end fw-bold"><?php echo number_format($r['estimated_kg'],0); ?></td>
                        <td class="text-end text-muted"><?php echo $r['estimated_bunches']?number_format($r['estimated_bunches'],0):'—'; ?></td>
                        <td class="text-end"><?php echo $r['actual_kg']>0?'<strong>'.number_format($r['actual_kg'],0).'</strong>':'<span class="text-muted">—</span>'; ?></td>
                        <td class="text-end <?php echo $vc; ?>"><?php echo $r['actual_kg']>0?($var>=0?'+':'').number_format($var,0):'<span class="text-muted">—</span>'; ?></td>
                        <td class="text-end"><?php echo $pct!==null?'<span class="'.$pc.'">'.$pct.'%</span>':'<span class="text-muted">—</span>'; ?></td>
                        <td><span class="badge bg-<?php echo $status_color[$r['status']]??'secondary'; ?>"><?php echo $r['status']; ?></span></td>
                        <td class="no-print" style="white-space:nowrap;">
                            <?php if ($r['status']==='Draft'): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="taksasi_id" value="<?php echo $r['taksasi_id']; ?>">
                                <input type="hidden" name="scope" value="monthly">
                                <button class="btn btn-sm btn-success py-0 px-1" title="Approve"><i class="bi bi-check-circle"></i></button>
                            </form>
                            <?php endif; ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus taksasi blok <?php echo htmlspecialchars($r['block_code'],ENT_QUOTES); ?>?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="taksasi_id" value="<?php echo $r['taksasi_id']; ?>">
                                <input type="hidden" name="scope" value="monthly">
                                <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Hapus"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($monthly)): ?>
                <tfoot>
                    <tr class="table-dark fw-bold">
                        <td colspan="6">TOTAL <?php echo $month_names[$f_month].' '.$f_year; ?></td>
                        <td class="text-end"><?php echo number_format($m_est,0); ?> kg</td>
                        <td class="text-end"><?php echo number_format(array_sum(array_column($monthly,'estimated_bunches')),0); ?></td>
                        <td class="text-end"><?php echo number_format($m_act,0); ?> kg</td>
                        <td class="text-end <?php echo ($m_act-$m_est)>=0?'var-pos':'var-neg'; ?>">
                            <?php $tv=$m_act-$m_est; echo ($tv>=0?'+':'').number_format($tv,0); ?>
                        </td>
                        <td class="text-end"><?php if ($m_est>0): $tp=round($m_act/$m_est*100,1); echo '<span class="'.($tp>=100?'text-success':($tp>=85?'text-warning':'text-danger')).'">'.$tp.'%</span>'; endif; ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
            </div>
        </div>
    </div>

    <!-- ══ DAILY TAB ════════════════════════════════════════════════════════════ -->
    <?php else: ?>
    <div class="card mb-3" style="border-top:none;border-top-left-radius:0;">
        <div class="section-hdr-d">
            <i class="bi bi-calendar-day me-1"></i>
            Taksasi Harian — <?php echo $f_day?date('d M Y',strtotime($f_day)):$month_names[$f_month].' '.$f_year; ?>
            <span class="badge bg-light text-dark ms-2"><?php echo count($daily); ?> entri</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover table-bordered mb-0" style="font-size:0.8rem;">
                <thead class="table-dark">
                    <tr>
                        <th>Tanggal</th><th>Afdeling</th><th>Blok</th><th>Rotasi</th>
                        <th class="text-end">Taksasi (kg)</th>
                        <th class="text-end">Est. Janjang</th>
                        <th class="text-end">Realisasi (kg)</th>
                        <th class="text-end">Variance</th>
                        <th class="text-end">%</th>
                        <th>Status</th>
                        <th class="no-print">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($daily)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">
                        Belum ada taksasi harian untuk periode ini.
                        <?php if ($schema_ok): ?><a href="#" data-bs-toggle="modal" data-bs-target="#modalAdd" class="ms-2">Tambah sekarang</a><?php endif; ?>
                    </td></tr>
                <?php else:
                    $prev_day = null;
                    foreach ($daily as $r):
                        $pct = $r['achievement_pct'];
                        $var = (float)$r['variance_kg'];
                        $pc  = $pct===null?'pct-na':($pct>=100?'pct-good':($pct>=85?'pct-warn':'pct-bad'));
                        $vc  = $var>=0?'var-pos':'var-neg';
                        $is_new = $r['taksasi_date'] !== $prev_day;
                        $prev_day = $r['taksasi_date'];
                ?>
                    <?php if ($is_new && !$f_day): ?>
                    <tr class="table-secondary">
                        <td colspan="11" class="fw-bold" style="font-size:0.75rem;padding:3px 8px;">
                            <i class="bi bi-calendar3 me-1"></i><?php echo date('l, d M Y',strtotime($r['taksasi_date'])); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td><?php echo date('d M',strtotime($r['taksasi_date'])); ?></td>
                        <td class="text-muted"><?php echo htmlspecialchars($r['division_name']??'—'); ?></td>
                        <td><strong><?php echo htmlspecialchars($r['block_code']); ?></strong></td>
                        <td><?php if ($r['harvesting_round']): ?><span class="badge bg-<?php echo $round_color[$r['harvesting_round']]??'secondary'; ?>"><?php echo $r['harvesting_round']; ?></span><?php else: echo '—'; endif; ?></td>
                        <td class="text-end fw-bold"><?php echo number_format($r['estimated_kg'],1); ?></td>
                        <td class="text-end text-muted"><?php echo $r['estimated_bunches']?number_format($r['estimated_bunches'],0):'—'; ?></td>
                        <td class="text-end"><?php echo $r['actual_kg']>0?'<strong>'.number_format($r['actual_kg'],1).'</strong>':'<span class="text-muted">—</span>'; ?></td>
                        <td class="text-end <?php echo $vc; ?>"><?php echo $r['actual_kg']>0?($var>=0?'+':'').number_format($var,1):'<span class="text-muted">—</span>'; ?></td>
                        <td class="text-end"><?php echo $pct!==null?'<span class="'.$pc.'">'.$pct.'%</span>':'<span class="text-muted">—</span>'; ?></td>
                        <td><span class="badge bg-<?php echo $status_color[$r['status']]??'secondary'; ?>"><?php echo $r['status']; ?></span></td>
                        <td class="no-print" style="white-space:nowrap;">
                            <?php if ($r['status']==='Draft'): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="taksasi_id" value="<?php echo $r['taksasi_id']; ?>">
                                <input type="hidden" name="scope" value="daily">
                                <button class="btn btn-sm btn-success py-0 px-1"><i class="bi bi-check-circle"></i></button>
                            </form>
                            <?php endif; ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus taksasi ini?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="taksasi_id" value="<?php echo $r['taksasi_id']; ?>">
                                <input type="hidden" name="scope" value="daily">
                                <button class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($daily)): ?>
                <tfoot>
                    <tr class="table-dark fw-bold">
                        <td colspan="4">TOTAL</td>
                        <td class="text-end"><?php echo number_format($d_est,1); ?> kg</td>
                        <td class="text-end"><?php echo number_format(array_sum(array_column($daily,'estimated_bunches')),0); ?></td>
                        <td class="text-end"><?php echo number_format($d_act,1); ?> kg</td>
                        <td class="text-end <?php echo ($d_act-$d_est)>=0?'var-pos':'var-neg'; ?>">
                            <?php $dv=$d_act-$d_est; echo ($dv>=0?'+':'').number_format($dv,1); ?>
                        </td>
                        <td class="text-end"><?php if ($d_est>0): $dp=round($d_act/$d_est*100,1); echo '<span class="'.($dp>=100?'text-success':($dp>=85?'text-warning':'text-danger')).'">'.$dp.'%</span>'; endif; ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /content-wrapper -->

<!-- ══ ADD TAKSASI MODAL ════════════════════════════════════════════════════════ -->
<?php if ($schema_ok): ?>
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:#2e7d32;color:#fff;">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i>Tambah Taksasi Panen</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="scope" id="addScope" value="<?php echo htmlspecialchars($f_tab); ?>">
        <div class="modal-body row g-3">

          <!-- Scope toggle -->
          <div class="col-12">
            <div class="btn-group w-100">
              <input type="radio" class="btn-check" name="scope_ui" id="scopeM" value="monthly" <?php echo $f_tab==='monthly'?'checked':''; ?>>
              <label class="btn btn-outline-success" for="scopeM"><i class="bi bi-calendar3 me-1"></i>Bulanan</label>
              <input type="radio" class="btn-check" name="scope_ui" id="scopeD" value="daily" <?php echo $f_tab==='daily'?'checked':''; ?>>
              <label class="btn btn-outline-secondary" for="scopeD" style="border-color:#5d4037;color:#5d4037;"><i class="bi bi-calendar-day me-1"></i>Harian</label>
            </div>
          </div>

          <div class="col-md-3">
            <label class="form-label fw-bold">Tahun *</label>
            <select name="period_year" class="form-select" required>
              <?php for ($y=date('Y')+1;$y>=2024;$y--): ?><option value="<?php echo $y;?>" <?php echo $y===$f_year?'selected':'';?>><?php echo $y;?></option><?php endfor; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-bold">Bulan *</label>
            <select name="period_month" class="form-select" required>
              <?php for ($m=1;$m<=12;$m++): ?><option value="<?php echo $m;?>" <?php echo $m===$f_month?'selected':'';?>><?php echo $month_names[$m];?></option><?php endfor; ?>
            </select>
          </div>
          <div class="col-md-3" id="rowDate" style="<?php echo $f_tab!=='daily'?'display:none;':''; ?>">
            <label class="form-label fw-bold">Tanggal *</label>
            <input type="date" name="taksasi_date" class="form-control" value="<?php echo $f_day?:date('Y-m-d'); ?>">
          </div>
          <div class="col-md-3" id="rowRound" style="<?php echo $f_tab!=='daily'?'display:none;':''; ?>">
            <label class="form-label">Rotasi</label>
            <select name="harvesting_round" class="form-select">
              <option value="">—</option>
              <option>Round 1</option><option>Round 2</option><option>Round 3</option><option>Round 4</option>
            </select>
          </div>

          <div class="col-md-12">
            <label class="form-label fw-bold">Blok *</label>
            <select name="block_id" id="addBlk" class="form-select" required>
              <option value="">— Pilih Blok —</option>
              <?php foreach ($blocks as $bk): ?>
              <option value="<?php echo $bk['block_id']; ?>"
                      data-area="<?php echo $bk['area']; ?>"
                      data-age="<?php echo $bk['plant_age']??''; ?>"
                      data-status="<?php echo $bk['block_status']; ?>">
                <?php echo htmlspecialchars($bk['block_code'].' — '.($bk['unit_name']??'').'/'.($bk['division_name']??'')); ?>
                (<?php echo $bk['block_status']; ?>, <?php echo $bk['plant_age']??'?'; ?> th)
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Block info hints -->
          <div class="col-md-4">
            <label class="form-label text-muted">Luas (ha)</label>
            <input type="number" name="area_ha" id="addArea" class="form-control" step="0.01" min="0" required placeholder="Auto dari blok">
          </div>
          <div class="col-md-4">
            <label class="form-label text-muted">Umur Tanaman (th)</label>
            <input type="text" id="infoAge" class="form-control-plaintext fw-bold ps-2" style="border:1px dashed #ccc;border-radius:4px;" readonly value="—">
          </div>
          <div class="col-md-4">
            <label class="form-label text-muted">Status Blok</label>
            <input type="text" id="infoStatus" class="form-control-plaintext fw-bold ps-2" style="border:1px dashed #ccc;border-radius:4px;" readonly value="—">
          </div>

          <div class="col-md-4">
            <label class="form-label fw-bold">Yield Factor (ton/ha/bulan)</label>
            <input type="number" name="yield_factor" id="addYF" class="form-control" step="0.001" min="0" placeholder="cth: 2.200">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-bold">Taksasi (kg) *</label>
            <input type="number" name="estimated_kg" id="addEst" class="form-control" step="0.01" min="0" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Rata-rata Berat Janjang (kg)</label>
            <input type="number" name="avg_bunch_weight_kg" id="addABW" class="form-control" step="0.1" min="0" placeholder="cth: 21.0">
          </div>
          <div class="col-md-4">
            <label class="form-label text-muted">Est. Janjang (auto)</label>
            <input type="text" id="infoJanjang" class="form-control-plaintext fw-bold ps-2" style="border:1px dashed #ccc;border-radius:4px;" readonly value="—">
          </div>
          <div class="col-md-4">
            <label class="form-label">Supervisor</label>
            <input type="text" name="supervisor" class="form-control" placeholder="Nama mandor / supervisor">
          </div>
          <div class="col-md-4">
            <label class="form-label">Tim</label>
            <input type="text" name="assigned_team" class="form-control" placeholder="cth: Tim A">
          </div>
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="Draft">Draft</option><option value="Approved">Approved</option>
            </select>
          </div>
          <div class="col-md-8">
            <label class="form-label">Catatan</label>
            <input type="text" name="notes" class="form-control" placeholder="Opsional">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-tk"><i class="bi bi-save me-1"></i>Simpan Taksasi</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// Scope toggle
document.querySelectorAll('input[name="scope_ui"]').forEach(function(r){
    r.addEventListener('change', function(){
        var d = this.value==='daily';
        document.getElementById('addScope').value = this.value;
        document.getElementById('rowDate').style.display  = d ? '' : 'none';
        document.getElementById('rowRound').style.display = d ? '' : 'none';
        document.querySelector('input[name="taksasi_date"]').required = d;
    });
});

// Block selection → fill area & info
document.getElementById('addBlk').addEventListener('change', function(){
    var o = this.options[this.selectedIndex];
    if (!o.value) { document.getElementById('addArea').value=''; document.getElementById('infoAge').value='—'; document.getElementById('infoStatus').value='—'; return; }
    document.getElementById('addArea').value = o.dataset.area || '';
    document.getElementById('infoAge').value  = (o.dataset.age||'—') + (o.dataset.age?' th':'');
    document.getElementById('infoStatus').value = o.dataset.status || '—';
    computeEst();
});

// Auto-compute estimated_kg from area × yield_factor × 1000
function computeEst(){
    var a  = parseFloat(document.getElementById('addArea').value)  || 0;
    var yf = parseFloat(document.getElementById('addYF').value)    || 0;
    if (a>0 && yf>0) document.getElementById('addEst').value = (a*yf*1000).toFixed(2);
    computeJanjang();
}
function computeJanjang(){
    var kg  = parseFloat(document.getElementById('addEst').value)  || 0;
    var abw = parseFloat(document.getElementById('addABW').value)  || 0;
    document.getElementById('infoJanjang').value = (kg>0 && abw>0) ? Math.round(kg/abw)+' janjang' : '—';
}
document.getElementById('addArea').addEventListener('input', computeEst);
document.getElementById('addYF').addEventListener('input', computeEst);
document.getElementById('addEst').addEventListener('input', computeJanjang);
document.getElementById('addABW').addEventListener('input', computeJanjang);
</script>

<?php require_once 'includes/footer.php'; ?>
