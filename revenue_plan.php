<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db         = getDB();
require_once 'includes/functions.php';
require_once 'includes/lang.php';
$page_title = __('pt_revenue_plan');

// ─── Filter ──────────────────────────────────────────────────────────────────
$year        = (int) get('year',  2026);
$pks_filter  = get('pks', 'all');     // all | <pks_code>
$prod_filter = get('product', 'all'); // all | CPO | Kernel

require_once 'includes/header.php';

// ─── Load Assumptions from DB ────────────────────────────────────────────────
$asmp = [
    'price_cpo_idr'    => 12500,
    'price_pk_idr'     => 6200,
    'usd_idr_rate'     => 16000,
    'price_cpo_usd'    => 820,
    'price_pk_usd'     => 390,
    'export_ratio_cpo' => 0,
    'export_ratio_pk'  => 0,
    'updated_at'       => null,
    'updated_by'       => null,
];
try {
    $s = $db->prepare("SELECT * FROM revenue_assumptions WHERE year = ? LIMIT 1");
    $s->execute([$year]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) $asmp = array_merge($asmp, $row);
} catch (PDOException $e) { /* use defaults */ }

// ─── Load PKS list from DB ────────────────────────────────────────────────────
$pks_list = [];
try {
    $sp = $db->prepare("SELECT * FROM revenue_assumption_pks WHERE year=? AND is_active=1 ORDER BY sort_order,id");
    $sp->execute([$year]);
    $pks_list = $sp->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback to hardcoded if table not yet created
    $pks_list = [
        ['id'=>0,'pks_code'=>'MHK','pks_name'=>'PKS Mahakam','oer'=>0.2200,'ker'=>0.0520,
         'price_cpo_idr'=>null,'price_pk_idr'=>null,'price_cpo_usd'=>null,'price_pk_usd'=>null,
         'invoice_pattern'=>"INV-BSM-%-MHK-$year-%",'is_active'=>1,'sort_order'=>1],
        ['id'=>0,'pks_code'=>'BLG','pks_name'=>'PKS Bulungan','oer'=>0.2100,'ker'=>0.0500,
         'price_cpo_idr'=>null,'price_pk_idr'=>null,'price_cpo_usd'=>null,'price_pk_usd'=>null,
         'invoice_pattern'=>"INV-BSM-%-BLG-$year-%",'is_active'=>1,'sort_order'=>2],
    ];
}

// Build map: pks_code → name (for label rendering)
$pks_map = [];
foreach ($pks_list as $p) $pks_map[$p['pks_code']] = $p['pks_name'];

// ─── KPI SUMMARY ─────────────────────────────────────────────────────────────
try {
    $sql_kpi    = "SELECT product_type, SUM(quantity_kg) AS total_kg,
                   SUM(total_amount) AS total_amount
                   FROM sales WHERE YEAR(sale_date)=? AND updated_by='seed'";
    $params_kpi = [$year];
    // Dynamic PKS filter using invoice_pattern from DB
    if ($pks_filter !== 'all') {
        foreach ($pks_list as $p) {
            if ($p['pks_code'] === $pks_filter) {
                $sql_kpi .= " AND invoice_number LIKE ?";
                $params_kpi[] = $p['invoice_pattern'];
                break;
            }
        }
    }
    $sql_kpi .= " GROUP BY product_type";
    $stmt_kpi = $db->prepare($sql_kpi);
    $stmt_kpi->execute($params_kpi);
    $kpi_rows = $stmt_kpi->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $kpi_rows = []; }

$kpi = ['CPO' => ['kg' => 0, 'amount' => 0], 'Kernel' => ['kg' => 0, 'amount' => 0]];
foreach ($kpi_rows as $r) {
    if (isset($kpi[$r['product_type']])) {
        $kpi[$r['product_type']]['kg']     = (float)$r['total_kg'];
        $kpi[$r['product_type']]['amount'] = (float)$r['total_amount'];
    }
}
$total_amount = $kpi['CPO']['amount'] + $kpi['Kernel']['amount'];
$total_ton    = ($kpi['CPO']['kg'] + $kpi['Kernel']['kg']) / 1000;

// ─── MONTHLY DATA ─────────────────────────────────────────────────────────────
try {
    $sql_monthly = "SELECT MONTH(sale_date) AS bulan, product_type,
                    SUM(quantity_kg) AS total_kg, SUM(total_amount) AS total_amount
                    FROM sales WHERE YEAR(sale_date)=? AND updated_by='seed'";
    $params_m = [$year];
    if ($pks_filter !== 'all') {
        foreach ($pks_list as $p) {
            if ($p['pks_code'] === $pks_filter) {
                $sql_monthly .= " AND invoice_number LIKE ?";
                $params_m[]   = $p['invoice_pattern'];
                break;
            }
        }
    }
    if ($prod_filter !== 'all') { $sql_monthly .= " AND product_type=?"; $params_m[] = $prod_filter; }
    $sql_monthly .= " GROUP BY MONTH(sale_date),product_type ORDER BY MONTH(sale_date),product_type";
    $stmt_m = $db->prepare($sql_monthly);
    $stmt_m->execute($params_m);
    $monthly_rows = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $monthly_rows = []; }

