<?php
/**
 * AJAX endpoint — Revenue Assumptions + PKS Management
 *
 * Actions:
 *   GET  action=get          → asumsi utama untuk tahun tertentu
 *   GET  action=get_pks      → daftar PKS untuk tahun tertentu
 *   POST action=save         → simpan asumsi utama + recalculate
 *   POST action=save_pks     → upsert satu baris PKS
 *   POST action=delete_pks   → hapus satu baris PKS
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// ── Clean ALL output buffers (database.php ob_start + host injection) ─────────
while (ob_get_level() > 0) ob_end_clean();
ob_start();

error_reporting(0);
@ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function json_out(array $data): void {
    $json = json_encode($data);
    while (ob_get_level() > 0) ob_end_clean();
    echo $json;
    exit;
}

set_error_handler(function(int $errno, string $errstr): bool {
    json_out(['ok' => false, 'error' => "PHP[$errno]: $errstr"]);
    return true;
});
set_exception_handler(function(Throwable $e): void {
    json_out(['ok' => false, 'error' => $e->getMessage()]);
});

try {
    require_login();
} catch (Exception $e) {
    json_out(['ok' => false, 'error' => 'Unauthorized']);
}

$db     = getDB();
$action = $_REQUEST['action'] ?? 'get';
$year   = (int)($_REQUEST['year'] ?? date('Y'));

// ── Auto-create tables ────────────────────────────────────────────────────────
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS revenue_assumptions (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            year             YEAR          NOT NULL,
            price_cpo_idr    DECIMAL(12,2) NOT NULL DEFAULT 12500.00,
            price_pk_idr     DECIMAL(12,2) NOT NULL DEFAULT 6200.00,
            usd_idr_rate     DECIMAL(10,2) NOT NULL DEFAULT 16000.00,
            price_cpo_usd    DECIMAL(10,2) NOT NULL DEFAULT 820.00,
            price_pk_usd     DECIMAL(10,2) NOT NULL DEFAULT 390.00,
            export_ratio_cpo DECIMAL(5,4)  NOT NULL DEFAULT 0.0000,
            export_ratio_pk  DECIMAL(5,4)  NOT NULL DEFAULT 0.0000,
            updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by       VARCHAR(100)  DEFAULT NULL,
            UNIQUE KEY uq_year (year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS revenue_assumption_pks (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            year             YEAR          NOT NULL,
            pks_code         VARCHAR(20)   NOT NULL  COMMENT 'Kode singkat, e.g. MHK',
            pks_name         VARCHAR(100)  NOT NULL  COMMENT 'Nama lengkap PKS',
            oer              DECIMAL(6,4)  NOT NULL DEFAULT 0.2200 COMMENT 'Oil Extraction Rate',
            ker              DECIMAL(6,4)  NOT NULL DEFAULT 0.0520 COMMENT 'Kernel Extraction Rate',
            price_cpo_idr    DECIMAL(12,2) DEFAULT NULL COMMENT 'Override harga CPO lokal (NULL = pakai harga utama)',
            price_pk_idr     DECIMAL(12,2) DEFAULT NULL COMMENT 'Override harga PK lokal (NULL = pakai harga utama)',
            price_cpo_usd    DECIMAL(10,2) DEFAULT NULL COMMENT 'Override harga CPO ekspor (NULL = pakai harga utama)',
            price_pk_usd     DECIMAL(10,2) DEFAULT NULL COMMENT 'Override harga PK ekspor (NULL = pakai harga utama)',
            invoice_pattern  VARCHAR(100)  NOT NULL  COMMENT 'SQL LIKE pattern, e.g. INV-BSM-%-MHK-%',
            is_active        TINYINT(1)    NOT NULL DEFAULT 1,
            sort_order       INT           NOT NULL DEFAULT 0,
            UNIQUE KEY uq_year_code (year, pks_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Seed default PKS rows for missing years
    foreach ([2025, 2026, 2027] as $y) {
        $db->prepare("INSERT IGNORE INTO revenue_assumption_pks
            (year,pks_code,pks_name,oer,ker,invoice_pattern,is_active,sort_order) VALUES
            (?,   'MHK',   'PKS Mahakam',  0.2200, 0.0520, ?, 1, 1),
            (?,   'BLG',   'PKS Bulungan', 0.2100, 0.0500, ?, 1, 2)
        ")->execute([
            $y, "INV-BSM-%-MHK-$y-%",
            $y, "INV-BSM-%-BLG-$y-%",
        ]);
    }
} catch (Exception $e) { /* table already exists */ }

