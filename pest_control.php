<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'config/standards.php';

$db = getDB();

// ─────────────────────────────────────────────────────────────────────────────
// POST handlers
// ─────────────────────────────────────────────────────────────────────────────
if (is_post()) {
    $action = post('action');

    if ($action === 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO pest_control_records
                (work_order_id, block_id, application_date, pest_type, pest_name, severity,
                 pesticide_name, pesticide_type, quantity_used, application_method,
                 area_covered, labor_count, labor_hours, cost, weather_condition, performed_by,
                 supervisor, effectiveness, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                post('work_order_id') ?: null,
                post('block_id'),
                post('application_date'),
                post('pest_type'),
                post('pest_name')           ?: null,
                post('severity')            ?: 'Medium',
                post('pesticide_name')      ?: null,
                post('pesticide_type')      ?: null,
                post('quantity_used')       ?: null,
                post('application_method')  ?: null,
                post('area_covered')        ?: null,
                post('labor_count')         ?: null,
                post('labor_hours')         ?: null,
                post('cost')                ?: null,
                post('weather_condition')   ?: null,
                post('performed_by')        ?: null,
                post('supervisor')          ?: null,
                post('effectiveness')       ?: 'Not Assessed',
                post('status')              ?: 'Planned',
                post('notes')               ?: null,
                'admin',
            ]);
            set_message('success', 'Catatan pengendalian berhasil ditambahkan.');
            redirect('pest_control.php');
        } catch (PDOException $e) {
            set_message('error', 'Gagal menyimpan: ' . $e->getMessage());
        }
    }

    elseif ($action === 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE pest_control_records
                SET work_order_id = ?, block_id = ?, application_date = ?, pest_type = ?,
                    pest_name = ?, severity = ?, pesticide_name = ?, pesticide_type = ?,
                    quantity_used = ?, application_method = ?,
                    area_covered = ?, labor_count = ?, labor_hours = ?, cost = ?,
                    weather_condition = ?, performed_by = ?, supervisor = ?,
                    effectiveness = ?, status = ?, notes = ?
                WHERE pest_control_id = ?
            ");
            $stmt->execute([
                post('work_order_id') ?: null,
                post('block_id'),
                post('application_date'),
                post('pest_type'),
                post('pest_name')           ?: null,
                post('severity')            ?: 'Medium',
                post('pesticide_name')      ?: null,
                post('pesticide_type')      ?: null,
                post('quantity_used')       ?: null,
                post('application_method')  ?: null,
                post('area_covered')        ?: null,
                post('labor_count')         ?: null,
                post('labor_hours')         ?: null,
                post('cost')                ?: null,
                post('weather_condition')   ?: null,
                post('performed_by')        ?: null,
                post('supervisor')          ?: null,
                post('effectiveness')       ?: 'Not Assessed',
                post('status'),
                post('notes')               ?: null,
                post('pest_control_id'),
            ]);
            set_message('success', 'Catatan pengendalian berhasil diperbarui.');
            redirect('pest_control.php');
        } catch (PDOException $e) {
            set_message('error', 'Gagal memperbarui: ' . $e->getMessage());
        }
    }

    elseif ($action === 'delete') {
        try {
            $db->prepare("DELETE FROM pest_control_records WHERE pest_control_id = ?")
               ->execute([post('pest_control_id')]);
            set_message('success', 'Catatan berhasil dihapus.');
            redirect('pest_control.php');
        } catch (PDOException $e) {
            set_message('error', 'Gagal menghapus: ' . $e->getMessage());
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Edit record lookup
// ─────────────────────────────────────────────────────────────────────────────
$edit_record = null;
if (get('action') === 'edit' && get('id')) {
    $s = $db->prepare("SELECT * FROM pest_control_records WHERE pest_control_id = ?");
    $s->execute([get('id')]);
    $edit_record = $s->fetch();
}

// ─────────────────────────────────────────────────────────────────────────────
// Reference data
// ─────────────────────────────────────────────────────────────────────────────
$blocks = $db->query("
    SELECT b.block_id, b.block_code, b.block_name, b.total_plants,
           py.year, d.division_name, bu.unit_name, c.company_name
    FROM blocks b
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d       ON py.division_id     = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c       ON bu.company_id      = c.company_id
    WHERE b.status IN ('TBM','TM','TR')
    ORDER BY c.company_name, bu.unit_name, d.division_name, b.block_name
")->fetchAll();

$work_orders = $db->query("
    SELECT wo.work_order_id, wo.work_order_number, b.block_name
    FROM work_orders wo
    INNER JOIN blocks b ON wo.block_id = b.block_id
    WHERE wo.status IN ('Planned','Assigned','In Progress') AND wo.work_type = 'Pest Control'
    ORDER BY wo.work_order_number DESC
")->fetchAll();

// ─────────────────────────────────────────────────────────────────────────────
// Filter & fetch records
// ─────────────────────────────────────────────────────────────────────────────
$search          = get('search', '');
$pest_type_filter = get('pest_type', '');
$severity_filter = get('severity', '');
$status_filter   = get('status', '');
$date_from       = get('date_from', '');
$date_to         = get('date_to', '');

$sql = "SELECT pcr.*,
        b.block_code, b.block_name, b.total_plants,
        py.year AS planting_year,
        d.division_name,
        bu.unit_name AS estate_name,
        c.company_name,
        wo.work_order_number
        FROM pest_control_records pcr
        INNER JOIN blocks b          ON pcr.block_id          = b.block_id
        INNER JOIN planting_years py ON b.planting_year_id    = py.planting_year_id
        INNER JOIN divisions d       ON py.division_id        = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id    = bu.business_unit_id
        INNER JOIN companies c       ON bu.company_id         = c.company_id
        LEFT JOIN  work_orders wo    ON pcr.work_order_id     = wo.work_order_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (b.block_name LIKE ? OR pcr.pest_type LIKE ? OR pcr.pest_name LIKE ? OR wo.work_order_number LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($pest_type_filter)  { $sql .= " AND pcr.pest_type = ?";  $params[] = $pest_type_filter; }
if ($severity_filter)   { $sql .= " AND pcr.severity = ?";   $params[] = $severity_filter; }
if ($status_filter)     { $sql .= " AND pcr.status = ?";     $params[] = $status_filter; }
if ($date_from)         { $sql .= " AND pcr.application_date >= ?"; $params[] = $date_from; }
if ($date_to)           { $sql .= " AND pcr.application_date <= ?"; $params[] = $date_to; }

$sql .= " ORDER BY pcr.application_date DESC, pcr.pest_control_id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// ─────────────────────────────────────────────────────────────────────────────
// Summary stats
// ─────────────────────────────────────────────────────────────────────────────
$total_records   = count($records);
$total_quantity  = array_sum(array_column($records, 'quantity_used'));
$total_area      = array_sum(array_column($records, 'area_covered'));
$total_cost      = array_sum(array_column($records, 'cost'));
$critical_count  = count(array_filter($records, fn($r) => $r['severity'] === 'Critical'));
$high_count      = count(array_filter($records, fn($r) => $r['severity'] === 'High'));
$completed_count = count(array_filter($records, fn($r) => $r['status'] === 'Selesai' || $r['status'] === 'Completed'));

// Per-type breakdown
$by_type = [];
foreach ($records as $r) {
    $t = $r['pest_type'] ?? 'Lainnya';
    $by_type[$t] = ($by_type[$t] ?? 0) + 1;
}
arsort($by_type);

// ─────────────────────────────────────────────────────────────────────────────
// Lookup maps
// ─────────────────────────────────────────────────────────────────────────────
// Pest type → label Indonesia
$pest_type_labels = [
    'Insect'   => '🦟 Serangga',
    'Disease'  => '🍄 Penyakit',
    'Weed'     => '🌿 Gulma',
    'Rodent'   => '🐀 Hewan Pengerat',
    'Other'    => '🔵 Lainnya',
];
$pest_types = array_keys($pest_type_labels);

// Severity GAPKI 2020 ambang
$severity_levels = ['Low', 'Medium', 'High', 'Critical'];
$severity_labels = [
    'Low'      => 'Rendah',
    'Medium'   => 'Sedang',
    'High'     => 'Tinggi',
    'Critical' => 'Kritis',
];
$severity_colors = [
    'Low'      => 'success',
    'Medium'   => 'primary',
    'High'     => 'warning',
    'Critical' => 'danger',
];

// Pesticide types → Indonesia
$pesticide_types = [
    'Insecticide'  => 'Insektisida',
    'Herbicide'    => 'Herbisida',
    'Fungicide'    => 'Fungisida',
    'Rodenticide'  => 'Rodentisida',
    'Biological'   => 'Agen Biologi (NPV/Bt/Trichoderma)',
    'Other'        => 'Lainnya',
];

// Application methods → Indonesia
$application_methods = [
    'Spraying'     => 'Penyemprotan',
    'Baiting'      => 'Pengumpanan',
    'Trapping'     => 'Perangkap',
    'Manual'       => 'Manual',
    'Injection'    => 'Injeksi Batang',
    'Biopesticide' => 'Biopestisida (tabur/siram)',
    'Other'        => 'Lainnya',
];

// Statuses — value = English (ENUM DB), label = Indonesia
$statuses = [
    'Planned'     => 'Direncanakan',
    'In Progress' => 'Sedang Berlangsung',
    'Completed'   => 'Selesai',
];

// Effectiveness — value = English (ENUM DB), label = Indonesia
$effectiveness_levels = [
    'Not Assessed' => 'Belum Dinilai',
    'Poor'         => 'Buruk',
    'Fair'         => 'Cukup',
    'Good'         => 'Baik',
    'Excellent'    => 'Sangat Baik',
];
// alias display badge (termasuk nilai Indonesia lama bila sudah tersimpan)
$effectiveness_display = [
    'Not Assessed'  => 'Belum Dinilai',
    'Poor'          => 'Buruk',
    'Fair'          => 'Cukup',
    'Good'          => 'Baik',
    'Excellent'     => 'Sangat Baik',
    'Belum Dinilai' => 'Belum Dinilai',
    'Buruk'         => 'Buruk',
    'Cukup'         => 'Cukup',
    'Baik'          => 'Baik',
    'Sangat Baik'   => 'Sangat Baik',
];
$effectiveness_colors = [
    'Not Assessed'  => 'secondary',
    'Poor'          => 'danger',
    'Fair'          => 'warning',
    'Good'          => 'info',
    'Excellent'     => 'success',
    'Belum Dinilai' => 'secondary',
    'Buruk'         => 'danger',
    'Cukup'         => 'warning',
    'Baik'          => 'info',
    'Sangat Baik'   => 'success',
];

// Weather options → Indonesia
$weather_options = ['Cerah', 'Berawan', 'Hujan Ringan', 'Mendung', 'Hujan Lebat'];

// GAPKI 2020 PHT — daftar OPT umum untuk autocomplete
$opt_names_by_type = [
    'Insect'  => ['Ulat Api (Setothosea asigna)', 'Ulat Kantong (Mahasena corbetti)', 'Kumbang Tanduk (Oryctes rhinoceros)', 'Ulat Pemakan Daun (Limacodidae)', 'Tungau (Oligonychus)', 'Belalang (Valanga nigricornis)', 'Kutu Putih (Dysmicoccus)'],
    'Disease' => ['Ganoderma BSR (Ganoderma boninense)', 'Busuk Pucuk (Phytophthora palmivora)', 'Crown Disease', 'Spear Rot / Busuk Tandan', 'Anthracnose', 'Leaf Blight (Pestalotiopsis)', 'Fusarium Wilt'],
    'Weed'    => ['Mikania cordata', 'Asystasia gangetica', 'Axonopus compressus', 'Cyperus rotundus', 'Paspalum conjugatum', 'Imperata cylindrica (Alang-alang)', 'Ottochloa nodosa'],
    'Rodent'  => ['Tikus Belukar (Rattus tiomanicus)', 'Tikus Sawah (Rattus argentiventer)', 'Bajing (Callosciurus)'],
    'Other'   => [],
];

// GAPKI 2020 ambang tindakan (untuk alert di UI)
$gapki_thresholds = [
    'pest_rat_attack_pct'           => ['label' => 'Serangan Tikus', 'warn' => 5, 'fail' => 15, 'unit' => '%'],
    'pest_nettle_caterpillar_pct'   => ['label' => 'Ulat Api', 'warn' => 10, 'fail' => 25, 'unit' => '%'],
    'pest_bagworm_attack_pct'       => ['label' => 'Ulat Kantong', 'warn' => 5, 'fail' => 20, 'unit' => '%'],
    'pest_oryctes_attack_pct'       => ['label' => 'Kumbang Tanduk', 'warn' => 3, 'fail' => 8, 'unit' => '%'],
    'disease_ganoderma_pct'         => ['label' => 'Ganoderma BSR', 'warn' => 5, 'fail' => 15, 'unit' => '%'],
    'disease_bud_rot_pct'           => ['label' => 'Busuk Pucuk', 'warn' => 1, 'fail' => 3, 'unit' => '%'],
    'disease_spear_rot_pct'         => ['label' => 'Spear Rot', 'warn' => 2, 'fail' => 5, 'unit' => '%'],
];

$page_title = __('pt_pest_control');
require_once 'includes/header.php';
?>

<style>
/* ── Theme Hama & Penyakit ──────────────────────────────────────────────── */
:root { --pc-teal: #006359; --pc-teal-dark: #004d45; }
.card-header            { background-color: var(--pc-teal) !important; color: #fff !important; }
.page-header h1         { color: var(--pc-teal) !important; }
.page-header            { border-bottom-color: var(--pc-teal) !important; }
.stat-card              { border-left: 4px solid var(--pc-teal); }
.stat-card h3           { color: var(--pc-teal) !important; }
.btn-primary            { background-color: var(--pc-teal) !important; border-color: var(--pc-teal) !important; }
.btn-primary:hover      { background-color: var(--pc-teal-dark) !important; border-color: var(--pc-teal-dark) !important; }
.text-primary           { color: var(--pc-teal) !important; }
.table-pc-head          { background-color: var(--pc-teal); color: #fff; }
.badge-critical         { background:#dc2626!important; color:#fff; }
.badge-high             { background:#d97706!important; color:#fff; }
.badge-medium           { background:#3b82f6!important; color:#fff; }
.badge-low              { background:#16a34a!important; color:#fff; }

/* ── GAPKI threshold alert strip ─────────────────────────────────────────── */
.gapki-ref              { background:#fffbeb; border:1px solid #fcd34d; border-radius:.4rem;
                          padding:.45rem .75rem; font-size:.78rem; }
.gapki-ref .ref-item    { display:inline-block; margin-right:1.2rem; }
.gapki-ref .ref-warn    { color:#92400e; font-weight:600; }
.gapki-ref .ref-fail    { color:#991b1b; font-weight:700; }

/* ── OPT type badge strip ────────────────────────────────────────────────── */
.type-strip             { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.5rem; }
.type-badge             { border-radius:.3rem; padding:4px 10px; font-size:.78rem; font-weight:600; cursor:pointer; }
.type-insect            { background:#fee2e2; color:#991b1b; }
.type-disease           { background:#fef3c7; color:#92400e; }
.type-weed              { background:#dcfce7; color:#166534; }
.type-rodent            { background:#f3e8ff; color:#6d28d9; }
.type-other             { background:#f1f5f9; color:#475569; }

/* ── Effectiveness bar ────────────────────────────────────────────────────── */
.eff-bar                { height:5px; border-radius:3px; margin-top:2px; }
</style>

<?php
// ── Helper badge renderers ──────────────────────────────────────────────────
function pc_severity_badge(string $sev): string {
    $map = ['Low'=>'low','Medium'=>'medium','High'=>'high','Critical'=>'critical',
            'Rendah'=>'low','Sedang'=>'medium','Tinggi'=>'high','Kritis'=>'critical'];
    $cls = $map[$sev] ?? 'medium';
    $lbl = match($sev) {
        'Low','Rendah'       => 'Rendah',
        'High','Tinggi'      => 'Tinggi ⚠',
        'Critical','Kritis'  => 'KRITIS 🔴',
        default              => 'Sedang',
    };
    return '<span class="badge badge-' . $cls . '">' . $lbl . '</span>';
}
function pc_effectiveness_badge(string $eff): string {
    global $effectiveness_colors, $effectiveness_display;
    $cls = $effectiveness_colors[$eff] ?? 'secondary';
    $lbl = $effectiveness_display[$eff] ?? $eff;
    return '<span class="badge text-bg-' . $cls . '">' . htmlspecialchars($lbl) . '</span>';
}
function pc_status_badge(string $st): string {
    $map = ['Direncanakan'=>'secondary','Berlangsung'=>'primary','Selesai'=>'success',
            'Planned'=>'secondary','In Progress'=>'primary','Completed'=>'success'];
    $cls = $map[$st] ?? 'secondary';
    $lbl = match($st) {
        'Planned','Direncanakan'    => 'Direncanakan',
        'In Progress','Berlangsung' => 'Berlangsung',
        'Completed','Selesai'       => 'Selesai',
        default => htmlspecialchars($st),
    };
    return '<span class="badge text-bg-' . $cls . '">' . $lbl . '</span>';
}
function pc_type_icon(string $t): string {
    return match($t) {
        'Insect'  => '🦟',
        'Disease' => '🍄',
        'Weed'    => '🌿',
        'Rodent'  => '🐀',
        default   => '🔵',
    };
}
?>

<!-- ── Page Header ──────────────────────────────────────────────────────────── -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-bug-fill"></i> Pengendalian Hama &amp; Penyakit</h1>
            <p class="text-muted mb-0">Penerapan PHT (Pengendalian Hama Terpadu) · Referensi: <strong>GAPKI 2020</strong> · PPKS Medan · Ditjenbun</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Catat Pengendalian
            </button>
        </div>
    </div>
</div>

<!-- ── GAPKI 2020 Ambang Tindakan Strip ─────────────────────────────────────── -->
<div class="gapki-ref mb-3">
    <span style="font-weight:700;color:#92400e"><i class="bi bi-patch-check"></i> Ambang Tindakan GAPKI PHT 2020:</span>
    <?php foreach ($gapki_thresholds as $std): ?>
    <span class="ref-item">
        <?= htmlspecialchars($std['label']) ?>:
        <span class="ref-warn">⚠ <?= $std['warn'] ?><?= $std['unit'] ?></span>
        <span class="ref-fail">· 🔴 <?= $std['fail'] ?><?= $std['unit'] ?></span>
    </span>
    <?php endforeach; ?>
</div>

<!-- ── Summary Cards ─────────────────────────────────────────────────────────── -->
<div class="row mb-3 g-3">
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <h3 class="mb-0"><?= $total_records ?></h3>
                <p class="mb-0 small"><i class="bi bi-list-check"></i> Total Catatan</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100" style="border-left-color:#dc2626">
            <div class="card-body py-2 px-3">
                <h3 class="mb-0 text-danger"><?= $critical_count ?></h3>
                <p class="mb-0 small"><i class="bi bi-exclamation-octagon"></i> Kritis</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100" style="border-left-color:#d97706">
            <div class="card-body py-2 px-3">
                <h3 class="mb-0 text-warning"><?= $high_count ?></h3>
                <p class="mb-0 small"><i class="bi bi-exclamation-triangle"></i> Tinggi</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <h3 class="mb-0"><?= format_number($total_area, 1) ?> Ha</h3>
                <p class="mb-0 small"><i class="bi bi-map"></i> Luas Ditangani</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <h3 class="mb-0"><?= format_number($total_quantity, 0) ?> L</h3>
                <p class="mb-0 small"><i class="bi bi-droplet"></i> Pestisida</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <h3 class="mb-0" style="font-size:1rem">Rp <?= number_format($total_cost, 0, ',', '.') ?></h3>
                <p class="mb-0 small"><i class="bi bi-cash"></i> Total Biaya</p>
            </div>
        </div>
    </div>
</div>

<!-- ── OPT Type Breakdown ─────────────────────────────────────────────────────── -->
<?php if (!empty($by_type)): ?>
<div class="card mb-3">
    <div class="card-header py-2"><i class="bi bi-pie-chart"></i> Rekapitulasi per Jenis OPT</div>
    <div class="card-body py-2">
        <div class="type-strip">
            <?php foreach ($by_type as $type => $cnt):
                $cls = match($type) {
                    'Insect'  => 'type-insect',
                    'Disease' => 'type-disease',
                    'Weed'    => 'type-weed',
                    'Rodent'  => 'type-rodent',
                    default   => 'type-other',
                };
            ?>
            <span class="type-badge <?= $cls ?>" onclick="filterByType('<?= htmlspecialchars($type) ?>')">
                <?= pc_type_icon($type) ?> <?= htmlspecialchars($pest_type_labels[$type] ?? $type) ?>: <strong><?= $cnt ?></strong>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Filter & Search ────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="Cari blok / OPT / WO…"
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="pest_type" id="filterType">
                    <option value="">Semua Jenis OPT</option>
                    <?php foreach ($pest_types as $t): ?>
                        <option value="<?= $t ?>" <?= $pest_type_filter === $t ? 'selected' : '' ?>>
                            <?= pc_type_icon($t) ?> <?= htmlspecialchars($pest_type_labels[$t] ?? $t) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="severity">
                    <option value="">Semua Keparahan</option>
                    <?php foreach ($severity_levels as $lv): ?>
                        <option value="<?= $lv ?>" <?= $severity_filter === $lv ? 'selected' : '' ?>>
                            <?= htmlspecialchars($severity_labels[$lv] ?? $lv) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="status">
                    <option value="">Semua Status</option>
                    <option value="Direncanakan" <?= $status_filter === 'Direncanakan' || $status_filter === 'Planned' ? 'selected' : '' ?>>Direncanakan</option>
                    <option value="Berlangsung"  <?= $status_filter === 'Berlangsung'  || $status_filter === 'In Progress' ? 'selected' : '' ?>>Sedang Berlangsung</option>
                    <option value="Selesai"      <?= $status_filter === 'Selesai'      || $status_filter === 'Completed' ? 'selected' : '' ?>>Selesai</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <input type="date" class="form-control form-control-sm" name="date_from"
                       value="<?= htmlspecialchars($date_from) ?>" title="Dari tanggal">
                <input type="date" class="form-control form-control-sm" name="date_to"
                       value="<?= htmlspecialchars($date_to) ?>" title="Sampai tanggal">
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i> Filter</button>
                <a href="pest_control.php" class="btn btn-outline-secondary btn-sm" title="Reset"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- ── Records Table ──────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header py-2">
        <i class="bi bi-list-ul"></i> Catatan Pengendalian Hama &amp; Penyakit
        <span class="badge bg-white text-dark ms-1"><?= count($records) ?> data</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" style="font-size:.82rem">
                <thead class="table-pc-head">
                    <tr>
                        <th>Tanggal</th>
                        <th>Blok / Divisi</th>
                        <th>Jenis OPT</th>
                        <th>Nama OPT</th>
                        <th>Keparahan</th>
                        <th>Pestisida / Metode</th>
                        <th class="text-end">Qty (L)</th>
                        <th class="text-end">Luas (Ha)</th>
                        <th>Efektivitas</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">
                        <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem"></i>
                        Belum ada catatan pengendalian hama &amp; penyakit.<br>
                        <small>Klik <strong>Catat Pengendalian</strong> untuk mulai input.</small>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($records as $r): ?>
                    <tr class="<?= $r['severity'] === 'Critical' ? 'table-danger' : ($r['severity'] === 'High' ? 'table-warning' : '') ?>">
                        <td class="text-nowrap"><?= format_date($r['application_date']) ?></td>
                        <td>
                            <span class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($r['division_name'] ?? '') ?></span><br>
                            <strong><?= htmlspecialchars($r['block_name']) ?></strong>
                            <span class="text-muted"> (<?= htmlspecialchars($r['estate_name']) ?>)</span>
                        </td>
                        <td><?= pc_type_icon($r['pest_type']) ?> <?= htmlspecialchars($pest_type_labels[$r['pest_type']] ?? $r['pest_type']) ?></td>
                        <td><?= htmlspecialchars($r['pest_name'] ?? '—') ?></td>
                        <td><?= pc_severity_badge($r['severity'] ?? 'Medium') ?></td>
                        <td>
                            <?= htmlspecialchars($r['pesticide_name'] ?? '—') ?>
                            <?php if ($r['application_method']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($application_methods[$r['application_method']] ?? $r['application_method']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= $r['quantity_used'] ? format_number($r['quantity_used'], 1) : '—' ?></td>
                        <td class="text-end"><?= $r['area_covered'] ? format_number($r['area_covered'], 1) : '—' ?></td>
                        <td><?= pc_effectiveness_badge($r['effectiveness'] ?? 'Belum Dinilai') ?></td>
                        <td><?= pc_status_badge($r['status'] ?? '') ?></td>
                        <td class="text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-info py-0 px-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#viewModal<?= $r['pest_control_id'] ?>"
                                    title="Lihat Detail"><i class="bi bi-eye"></i></button>
                            <a href="?action=edit&id=<?= $r['pest_control_id'] ?>"
                               class="btn btn-sm btn-outline-warning py-0 px-1" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="pest_control.php" style="display:inline"
                                  onsubmit="return confirm('Hapus catatan ini?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="pest_control_id" value="<?= $r['pest_control_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Hapus">
                                    <i class="bi bi-trash"></i>
                                </button>
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

<!-- ── View Detail Modals ─────────────────────────────────────────────────────── -->
<?php foreach ($records as $r): ?>
<div class="modal fade" id="viewModal<?= $r['pest_control_id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--pc-teal);color:#fff">
                <h5 class="modal-title">
                    <?= pc_type_icon($r['pest_type']) ?>
                    Detail Pengendalian — <?= htmlspecialchars($r['block_name']) ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-0">
                    <div class="col-md-6 pe-3">
                        <table class="table table-sm table-borderless mb-0" style="font-size:.82rem">
                            <tr><th class="text-muted" width="42%">Tanggal Aplikasi</th><td><?= format_date($r['application_date']) ?></td></tr>
                            <tr><th class="text-muted">No. WO</th><td><?= $r['work_order_number'] ? htmlspecialchars($r['work_order_number']) : '—' ?></td></tr>
                            <tr><th class="text-muted">Blok</th><td><strong><?= htmlspecialchars($r['block_name']) ?></strong></td></tr>
                            <tr><th class="text-muted">Divisi</th><td><?= htmlspecialchars($r['division_name'] ?? '') ?></td></tr>
                            <tr><th class="text-muted">Estate</th><td><?= htmlspecialchars($r['estate_name']) ?></td></tr>
                            <tr><th class="text-muted">Jenis OPT</th><td><?= pc_type_icon($r['pest_type']) ?> <?= htmlspecialchars($pest_type_labels[$r['pest_type']] ?? $r['pest_type']) ?></td></tr>
                            <tr><th class="text-muted">Nama OPT</th><td><?= htmlspecialchars($r['pest_name'] ?? '—') ?></td></tr>
                            <tr><th class="text-muted">Keparahan</th><td><?= pc_severity_badge($r['severity'] ?? 'Medium') ?></td></tr>
                            <tr><th class="text-muted">Nama Pestisida</th><td><?= htmlspecialchars($r['pesticide_name'] ?? '—') ?></td></tr>
                            <tr><th class="text-muted">Tipe Pestisida</th><td><?= htmlspecialchars($pesticide_types[$r['pesticide_type']] ?? ($r['pesticide_type'] ?? '—')) ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6 ps-3 border-start">
                        <table class="table table-sm table-borderless mb-0" style="font-size:.82rem">
                            <tr><th class="text-muted" width="42%">Jumlah Digunakan</th><td><?= $r['quantity_used'] ? format_number($r['quantity_used'], 1) . ' L' : '—' ?></td></tr>
                            <tr><th class="text-muted">Metode Aplikasi</th><td><?= htmlspecialchars($application_methods[$r['application_method']] ?? ($r['application_method'] ?? '—')) ?></td></tr>
                            <tr><th class="text-muted">Luas Ditangani</th><td><?= $r['area_covered'] ? format_number($r['area_covered'], 1) . ' Ha' : '—' ?></td></tr>
                            <tr><th class="text-muted">Jumlah Tenaga</th><td><?= $r['labor_count'] ?? '—' ?> orang</td></tr>
                            <tr><th class="text-muted">Jam Kerja</th><td><?= $r['labor_hours'] ? format_number($r['labor_hours'], 1) . ' jam' : '—' ?></td></tr>
                            <tr><th class="text-muted">Biaya</th><td><?= $r['cost'] ? 'Rp ' . number_format($r['cost'], 0, ',', '.') : '—' ?></td></tr>
                            <tr><th class="text-muted">Cuaca</th><td><?= htmlspecialchars($r['weather_condition'] ?? '—') ?></td></tr>
                            <tr><th class="text-muted">Dilaksanakan Oleh</th><td><?= htmlspecialchars($r['performed_by'] ?? '—') ?></td></tr>
                            <tr><th class="text-muted">Supervisor</th><td><?= htmlspecialchars($r['supervisor'] ?? '—') ?></td></tr>
                            <tr><th class="text-muted">Efektivitas</th><td><?= pc_effectiveness_badge($r['effectiveness'] ?? 'Belum Dinilai') ?></td></tr>
                            <tr><th class="text-muted">Status</th><td><?= pc_status_badge($r['status'] ?? '') ?></td></tr>
                        </table>
                    </div>
                </div>
                <?php if ($r['notes']): ?>
                <div class="mt-2 p-2 bg-light rounded" style="font-size:.82rem">
                    <strong>Catatan:</strong> <?= nl2br(htmlspecialchars($r['notes'])) ?>
                </div>
                <?php endif; ?>

                <!-- GAPKI 2020 threshold alert inside detail modal -->
                <?php
                $sev = $r['severity'] ?? 'Medium';
                if ($sev === 'Critical'): ?>
                <div class="alert alert-danger py-2 mt-2 mb-0" style="font-size:.8rem">
                    <i class="bi bi-exclamation-octagon-fill"></i>
                    <strong>Status KRITIS</strong> — melebihi ambang darurat GAPKI PHT 2020.
                    Tindakan segera diperlukan. Laporkan ke Asisten Kepala / Manajer Kebun.
                </div>
                <?php elseif ($sev === 'High'): ?>
                <div class="alert alert-warning py-2 mt-2 mb-0" style="font-size:.8rem">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>Keparahan Tinggi</strong> — mendekati ambang ekonomi GAPKI PHT 2020.
                    Intensifkan monitoring dan siapkan tindakan darurat.
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer py-2">
                <a href="?action=edit&id=<?= $r['pest_control_id'] ?>" class="btn btn-sm btn-warning">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ── Add / Edit Modal ───────────────────────────────────────────────────────── -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="pest_control.php">
                <div class="modal-header" style="background:var(--pc-teal);color:#fff">
                    <h5 class="modal-title">
                        <i class="bi bi-<?= $edit_record ? 'pencil' : 'plus-circle' ?>"></i>
                        <?= $edit_record ? 'Edit Catatan Pengendalian' : 'Catat Pengendalian Hama &amp; Penyakit' ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?= $edit_record ? 'edit' : 'add' ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="pest_control_id" value="<?= $edit_record['pest_control_id'] ?>">
                    <?php endif; ?>

                    <!-- GAPKI ref strip inside modal -->
                    <div class="gapki-ref mb-3" style="font-size:.75rem">
                        <i class="bi bi-patch-check text-warning"></i>
                        <strong>Referensi GAPKI PHT 2020:</strong>
                        Tikus &lt;5% · Ulat Api &lt;10% · Ulat Kantong &lt;5% · Kumbang Tanduk &lt;3% · Ganoderma &lt;5% · Busuk Pucuk &lt;1%
                    </div>

                    <!-- Row 1: WO + Block -->
                    <div class="row g-2 mb-2">
                        <div class="col-md-5">
                            <label class="form-label form-label-sm mb-1">No. Work Order <span class="text-muted">(Opsional)</span></label>
                            <select class="form-select form-select-sm" name="work_order_id">
                                <option value="">— Tanpa Work Order —</option>
                                <?php foreach ($work_orders as $wo): ?>
                                    <option value="<?= $wo['work_order_id'] ?>"
                                        <?= ($edit_record && $edit_record['work_order_id'] == $wo['work_order_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($wo['work_order_number'] . ' — ' . $wo['block_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label form-label-sm mb-1">Blok <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" name="block_id" required id="block_select">
                                <option value="">— Pilih Blok —</option>
                                <?php foreach ($blocks as $b): ?>
                                    <option value="<?= $b['block_id'] ?>"
                                        data-plants="<?= $b['total_plants'] ?>"
                                        data-div="<?= htmlspecialchars($b['division_name']) ?>"
                                        <?= ($edit_record && $edit_record['block_id'] == $b['block_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['unit_name'] . ' › ' . $b['division_name'] . ' › ' . $b['block_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" id="plant_count"></small>
                        </div>
                    </div>

                    <!-- Row 2: Date + Pest Type + Pest Name -->
                    <div class="row g-2 mb-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-1">Tanggal Aplikasi <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm" name="application_date" required
                                   value="<?= $edit_record ? $edit_record['application_date'] : date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-1">Jenis OPT <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" name="pest_type" required id="pestTypeSelect">
                                <?php foreach ($pest_types as $t): ?>
                                    <option value="<?= $t ?>"
                                        <?= ($edit_record && $edit_record['pest_type'] === $t) ? 'selected' : '' ?>>
                                        <?= pc_type_icon($t) ?> <?= htmlspecialchars($pest_type_labels[$t] ?? $t) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-1">Nama OPT / Organisme</label>
                            <input type="text" class="form-control form-control-sm" name="pest_name"
                                   id="pestNameInput"
                                   list="optNameList"
                                   value="<?= $edit_record ? htmlspecialchars($edit_record['pest_name']) : '' ?>"
                                   placeholder="Ketik nama OPT atau pilih…">
                            <datalist id="optNameList"></datalist>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-1">Keparahan</label>
                            <select class="form-select form-select-sm" name="severity" id="severitySelect">
                                <?php foreach ($severity_levels as $lv): ?>
                                    <option value="<?= $lv ?>"
                                        <?= ($edit_record && $edit_record['severity'] === $lv)
                                            ? 'selected'
                                            : (!$edit_record && $lv === 'Medium' ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($severity_labels[$lv] ?? $lv) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Row 3: Pesticide + Type + Method -->
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-1">Nama Pestisida / Agen Biologi</label>
                            <input type="text" class="form-control form-control-sm" name="pesticide_name"
                                   value="<?= $edit_record ? htmlspecialchars($edit_record['pesticide_name']) : '' ?>"
                                   placeholder="cth: Cypermethrin, NPV, Trichoderma sp.">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-1">Tipe Pestisida</label>
                            <select class="form-select form-select-sm" name="pesticide_type">
                                <option value="">— Pilih Tipe —</option>
                                <?php foreach ($pesticide_types as $k => $v): ?>
                                    <option value="<?= $k ?>"
                                        <?= ($edit_record && $edit_record['pesticide_type'] === $k) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($v) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-1">Metode Aplikasi</label>
                            <select class="form-select form-select-sm" name="application_method">
                                <option value="">— Pilih Metode —</option>
                                <?php foreach ($application_methods as $k => $v): ?>
                                    <option value="<?= $k ?>"
                                        <?= ($edit_record && $edit_record['application_method'] === $k) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($v) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-1">Cuaca</label>
                            <select class="form-select form-select-sm" name="weather_condition">
                                <option value="">— Pilih —</option>
                                <?php foreach ($weather_options as $w): ?>
                                    <option value="<?= $w ?>"
                                        <?= ($edit_record && $edit_record['weather_condition'] === $w) ? 'selected' : '' ?>>
                                        <?= $w ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Row 4: Quantity + Area + Labor + Cost -->
                    <div class="row g-2 mb-2">
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-1">Jumlah Digunakan (L)</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="quantity_used"
                                   value="<?= $edit_record ? $edit_record['quantity_used'] : '' ?>"
                                   placeholder="cth: 50">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-1">Luas Ditangani (Ha)</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="area_covered"
                                   value="<?= $edit_record ? $edit_record['area_covered'] : '' ?>"
                                   placeholder="cth: 5.5">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-1">Jml Tenaga Kerja</label>
                            <input type="number" min="0" class="form-control form-control-sm" name="labor_count"
                                   value="<?= $edit_record ? $edit_record['labor_count'] : '' ?>"
                                   placeholder="Orang">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-1">Jam Kerja (HK)</label>
                            <input type="number" step="0.5" min="0" class="form-control form-control-sm" name="labor_hours"
                                   value="<?= $edit_record ? $edit_record['labor_hours'] : '' ?>"
                                   placeholder="cth: 40">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-1">Biaya (Rp)</label>
                            <input type="number" min="0" class="form-control form-control-sm" name="cost"
                                   value="<?= $edit_record ? $edit_record['cost'] : '' ?>"
                                   placeholder="cth: 2000000">
                        </div>
                    </div>

                    <!-- Row 5: Performed By + Supervisor + Effectiveness + Status -->
                    <div class="row g-2 mb-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-1">Dilaksanakan Oleh</label>
                            <input type="text" class="form-control form-control-sm" name="performed_by"
                                   value="<?= $edit_record ? htmlspecialchars($edit_record['performed_by']) : '' ?>"
                                   placeholder="Nama regu / petugas">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-1">Supervisor / Mandor</label>
                            <input type="text" class="form-control form-control-sm" name="supervisor"
                                   value="<?= $edit_record ? htmlspecialchars($edit_record['supervisor']) : '' ?>"
                                   placeholder="Nama mandor">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-1">Efektivitas Pengendalian</label>
                            <select class="form-select form-select-sm" name="effectiveness">
                                <?php
                                // Current value from DB (English key)
                                $cur_eff = $edit_record ? ($edit_record['effectiveness'] ?? 'Not Assessed') : 'Not Assessed';
                                foreach ($effectiveness_levels as $ek => $ev):
                                ?>
                                    <option value="<?= $ek ?>" <?= $cur_eff === $ek ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ev) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-1">Status</label>
                            <select class="form-select form-select-sm" name="status">
                                <?php
                                $cur_status = $edit_record ? ($edit_record['status'] ?? 'Planned') : 'Planned';
                                foreach ($statuses as $sk => $sv):
                                ?>
                                    <option value="<?= $sk ?>" <?= $cur_status === $sk ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sv) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-1">
                        <label class="form-label form-label-sm mb-1">Catatan Tambahan</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2"
                                  placeholder="Kondisi lapangan, tindak lanjut, rekomendasi…"><?= $edit_record ? htmlspecialchars($edit_record['notes']) : '' ?></textarea>
                    </div>

                    <!-- Severity warning banner (live) -->
                    <div id="severityAlert" class="mt-2" style="display:none"></div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-save"></i> <?= $edit_record ? 'Simpan Perubahan' : 'Simpan Catatan' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_record): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('addModal')).show();
});
</script>
<?php endif; ?>

<script>
// ── OPT name autocomplete per pest type ───────────────────────────────────
var optNames = <?= json_encode($opt_names_by_type) ?>;

function updateOptDatalist(type) {
    var dl = document.getElementById('optNameList');
    dl.innerHTML = '';
    var names = optNames[type] || [];
    names.forEach(function(n) {
        var opt = document.createElement('option');
        opt.value = n;
        dl.appendChild(opt);
    });
}

var pestTypeSelect = document.getElementById('pestTypeSelect');
if (pestTypeSelect) {
    pestTypeSelect.addEventListener('change', function () {
        updateOptDatalist(this.value);
    });
    updateOptDatalist(pestTypeSelect.value);
}

// ── Filter by type badge click ─────────────────────────────────────────────
function filterByType(type) {
    var sel = document.getElementById('filterType');
    if (sel) {
        sel.value = type;
        sel.closest('form').submit();
    }
}

// ── Severity live alert in modal ───────────────────────────────────────────
var sevSel = document.getElementById('severitySelect');
var sevAlert = document.getElementById('severityAlert');
function updateSeverityAlert(val) {
    if (!sevAlert) return;
    if (val === 'Critical') {
        sevAlert.style.display = 'block';
        sevAlert.innerHTML = '<div class="alert alert-danger py-2 mb-0" style="font-size:.8rem"><i class="bi bi-exclamation-octagon-fill"></i> <strong>KRITIS</strong> — Melebihi ambang darurat GAPKI PHT 2020. Tindakan segera dan pelaporan ke atasan wajib dilakukan.</div>';
    } else if (val === 'High') {
        sevAlert.style.display = 'block';
        sevAlert.innerHTML = '<div class="alert alert-warning py-2 mb-0" style="font-size:.8rem"><i class="bi bi-exclamation-triangle-fill"></i> <strong>Tinggi</strong> — Mendekati ambang ekonomi GAPKI PHT 2020. Intensifkan monitoring dan siapkan tindakan lanjutan.</div>';
    } else {
        sevAlert.style.display = 'none';
    }
}
if (sevSel) {
    sevSel.addEventListener('change', function () { updateSeverityAlert(this.value); });
    updateSeverityAlert(sevSel.value);
}

// ── Block info display ─────────────────────────────────────────────────────
var blockSel = document.getElementById('block_select');
var plantCnt = document.getElementById('plant_count');
if (blockSel) {
    blockSel.addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        var plants = opt.getAttribute('data-plants');
        var div    = opt.getAttribute('data-div');
        if (plants || div) {
            plantCnt.textContent = (div ? div + ' · ' : '') + (plants ? plants + ' pohon' : '');
        } else {
            plantCnt.textContent = '';
        }
    });
    // Trigger on load if editing
    if (blockSel.value) blockSel.dispatchEvent(new Event('change'));
}
</script>

<?php require_once 'includes/footer.php'; ?>