$months_id = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$monthly = [];
for ($m = 1; $m <= 12; $m++) {
    $monthly[$m] = ['CPO_kg'=>0,'CPO_amt'=>0,'Kernel_kg'=>0,'Kernel_amt'=>0];
}
foreach ($monthly_rows as $r) {
    $m  = (int)$r['bulan'];
    $pt = $r['product_type'];
    $monthly[$m][$pt.'_kg']  += (float)$r['total_kg'];
    $monthly[$m][$pt.'_amt'] += (float)$r['total_amount'];
}

// ─── DETAIL TABLE ─────────────────────────────────────────────────────────────
try {
    $sql_det  = "SELECT s.sale_date, s.invoice_number, s.product_type,
                 s.quantity_kg, s.unit_price, s.total_amount,
                 s.payment_status, s.delivery_location, s.notes, cu.customer_name
                 FROM sales s JOIN customers cu ON s.customer_id=cu.customer_id
                 WHERE YEAR(s.sale_date)=? AND s.updated_by='seed'";
    $params_d = [$year];
    if ($pks_filter !== 'all') {
        foreach ($pks_list as $p) {
            if ($p['pks_code'] === $pks_filter) {
                $sql_det .= " AND s.invoice_number LIKE ?";
                $params_d[] = $p['invoice_pattern'];
                break;
            }
        }
    }
    if ($prod_filter !== 'all') { $sql_det .= " AND s.product_type=?"; $params_d[] = $prod_filter; }
    $sql_det .= " ORDER BY s.sale_date, s.product_type";
    $stmt_d = $db->prepare($sql_det);
    $stmt_d->execute($params_d);
    $details = $stmt_d->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $details = []; }

// ─── Chart JSON ───────────────────────────────────────────────────────────────
$chart_labels  = json_encode($months_id);
$chart_cpo     = json_encode(array_map(fn($m) => round($monthly[$m]['CPO_amt']    / 1e9, 2), range(1,12)));
$chart_kernel  = json_encode(array_map(fn($m) => round($monthly[$m]['Kernel_amt'] / 1e9, 2), range(1,12)));
$chart_cpo_ton = json_encode(array_map(fn($m) => round($monthly[$m]['CPO_kg']     / 1000, 1), range(1,12)));
$chart_pk_ton  = json_encode(array_map(fn($m) => round($monthly[$m]['Kernel_kg']  / 1000, 1), range(1,12)));

// ─── Helpers ──────────────────────────────────────────────────────────────────
function fmt_idr($n) { return 'Rp '.number_format($n, 0, ',', '.'); }
function fmt_ton($kg) { return number_format($kg/1000, 1, ',', '.').' ton'; }
// Dynamic PKS label based on loaded pks_list
function pks_label(string $inv, array $pks_list): string {
    $colors = ['bg-primary','bg-info text-dark','bg-success','bg-warning text-dark','bg-purple','bg-secondary'];
    foreach ($pks_list as $i => $p) {
        $code = $p['pks_code'];
        if (strpos($inv, "-$code-") !== false) {
            $cls = $colors[$i % count($colors)];
            return '<span class="badge '.$cls.'">'.htmlspecialchars($p['pks_name']).'</span>';
        }
    }
    return '';
}
?>