// ─── GET main assumptions ─────────────────────────────────────────────────────
if ($action === 'get') {
    $stmt = $db->prepare("SELECT * FROM revenue_assumptions WHERE year = ? LIMIT 1");
    $stmt->execute([$year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $row = [
            'year'            => $year,
            'price_cpo_idr'   => 12500,
            'price_pk_idr'    => 6200,
            'usd_idr_rate'    => 16000,
            'price_cpo_usd'   => 820,
            'price_pk_usd'    => 390,
            'export_ratio_cpo'=> 0,
            'export_ratio_pk' => 0,
        ];
    }
    json_out(['ok' => true, 'data' => $row]);
}

// ─── GET PKS list (all rows including inactive, for edit modal) ───────────────
if ($action === 'get_pks') {
    $stmt = $db->prepare("SELECT * FROM revenue_assumption_pks WHERE year = ? ORDER BY sort_order, id");
    $stmt->execute([$year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Cast numeric strings for JS
    foreach ($rows as &$r) {
        $r['oer'] = (float)$r['oer'];
        $r['ker'] = (float)$r['ker'];
        $r['is_active']  = (int)$r['is_active'];
        $r['sort_order'] = (int)$r['sort_order'];
    }
    json_out(['ok' => true, 'data' => $rows]);
}

// ─── SAVE main assumptions + recalculate all PKS ─────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') { try {

    $base = [
        'price_cpo_idr'    => (float)($_POST['price_cpo_idr']    ?? 12500),
        'price_pk_idr'     => (float)($_POST['price_pk_idr']     ?? 6200),
        'usd_idr_rate'     => (float)($_POST['usd_idr_rate']     ?? 16000),
        'price_cpo_usd'    => (float)($_POST['price_cpo_usd']    ?? 820),
        'price_pk_usd'     => (float)($_POST['price_pk_usd']     ?? 390),
        'export_ratio_cpo' => min(1, max(0, (float)($_POST['export_ratio_cpo'] ?? 0))),
        'export_ratio_pk'  => min(1, max(0, (float)($_POST['export_ratio_pk']  ?? 0))),
        'updated_by'       => $_SESSION['username'] ?? $_SESSION['email'] ?? 'user',
    ];

    $db->prepare("
        INSERT INTO revenue_assumptions
            (year,price_cpo_idr,price_pk_idr,usd_idr_rate,price_cpo_usd,price_pk_usd,
             export_ratio_cpo,export_ratio_pk,updated_by)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            price_cpo_idr=VALUES(price_cpo_idr), price_pk_idr=VALUES(price_pk_idr),
            usd_idr_rate=VALUES(usd_idr_rate),   price_cpo_usd=VALUES(price_cpo_usd),
            price_pk_usd=VALUES(price_pk_usd),   export_ratio_cpo=VALUES(export_ratio_cpo),
            export_ratio_pk=VALUES(export_ratio_pk), updated_by=VALUES(updated_by),
            updated_at=CURRENT_TIMESTAMP
    ")->execute([
        $year,
        $base['price_cpo_idr'], $base['price_pk_idr'],
        $base['usd_idr_rate'],  $base['price_cpo_usd'], $base['price_pk_usd'],
        $base['export_ratio_cpo'], $base['export_ratio_pk'], $base['updated_by'],
    ]);

    // Recalculate all active PKS rows for this year
    $pks_rows = $db->prepare("SELECT * FROM revenue_assumption_pks WHERE year=? AND is_active=1");
    $pks_rows->execute([$year]);
    $all_pks = $pks_rows->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_pks as $pks) {
        // Effective prices: use PKS override if set, otherwise fall back to base
        $cpo_idr = $pks['price_cpo_idr'] !== null ? (float)$pks['price_cpo_idr'] : $base['price_cpo_idr'];
        $pk_idr  = $pks['price_pk_idr']  !== null ? (float)$pks['price_pk_idr']  : $base['price_pk_idr'];
        $cpo_usd = $pks['price_cpo_usd'] !== null ? (float)$pks['price_cpo_usd'] : $base['price_cpo_usd'];
        $pk_usd  = $pks['price_pk_usd']  !== null ? (float)$pks['price_pk_usd']  : $base['price_pk_usd'];

        $eff_cpo = $cpo_usd * $base['usd_idr_rate'] / 1000 * $base['export_ratio_cpo']
                 + $cpo_idr * (1 - $base['export_ratio_cpo']);
        $eff_pk  = $pk_usd  * $base['usd_idr_rate'] / 1000 * $base['export_ratio_pk']
                 + $pk_idr  * (1 - $base['export_ratio_pk']);

        $pat = $pks['invoice_pattern'];
        $db->prepare("UPDATE sales SET unit_price=?, total_amount=quantity_kg*?
            WHERE YEAR(sale_date)=? AND product_type='CPO' AND updated_by='seed'
            AND invoice_number LIKE ?")->execute([$eff_cpo, $eff_cpo, $year, $pat]);
        $db->prepare("UPDATE sales SET unit_price=?, total_amount=quantity_kg*?
            WHERE YEAR(sale_date)=? AND product_type='Kernel' AND updated_by='seed'
            AND invoice_number LIKE ?")->execute([$eff_pk, $eff_pk, $year, $pat]);
    }

    // KPI totals
    $kpi_rows = $db->prepare("SELECT product_type, SUM(quantity_kg) AS total_kg,
        SUM(total_amount) AS total_amount FROM sales
        WHERE YEAR(sale_date)=? AND updated_by='seed' GROUP BY product_type");
    $kpi_rows->execute([$year]);
    $kpi = ['CPO'=>['kg'=>0,'amount'=>0],'Kernel'=>['kg'=>0,'amount'=>0]];
    foreach ($kpi_rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($kpi[$r['product_type']])) {
            $kpi[$r['product_type']]['kg']     = (float)$r['total_kg'];
            $kpi[$r['product_type']]['amount'] = (float)$r['total_amount'];
        }
    }

    json_out([
        'ok'          => true,
        'kpi'         => $kpi,
        'total_amount'=> $kpi['CPO']['amount'] + $kpi['Kernel']['amount'],
    ]);
} catch (Exception $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()]);
} }