<style>
    .rp-accent   { color: #1a6b3a !important; }
    .bg-rp       { background-color: #1a6b3a !important; }
    .btn-rp      { background-color: #1a6b3a; color:#fff; border:none; }
    .btn-rp:hover{ background-color: #155c30; color:#fff; }
    .kpi-card    { border-left: 4px solid #1a6b3a; }
    .kpi-card.orange { border-left-color: #e87722; }
    .kpi-card.blue   { border-left-color: #1565c0; }
    .kpi-card.purple { border-left-color: #7b1fa2; }
    .kpi-val     { font-size: 1.6rem; font-weight:700; }
    .table-plan thead th { background-color: #1a6b3a; color:#fff; font-weight:600; }
    .table-plan tfoot td { background-color: #f0f7f2; font-weight:700; }
    .row-peak    { background-color: #fff8e1 !important; }

    /* Asumsi panel */
    #asumsiPanel { display:none; }
    #asumsiPanel.show { display:block; }
    .asumsi-group-title {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #1a6b3a;
        border-bottom: 1px solid #c8e6c9;
        padding-bottom: 4px;
        margin-bottom: 8px;
        margin-top: 12px;
    }
    .asumsi-group-title:first-child { margin-top: 0; }
    .form-label-sm { font-size: 0.78rem; color: #555; margin-bottom: 2px; }
    .input-asumsi  { font-size: 0.82rem; }
    #toastRecalc {
        position: fixed; bottom: 24px; right: 24px; z-index: 9999;
        min-width: 280px;
    }
    .eff-price-badge {
        font-size: 0.78rem;
        background: #e8f5e9;
        border: 1px solid #a5d6a7;
        border-radius: 4px;
        padding: 2px 8px;
        color: #1a6b3a;
        font-weight: 600;
    }
    .export-active-badge {
        font-size: 0.72rem;
        background: #fff3e0;
        border: 1px solid #ffcc80;
        border-radius: 4px;
        padding: 1px 6px;
        color: #e65100;
        font-weight: 600;
    }
</style>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="rp-accent mb-0"><i class="bi bi-graph-up-arrow"></i> Rencana Pendapatan <?= $year ?></h1>
        <p class="text-muted mb-0 small">Panen TBS · Produksi CPO &amp; PK · Proyeksi Pendapatan — PT Borneo Sawit Mandiri</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-success btn-sm" id="btnToggleAsumsi">
            <i class="bi bi-sliders"></i> Edit Asumsi
        </button>
        <a href="?year=<?= $year ?>&pks=<?= $pks_filter ?>&product=<?= $prod_filter ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </a>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     ASUMSI PANEL (collapsible)
════════════════════════════════════════════════════════════ -->
<div id="asumsiPanel" class="card mb-3 border-success">
    <div class="card-header py-2" style="background:#f0f7f2;border-bottom:1px solid #c8e6c9;">
        <div class="d-flex justify-content-between align-items-center">
            <span class="fw-semibold text-success small"><i class="bi bi-sliders me-1"></i>Asumsi &amp; Parameter <?= $year ?></span>
            <?php if ($asmp['updated_at']): ?>
            <span class="text-muted" style="font-size:0.72rem;">
                Diupdate: <?= date('d M Y H:i', strtotime($asmp['updated_at'])) ?>
                <?php if ($asmp['updated_by']): ?> oleh <strong><?= htmlspecialchars($asmp['updated_by']) ?></strong><?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body py-3">
        <form id="formAsumsi" onsubmit="return false;">
            <input type="hidden" name="year" value="<?= $year ?>">

            <div class="row g-3">
                <!-- Kolom 1: Harga Default Lokal -->
                <div class="col-sm-6 col-lg-3">
                    <div class="asumsi-group-title"><i class="bi bi-cash-coin me-1"></i>Harga Default Lokal (Rp/kg)</div>
                    <div class="mb-2">
                        <label class="form-label-sm">Harga CPO (Rp/kg)</label>
                        <input type="number" name="price_cpo_idr" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_cpo_idr'] ?>" step="50" min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Harga Palm Kernel (Rp/kg)</label>
                        <input type="number" name="price_pk_idr" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_pk_idr'] ?>" step="50" min="0">
                    </div>
                    <div class="form-text" style="font-size:0.71rem;">Berlaku untuk semua PKS kecuali ada override per-PKS</div>
                </div>

                <!-- Kolom 2: Kurs & Harga USD -->
                <div class="col-sm-6 col-lg-3">
                    <div class="asumsi-group-title"><i class="bi bi-currency-exchange me-1"></i>Kurs &amp; Harga Ekspor</div>
                    <div class="mb-2">
                        <label class="form-label-sm">Kurs USD / IDR</label>
                        <input type="number" name="usd_idr_rate" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['usd_idr_rate'] ?>" step="50" min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Harga CPO default (USD/MT)</label>
                        <input type="number" name="price_cpo_usd" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_cpo_usd'] ?>" step="5" min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Harga PK default (USD/MT)</label>
                        <input type="number" name="price_pk_usd" class="form-control form-control-sm input-asumsi"
                               value="<?= $asmp['price_pk_usd'] ?>" step="5" min="0">
                    </div>
                </div>

                <!-- Kolom 3: Rasio Ekspor -->
                <div class="col-sm-6 col-lg-3">
                    <div class="asumsi-group-title"><i class="bi bi-globe me-1"></i>Rasio Ekspor</div>
                    <div class="mb-2">
                        <label class="form-label-sm">Ekspor CPO (% volume)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="export_ratio_cpo" class="form-control input-asumsi"
                                   value="<?= round($asmp['export_ratio_cpo']*100,1) ?>" step="1" min="0" max="100">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Ekspor Palm Kernel (% volume)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="export_ratio_pk" class="form-control input-asumsi"
                                   value="<?= round($asmp['export_ratio_pk']*100,1) ?>" step="1" min="0" max="100">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text" style="font-size:0.72rem;">0% = semua jual lokal</div>
                    </div>
                </div>

                <!-- Kolom 4: Tombol -->
                <div class="col-sm-6 col-lg-3 d-flex flex-column justify-content-end">
                    <div class="d-flex flex-column gap-2">
                        <button type="button" id="btnSaveAsumsi" class="btn btn-rp btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Simpan &amp; Hitung Ulang
                        </button>
                        <button type="button" id="btnCancelAsumsi" class="btn btn-outline-secondary btn-sm">Batal</button>
                        <span id="asumsiSpinner" class="d-none">
                            <span class="spinner-border spinner-border-sm text-success"></span>
                            <span class="small text-muted ms-1">Menghitung ulang...</span>
                        </span>
                    </div>
                </div>
            </div><!-- /row -->

            <!-- ── PKS Table ──────────────────────────────────────────────── -->
            <div class="mt-3 pt-3" style="border-top:1px solid #e0e0e0;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="asumsi-group-title mb-0" style="border:none;padding:0;">
                        <i class="bi bi-building me-1"></i>Daftar PKS &amp; OER/KER
                        <span class="text-muted fw-normal" style="font-size:0.7rem;">— klik baris untuk edit, harga kosong = pakai default</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btnAddPks">
                        <i class="bi bi-plus-circle"></i> Tambah PKS
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.8rem;" id="pksTable">
                        <thead style="background:#e8f5e9;">
                            <tr>
                                <th style="width:70px">Kode</th>
                                <th>Nama PKS</th>
                                <th style="width:70px" class="text-center">OER (%)</th>
                                <th style="width:70px" class="text-center">KER (%)</th>
                                <th style="width:90px" class="text-center">CPO Rp/kg<br><span class="fw-normal text-muted" style="font-size:0.68rem;">override</span></th>
                                <th style="width:90px" class="text-center">PK Rp/kg<br><span class="fw-normal text-muted" style="font-size:0.68rem;">override</span></th>
                                <th style="width:80px" class="text-center">CPO USD/MT<br><span class="fw-normal text-muted" style="font-size:0.68rem;">override</span></th>
                                <th style="width:80px" class="text-center">PK USD/MT<br><span class="fw-normal text-muted" style="font-size:0.68rem;">override</span></th>
                                <th>Pola Invoice</th>
                                <th style="width:50px" class="text-center">Aktif</th>
                                <th style="width:60px" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="pksTableBody">
                        <?php foreach ($pks_list as $p): ?>
                            <tr data-id="<?= $p['id'] ?>" data-code="<?= htmlspecialchars($p['pks_code']) ?>">
                                <td class="fw-semibold"><?= htmlspecialchars($p['pks_code']) ?></td>
                                <td><?= htmlspecialchars($p['pks_name']) ?></td>
                                <td class="text-center"><?= round($p['oer']*100,2) ?>%</td>
                                <td class="text-center"><?= round($p['ker']*100,2) ?>%</td>
                                <td class="text-center text-muted"><?= $p['price_cpo_idr'] !== null ? number_format($p['price_cpo_idr'],0,',','.') : '—' ?></td>
                                <td class="text-center text-muted"><?= $p['price_pk_idr']  !== null ? number_format($p['price_pk_idr'],0,',','.')  : '—' ?></td>
                                <td class="text-center text-muted"><?= $p['price_cpo_usd'] !== null ? number_format($p['price_cpo_usd'],0,',','.') : '—' ?></td>
                                <td class="text-center text-muted"><?= $p['price_pk_usd']  !== null ? number_format($p['price_pk_usd'],0,',','.')  : '—' ?></td>
                                <td class="font-monospace" style="font-size:0.72rem;"><?= htmlspecialchars($p['invoice_pattern']) ?></td>
                                <td class="text-center"><?= $p['is_active'] ? '<span class="badge bg-success">Ya</span>' : '<span class="badge bg-secondary">Tidak</span>' ?></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-xs btn-outline-primary py-0 px-1 btn-edit-pks" data-id="<?= $p['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1 btn-del-pks ms-1" data-id="<?= $p['id'] ?>" title="Hapus"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add/Edit PKS -->
<div class="modal fade" id="modalPks" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#f0f7f2;">
                <h6 class="modal-title text-success"><i class="bi bi-building me-1"></i><span id="modalPksTitle">Tambah PKS</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formPks">
                    <input type="hidden" name="id"   id="pksId"   value="0">
                    <input type="hidden" name="year" id="pksYear" value="<?= $year ?>">
                    <div class="row g-2">
                        <div class="col-sm-3">
                            <label class="form-label-sm">Kode PKS <span class="text-danger">*</span></label>
                            <input type="text" name="pks_code" id="pksCode" class="form-control form-control-sm text-uppercase"
                                   placeholder="MHK" maxlength="20" required>
                            <div class="form-text" style="font-size:0.7rem;">Singkatan unik, e.g. MHK, BLG, KTM</div>
                        </div>
                        <div class="col-sm-9">
                            <label class="form-label-sm">Nama PKS <span class="text-danger">*</span></label>
                            <input type="text" name="pks_name" id="pksName" class="form-control form-control-sm"
                                   placeholder="PKS Mahakam" maxlength="100" required>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label-sm">OER (%)</label>
                            <input type="number" name="oer" id="pksOer" class="form-control form-control-sm"
                                   value="22" step="0.1" min="0" max="100">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label-sm">KER (%)</label>
                            <input type="number" name="ker" id="pksKer" class="form-control form-control-sm"
                                   value="5.2" step="0.1" min="0" max="100">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label-sm">Urutan</label>
                            <input type="number" name="sort_order" id="pksSortOrder" class="form-control form-control-sm" value="0">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label-sm">Status</label>
                            <select name="is_active" id="pksActive" class="form-select form-select-sm">
                                <option value="1">Aktif</option>
                                <option value="0">Non-aktif</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label-sm">Pola Invoice (SQL LIKE) <span class="text-danger">*</span></label>
                            <input type="text" name="invoice_pattern" id="pksPattern" class="form-control form-control-sm font-monospace"
                                   placeholder="INV-BSM-%-MHK-2026-%" required>
                            <div class="form-text" style="font-size:0.7rem;">Gunakan <code>%</code> sebagai wildcard. Contoh: <code>INV-BSM-%-MHK-<?= $year ?>-%</code></div>
                        </div>
                        <div class="col-12">
                            <hr class="my-1">
                            <div class="form-label-sm mb-1 text-muted">Override Harga (kosongkan = pakai harga default di atas)</div>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label-sm">CPO Rp/kg</label>
                            <input type="number" name="price_cpo_idr" id="pksPriceCpoIdr" class="form-control form-control-sm" step="50" min="0" placeholder="default">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label-sm">PK Rp/kg</label>
                            <input type="number" name="price_pk_idr" id="pksPricePkIdr" class="form-control form-control-sm" step="50" min="0" placeholder="default">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label-sm">CPO USD/MT</label>
                            <input type="number" name="price_cpo_usd" id="pksPriceCpoUsd" class="form-control form-control-sm" step="5" min="0" placeholder="default">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label-sm">PK USD/MT</label>
                            <input type="number" name="price_pk_usd" id="pksPricePkUsd" class="form-control form-control-sm" step="5" min="0" placeholder="default">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-rp btn-sm" id="btnSavePks">
                    <i class="bi bi-save me-1"></i>Simpan PKS
                </button>
                <span id="pksSpinner" class="d-none ms-1">
                    <span class="spinner-border spinner-border-sm text-success"></span>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Asumsi summary banner (always visible) -->
<div class="alert mb-3 py-2 px-3 small" style="background:#f0f7f2;border:1px solid #c8e6c9;border-radius:6px;">
    <div class="row g-0 align-items-center flex-wrap gap-2">
        <div class="col-auto">
            <i class="bi bi-info-circle text-success"></i>
            <strong>Asumsi <?= $year ?>:</strong>
        </div>
        <div class="col-auto">
            <span class="text-muted">Harga default:</span>
            <strong><?= number_format($asmp['price_cpo_idr'],0,',','.') ?></strong> Rp/kg CPO
            &nbsp;·&nbsp;
            <strong><?= number_format($asmp['price_pk_idr'],0,',','.') ?></strong> Rp/kg PK
        </div>
        <div class="col-auto">
            <span class="text-muted">Kurs:</span>
            <strong><?= number_format($asmp['usd_idr_rate'],0,',','.') ?></strong> USD/IDR
        </div>
        <div class="col-auto">
            <span class="text-muted">PKS aktif:</span>
            <?php foreach ($pks_list as $p): ?>
                <span class="badge bg-light text-dark border me-1">
                    <?= htmlspecialchars($p['pks_code']) ?>
                    OER <?= round($p['oer']*100,1) ?>% / KER <?= round($p['ker']*100,1) ?>%
                </span>
            <?php endforeach; ?>
        </div>
        <?php if ($asmp['export_ratio_cpo'] > 0 || $asmp['export_ratio_pk'] > 0): ?>
        <div class="col-auto">
            <span class="export-active-badge">
                <i class="bi bi-globe"></i> Ekspor:
                CPO <?= round($asmp['export_ratio_cpo']*100,0) ?>%
                · PK <?= round($asmp['export_ratio_pk']*100,0) ?>%
            </span>
        </div>
        <?php endif; ?>
        <div class="col-auto ms-auto">
            <button class="btn btn-sm btn-link text-success p-0" id="btnToggleAsumsi2" style="font-size:0.78rem;">
                <i class="bi bi-pencil-square"></i> ubah
            </button>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
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
                <label class="form-label small mb-1">PKS</label>
                <select name="pks" class="form-select form-select-sm">
                    <option value="all" <?= $pks_filter==='all'?'selected':'' ?>>Semua PKS</option>
                    <?php foreach ($pks_list as $p): ?>
                    <option value="<?= htmlspecialchars($p['pks_code']) ?>" <?= $pks_filter===$p['pks_code']?'selected':'' ?>>
                        <?= htmlspecialchars($p['pks_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">Produk</label>
                <select name="product" class="form-select form-select-sm">
                    <option value="all"   <?= $prod_filter==='all'   ?'selected':'' ?>>CPO &amp; PK</option>
                    <option value="CPO"   <?= $prod_filter==='CPO'   ?'selected':'' ?>>CPO saja</option>
                    <option value="Kernel"<?= $prod_filter==='Kernel'?'selected':'' ?>>Palm Kernel saja</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-rp btn-sm"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card kpi-card h-100">
            <div class="card-body">
                <p class="text-muted small mb-1"><i class="bi bi-cash-stack"></i> Total Pendapatan</p>
                <div class="kpi-val rp-accent" id="kpiTotalAmt"><?= 'Rp '.number_format($total_amount/1e9,1,',','.').' M' ?></div>
                <small class="text-muted">Sebelum PPN</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card orange h-100">
            <div class="card-body">
                <p class="text-muted small mb-1"><i class="bi bi-droplet-fill text-warning"></i> CPO</p>
                <div class="kpi-val" style="color:#e87722" id="kpiCpoTon"><?= fmt_ton($kpi['CPO']['kg']) ?></div>
                <small class="text-muted" id="kpiCpoAmt"><?= fmt_idr($kpi['CPO']['amount']) ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card blue h-100">
            <div class="card-body">
                <p class="text-muted small mb-1"><i class="bi bi-circle-fill text-primary"></i> Palm Kernel</p>
                <div class="kpi-val text-primary" id="kpiPkTon"><?= fmt_ton($kpi['Kernel']['kg']) ?></div>
                <small class="text-muted" id="kpiPkAmt"><?= fmt_idr($kpi['Kernel']['amount']) ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card purple h-100">
            <div class="card-body">
                <p class="text-muted small mb-1"><i class="bi bi-boxes"></i> Total Volume</p>
                <div class="kpi-val" style="color:#7b1fa2"><?= number_format($total_ton,1,',','.').' ton' ?></div>
                <small class="text-muted">CPO + PK gabungan</small>
            </div>
        </div>
    </div>
</div>

<!-- Chart -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-rp text-white py-2">
                <i class="bi bi-bar-chart-line"></i> Rencana Pendapatan Bulanan <?= $year ?> (Miliar Rp)
            </div>
            <div class="card-body">
                <canvas id="chartRevenue" height="100"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-rp text-white py-2">
                <i class="bi bi-pie-chart"></i> Komposisi Pendapatan
            </div>
            <div class="card-body">
                <canvas id="chartPie" height="160"></canvas>
                <div class="mt-2 text-center small text-muted" id="chartPriceNote">
                    CPO <?= fmt_idr($eff_cpo) ?>/kg &nbsp;|&nbsp; PK <?= fmt_idr($eff_pk) ?>/kg
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Summary Table -->
<div class="card mb-4">
    <div class="card-header bg-rp text-white py-2">
        <i class="bi bi-table"></i> Rekapitulasi Bulanan — Produksi &amp; Pendapatan <?= $year ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover table-bordered table-plan mb-0">
                <thead>
                    <tr>
                        <th rowspan="2" class="align-middle text-center">Bulan</th>
                        <th colspan="2" class="text-center">CPO</th>
                        <th colspan="2" class="text-center">Palm Kernel</th>
                        <th rowspan="2" class="align-middle text-center">Total Pendapatan</th>
                        <th rowspan="2" class="align-middle text-center">% dari Tahun</th>
                    </tr>
                    <tr>
                        <th class="text-center">Volume (ton)</th>
                        <th class="text-center">Nilai (Juta Rp)</th>
                        <th class="text-center">Volume (ton)</th>
                        <th class="text-center">Nilai (Juta Rp)</th>
                    </tr>
                </thead>
                <tbody id="monthlyTableBody">
                <?php
                $peak = [3,4,5,6,9,10,11];
                for ($m = 1; $m <= 12; $m++):
                    $row    = $monthly[$m];
                    $rowamt = $row['CPO_amt'] + $row['Kernel_amt'];
                    $pct    = $total_amount > 0 ? round($rowamt/$total_amount*100,1) : 0;
                    $is_peak = in_array($m,$peak);
                ?>
                    <tr class="<?= $is_peak?'row-peak':'' ?>" data-month="<?= $m ?>">
                        <td class="fw-semibold text-center">
                            <?= $months_id[$m-1] ?>
                            <?php if($is_peak): ?>
                                <span class="badge bg-warning text-dark ms-1" style="font-size:0.6rem">puncak</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= number_format($row['CPO_kg']/1000,    1,',','.') ?></td>
                        <td class="text-end"><?= number_format($row['CPO_amt']/1e6,    2,',','.') ?></td>
                        <td class="text-end"><?= number_format($row['Kernel_kg']/1000, 1,',','.') ?></td>
                        <td class="text-end"><?= number_format($row['Kernel_amt']/1e6, 2,',','.') ?></td>
                        <td class="text-end fw-semibold"><?= number_format($rowamt/1e6, 2,',','.') ?></td>
                        <td class="text-center">
                            <div class="d-flex align-items-center gap-1">
                                <div class="progress flex-grow-1" style="height:8px">
                                    <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                                </div>
                                <span style="font-size:0.78rem"><?= $pct ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr id="monthlyTfoot">
                        <td class="text-center">TOTAL</td>
                        <td class="text-end"><?= number_format($kpi['CPO']['kg']/1000,    1,',','.') ?></td>
                        <td class="text-end" id="tfCpoAmt"><?= number_format($kpi['CPO']['amount']/1e6, 2,',','.') ?></td>
                        <td class="text-end"><?= number_format($kpi['Kernel']['kg']/1000,    1,',','.') ?></td>
                        <td class="text-end" id="tfPkAmt"><?= number_format($kpi['Kernel']['amount']/1e6, 2,',','.') ?></td>
                        <td class="text-end" id="tfTotalAmt"><?= number_format($total_amount/1e6, 2,',','.') ?></td>
                        <td class="text-center">100%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Detail per Invoice -->
<div class="card mb-4">
    <div class="card-header bg-rp text-white py-2 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul"></i> Detail Rencana Penjualan <?= $year ?></span>
        <span class="badge bg-light text-dark"><?= count($details) ?> record</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover table-bordered mb-0" style="font-size:0.83rem">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal</th>
                        <th>No. Invoice</th>
                        <th class="text-center">PKS</th>
                        <th class="text-center">Produk</th>
                        <th class="text-end">Volume (ton)</th>
                        <th class="text-end">Harga/kg</th>
                        <th class="text-end">Nilai (Juta Rp)</th>
                        <th class="text-center">Pembeli</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody id="detailTableBody">
                <?php if(empty($details)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">
                        <i class="bi bi-inbox"></i> Belum ada data — jalankan seed SQL terlebih dahulu
                    </td></tr>
                <?php else: ?>
                <?php foreach($details as $d):
                    $pay_cls  = ['paid'=>'bg-success','partial'=>'bg-warning text-dark','pending'=>'bg-secondary'][$d['payment_status']] ?? 'bg-secondary';
                    $prod_cls = $d['product_type']==='CPO' ? 'bg-warning text-dark' : 'bg-info text-dark';
                ?>
                    <tr>
                        <td><?= date('d M Y',strtotime($d['sale_date'])) ?></td>
                        <td class="font-monospace small"><?= htmlspecialchars($d['invoice_number']) ?></td>
                        <td class="text-center"><?= pks_label($d['invoice_number'], $pks_list) ?></td>
                        <td class="text-center"><span class="badge <?= $prod_cls ?>"><?= $d['product_type'] ?></span></td>
                        <td class="text-end"><?= number_format($d['quantity_kg']/1000,1,',','.') ?></td>
                        <td class="text-end"><?= number_format($d['unit_price'],0,',','.') ?></td>
                        <td class="text-end"><?= number_format($d['total_amount']/1e6,2,',','.') ?></td>
                        <td class="text-center small"><?= htmlspecialchars($d['customer_name']) ?></td>
                        <td class="text-center"><span class="badge <?= $pay_cls ?>"><?= $d['payment_status'] ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Toast notification -->
<div id="toastRecalc" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
        <div class="toast-body" id="toastMsg">
            <i class="bi bi-check-circle-fill text-success me-1"></i> Asumsi disimpan &amp; data dihitung ulang.
        </div>
        <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
</div>

<?php
echo '</main></div></div>';
echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>';
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
?>
<script>
(function () {
    // ── Initial chart data from PHP ──────────────────────────────────────────
    const labels   = <?= $chart_labels ?>;
    let dataCPO    = <?= $chart_cpo ?>;
    let dataPK     = <?= $chart_kernel ?>;
    const tonCPO   = <?= $chart_cpo_ton ?>;
    const tonPK    = <?= $chart_pk_ton ?>;

    const barChart = new Chart(document.getElementById('chartRevenue'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'CPO (Miliar Rp)',
                    data: dataCPO,
                    backgroundColor: 'rgba(232,119,34,0.8)',
                    borderColor: '#e87722',
                    borderWidth: 1,
                    stack: 'rev'
                },
                {
                    label: 'Palm Kernel (Miliar Rp)',
                    data: dataPK,
                    backgroundColor: 'rgba(21,101,192,0.75)',
                    borderColor: '#1565c0',
                    borderWidth: 1,
                    stack: 'rev'
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        afterBody: ctx => {
                            const i = ctx[0].dataIndex;
                            return `Volume: CPO ${tonCPO[i]} ton | PK ${tonPK[i]} ton`;
                        }
                    }
                }
            },
            scales: {
                y: { stacked: true, title: { display: true, text: 'Miliar Rp' } },
                x: { stacked: true }
            }
        }
    });

    let totalCPO = dataCPO.reduce((a,b)=>a+b,0);
    let totalPK  = dataPK.reduce((a,b)=>a+b,0);

    const pieChart = new Chart(document.getElementById('chartPie'), {
        type: 'doughnut',
        data: {
            labels: ['CPO','Palm Kernel'],
            datasets: [{
                data: [totalCPO.toFixed(2), totalPK.toFixed(2)],
                backgroundColor: ['rgba(232,119,34,0.85)','rgba(21,101,192,0.8)'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: ctx => ` Rp ${ctx.parsed.toFixed(2)} Miliar (${((ctx.parsed/(totalCPO+totalPK))*100).toFixed(1)}%)`
                    }
                }
            }
        }
    });

    // ── Toggle asumsi panel ─────────────────────────────────────────────────
    function togglePanel() {
        document.getElementById('asumsiPanel').classList.toggle('show');
    }
    document.getElementById('btnToggleAsumsi').addEventListener('click', togglePanel);
    document.getElementById('btnToggleAsumsi2').addEventListener('click', togglePanel);
    document.getElementById('btnCancelAsumsi').addEventListener('click', () => {
        document.getElementById('asumsiPanel').classList.remove('show');
    });

    // ── Save main assumptions & Recalculate ─────────────────────────────────
    document.getElementById('btnSaveAsumsi').addEventListener('click', function() {
        const spinner = document.getElementById('asumsiSpinner');
        spinner.classList.remove('d-none');
        this.disabled = true;

        const fd = new FormData(document.getElementById('formAsumsi'));
        ['export_ratio_cpo','export_ratio_pk'].forEach(k => {
            fd.set(k, (parseFloat(fd.get(k)) / 100).toFixed(4));
        });
        fd.set('action', 'save');

        fetch('ajax/revenue_assumptions.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                spinner.classList.add('d-none');
                document.getElementById('btnSaveAsumsi').disabled = false;
                if (!res.ok) { alert('Error: ' + (res.error || 'Unknown')); return; }
                window.location.reload();
            })
            .catch(err => {
                spinner.classList.add('d-none');
                document.getElementById('btnSaveAsumsi').disabled = false;
                alert('Gagal menyimpan: ' + err.message);
            });
    });

    // ── PKS CRUD ────────────────────────────────────────────────────────────
    const modalPksEl = document.getElementById('modalPks');
    const modalPks   = new bootstrap.Modal(modalPksEl);
    const currentYear = <?= $year ?>;

    // Helper: render PKS table body from array of rows
    function renderPksTable(rows) {
        const colors = ['bg-primary','bg-info text-dark','bg-success','bg-warning text-dark','bg-secondary'];
        const fmt = v => v !== null && v !== '' ? Number(v).toLocaleString('id-ID') : '—';
        let html = '';
        rows.forEach((p, i) => {
            const cls = colors[i % colors.length];
            html += `<tr data-id="${p.id}" data-code="${p.pks_code}">
                <td class="fw-semibold"><span class="badge ${cls}">${p.pks_code}</span></td>
                <td>${p.pks_name}</td>
                <td class="text-center">${(parseFloat(p.oer)*100).toFixed(2)}%</td>
                <td class="text-center">${(parseFloat(p.ker)*100).toFixed(2)}%</td>
                <td class="text-center text-muted">${fmt(p.price_cpo_idr)}</td>
                <td class="text-center text-muted">${fmt(p.price_pk_idr)}</td>
                <td class="text-center text-muted">${fmt(p.price_cpo_usd)}</td>
                <td class="text-center text-muted">${fmt(p.price_pk_usd)}</td>
                <td class="font-monospace" style="font-size:0.72rem;">${p.invoice_pattern}</td>
                <td class="text-center">${p.is_active == 1
                    ? '<span class="badge bg-success">Ya</span>'
                    : '<span class="badge bg-secondary">Tidak</span>'}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-xs btn-outline-primary py-0 px-1 btn-edit-pks" data-id="${p.id}" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn btn-xs btn-outline-danger  py-0 px-1 btn-del-pks ms-1" data-id="${p.id}" title="Hapus"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`;
        });
        document.getElementById('pksTableBody').innerHTML = html;
        bindPksButtons();
    }

    // Open modal for ADD
    document.getElementById('btnAddPks').addEventListener('click', () => {
        document.getElementById('modalPksTitle').textContent = 'Tambah PKS';
        document.getElementById('pksId').value           = '0';
        document.getElementById('pksCode').value         = '';
        document.getElementById('pksName').value         = '';
        document.getElementById('pksOer').value          = '22';
        document.getElementById('pksKer').value          = '5.2';
        document.getElementById('pksSortOrder').value    = '0';
        document.getElementById('pksActive').value       = '1';
        document.getElementById('pksPattern').value      = '';
        document.getElementById('pksPriceCpoIdr').value  = '';
        document.getElementById('pksPricePkIdr').value   = '';
        document.getElementById('pksPriceCpoUsd').value  = '';
        document.getElementById('pksPricePkUsd').value   = '';
        document.getElementById('pksCode').readOnly      = false;
        modalPks.show();
    });

    // Open modal for EDIT — populate from row data
    function openEditPks(id) {
        const row = document.querySelector(`#pksTableBody tr[data-id="${id}"]`);
        if (!row) return;
        const cells = row.querySelectorAll('td');
        // fetch fresh data from server
        fetch(`ajax/revenue_assumptions.php?action=get_pks&year=${currentYear}`)
            .then(r => r.json())
            .then(res => {
                if (!res.ok) return;
                const p = res.data.find(x => x.id == id);
                if (!p) return;
                document.getElementById('modalPksTitle').textContent = 'Edit PKS';
                document.getElementById('pksId').value           = p.id;
                document.getElementById('pksCode').value         = p.pks_code;
                document.getElementById('pksName').value         = p.pks_name;
                document.getElementById('pksOer').value          = (parseFloat(p.oer)*100).toFixed(2);
                document.getElementById('pksKer').value          = (parseFloat(p.ker)*100).toFixed(2);
                document.getElementById('pksSortOrder').value    = p.sort_order;
                document.getElementById('pksActive').value       = p.is_active;
                document.getElementById('pksPattern').value      = p.invoice_pattern;
                document.getElementById('pksPriceCpoIdr').value  = p.price_cpo_idr ?? '';
                document.getElementById('pksPricePkIdr').value   = p.price_pk_idr  ?? '';
                document.getElementById('pksPriceCpoUsd').value  = p.price_cpo_usd ?? '';
                document.getElementById('pksPricePkUsd').value   = p.price_pk_usd  ?? '';
                document.getElementById('pksCode').readOnly      = true; // code is identity key
                modalPks.show();
            });
    }

    // Save PKS (add or edit)
    document.getElementById('btnSavePks').addEventListener('click', function() {
        const spinner = document.getElementById('pksSpinner');
        spinner.classList.remove('d-none');
        this.disabled = true;

        const fd = new FormData(document.getElementById('formPks'));
        // convert OER/KER pct → decimal
        fd.set('oer', (parseFloat(fd.get('oer')) / 100).toFixed(4));
        fd.set('ker', (parseFloat(fd.get('ker')) / 100).toFixed(4));
        // empty override fields stay as '' so backend converts to NULL
        fd.set('action', 'save_pks');
        fd.set('year', currentYear);

        fetch('ajax/revenue_assumptions.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                spinner.classList.add('d-none');
                document.getElementById('btnSavePks').disabled = false;
                if (!res.ok) { alert('Error: ' + (res.error || 'Unknown')); return; }
                modalPks.hide();
                renderPksTable(res.data);
            })
            .catch(err => {
                spinner.classList.add('d-none');
                document.getElementById('btnSavePks').disabled = false;
                alert('Gagal: ' + err.message);
            });
    });

    // Delete PKS
    function deletePks(id) {
        if (!confirm('Hapus PKS ini? Tindakan tidak dapat dibatalkan.')) return;
        const fd = new FormData();
        fd.set('action', 'delete_pks');
        fd.set('year', currentYear);
        fd.set('id', id);
        fetch('ajax/revenue_assumptions.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.ok) { alert('Error: ' + (res.error || 'Unknown')); return; }
                renderPksTable(res.data);
            })
            .catch(err => alert('Gagal: ' + err.message));
    }

    // Bind edit/delete buttons (called after each render)
    function bindPksButtons() {
        document.querySelectorAll('.btn-edit-pks').forEach(btn =>
            btn.addEventListener('click', () => openEditPks(btn.dataset.id)));
        document.querySelectorAll('.btn-del-pks').forEach(btn =>
            btn.addEventListener('click', () => deletePks(btn.dataset.id)));
    }

    // Bind initial PHP-rendered buttons
    bindPksButtons();

})();
</script>

<?php
echo '</body></html>';
?>