// ─── SAVE / UPSERT one PKS row ────────────────────────────────────────────────
if ($action === 'save_pks' && $_SERVER['REQUEST_METHOD'] === 'POST') { try {

    $id      = (int)($_POST['id'] ?? 0);
    $code    = strtoupper(trim($_POST['pks_code']    ?? ''));
    $name    = trim($_POST['pks_name']               ?? '');
    $oer     = (float)($_POST['oer']                 ?? 0.22);
    $ker     = (float)($_POST['ker']                 ?? 0.052);
    $pattern = trim($_POST['invoice_pattern']        ?? '');
    $active  = (int)($_POST['is_active']             ?? 1);
    $order   = (int)($_POST['sort_order']            ?? 0);
    $updated = $_SESSION['username'] ?? $_SESSION['email'] ?? 'user';

    // Optional per-PKS price overrides (empty string → NULL = use base price)
    $cpo_idr = $_POST['price_cpo_idr'] !== '' ? (float)$_POST['price_cpo_idr'] : null;
    $pk_idr  = $_POST['price_pk_idr']  !== '' ? (float)$_POST['price_pk_idr']  : null;
    $cpo_usd = $_POST['price_cpo_usd'] !== '' ? (float)$_POST['price_cpo_usd'] : null;
    $pk_usd  = $_POST['price_pk_usd']  !== '' ? (float)$_POST['price_pk_usd']  : null;

    if (!$code || !$name || !$pattern) {
        json_out(['ok' => false, 'error' => 'Kode, nama, dan pola invoice wajib diisi']);
    }

    if ($id > 0) {
        // UPDATE existing row
        $db->prepare("UPDATE revenue_assumption_pks SET
            pks_code=?, pks_name=?, oer=?, ker=?,
            price_cpo_idr=?, price_pk_idr=?, price_cpo_usd=?, price_pk_usd=?,
            invoice_pattern=?, is_active=?, sort_order=?
            WHERE id=? AND year=?
        ")->execute([$code,$name,$oer,$ker,$cpo_idr,$pk_idr,$cpo_usd,$pk_usd,
                     $pattern,$active,$order,$id,$year]);
    } else {
        // INSERT new row
        $db->prepare("INSERT INTO revenue_assumption_pks
            (year,pks_code,pks_name,oer,ker,price_cpo_idr,price_pk_idr,
             price_cpo_usd,price_pk_usd,invoice_pattern,is_active,sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$year,$code,$name,$oer,$ker,$cpo_idr,$pk_idr,
                     $cpo_usd,$pk_usd,$pattern,$active,$order]);
        $id = (int)$db->lastInsertId();
    }

    // Return updated full list
    $stmt = $db->prepare("SELECT * FROM revenue_assumption_pks WHERE year=? ORDER BY sort_order,id");
    $stmt->execute([$year]);
    json_out(['ok' => true, 'id' => $id, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()]);
} }

// ─── DELETE one PKS row ───────────────────────────────────────────────────────
if ($action === 'delete_pks' && $_SERVER['REQUEST_METHOD'] === 'POST') { try {

    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'ID tidak valid']);

    // Count remaining active PKS — prevent deleting last one
    $cnt = $db->prepare("SELECT COUNT(*) FROM revenue_assumption_pks WHERE year=? AND is_active=1 AND id != ?");
    $cnt->execute([$year, $id]);
    if ((int)$cnt->fetchColumn() < 1) {
        json_out(['ok' => false, 'error' => 'Minimal harus ada 1 PKS aktif']);
    }

    $db->prepare("DELETE FROM revenue_assumption_pks WHERE id=? AND year=?")->execute([$id, $year]);

    $stmt = $db->prepare("SELECT * FROM revenue_assumption_pks WHERE year=? ORDER BY sort_order,id");
    $stmt->execute([$year]);
    json_out(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()]);
} }

json_out(['ok' => false, 'error' => 'Invalid action']);
