<?php
/**
 * AJAX — Cost Plan
 * GET  action=get_assumptions   → asumsi per tahun
 * GET  action=get_lines         → semua baris biaya
 * POST action=save_assumptions  → simpan asumsi + recalculate planned_amount
 * POST action=save_line         → upsert satu baris
 * POST action=delete_line       → hapus satu baris
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

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
set_error_handler(function(int $no, string $str): bool {
    json_out(['ok' => false, 'error' => "PHP[$no]: $str"]);
    return true;
});
set_exception_handler(function(Throwable $e): void {
    json_out(['ok' => false, 'error' => $e->getMessage()]);
});

try { require_login(); } catch (Exception $e) { json_out(['ok'=>false,'error'=>'Unauthorized']); }

$db         = getDB();
$action     = $_REQUEST['action'] ?? '';
$year       = (int)($_REQUEST['year']       ?? 2026);
$company_id = (int)($_REQUEST['company_id'] ?? 1001); // PT Borneo Sawit Mandiri
$user       = $_SESSION['username'] ?? $_SESSION['email'] ?? 'user';

// ── Auto-create tables ────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS cost_plan_assumptions (
        id INT AUTO_INCREMENT PRIMARY KEY, year INT NOT NULL, company_id INT DEFAULT NULL,
        daily_wage_kebun DECIMAL(12,2) NOT NULL DEFAULT 180000,
        daily_wage_pks   DECIMAL(12,2) NOT NULL DEFAULT 200000,
        price_urea       DECIMAL(12,2) NOT NULL DEFAULT 3500,
        price_tsp        DECIMAL(12,2) NOT NULL DEFAULT 6500,
        price_kcl        DECIMAL(12,2) NOT NULL DEFAULT 7200,
        price_dolomite   DECIMAL(12,2) NOT NULL DEFAULT 2800,
        price_herbicide  DECIMAL(12,2) NOT NULL DEFAULT 85000,
        price_insecticide DECIMAL(12,2) NOT NULL DEFAULT 120000,
        price_diesel     DECIMAL(12,2) NOT NULL DEFAULT 7000,
        price_lubricant  DECIMAL(12,2) NOT NULL DEFAULT 45000,
        overhead_pct_kebun DECIMAL(6,4) NOT NULL DEFAULT 0.0800,
        overhead_pct_pks   DECIMAL(6,4) NOT NULL DEFAULT 0.1000,
        depreciation_kebun DECIMAL(15,2) NOT NULL DEFAULT 0,
        depreciation_pks   DECIMAL(15,2) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by VARCHAR(100) DEFAULT NULL,
        UNIQUE KEY uq_year_company (year, company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS cost_plan_lines (
        id INT AUTO_INCREMENT PRIMARY KEY, year INT NOT NULL,
        company_id INT DEFAULT NULL, business_unit_id INT DEFAULT NULL,
        unit_type VARCHAR(20) NOT NULL DEFAULT 'kebun',
        cost_category VARCHAR(50) NOT NULL, cost_item VARCHAR(150) NOT NULL,
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
        notes TEXT DEFAULT NULL, sort_order INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by VARCHAR(100) DEFAULT NULL,
        KEY idx_year_unit (year, unit_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── GET assumptions ───────────────────────────────────────────────────────────
if ($action === 'get_assumptions') {
    $s = $db->prepare("SELECT * FROM cost_plan_assumptions WHERE year=? AND company_id=? LIMIT 1");
    $s->execute([$year, $company_id]);
    $row = $s->fetch(PDO::FETCH_ASSOC) ?: [
        'year'=>$year,'daily_wage_kebun'=>180000,'daily_wage_pks'=>200000,
        'price_urea'=>3500,'price_tsp'=>6500,'price_kcl'=>7200,'price_dolomite'=>2800,
        'price_herbicide'=>85000,'price_insecticide'=>120000,'price_diesel'=>7000,
        'price_lubricant'=>45000,'overhead_pct_kebun'=>0.08,'overhead_pct_pks'=>0.10,
        'depreciation_kebun'=>0,'depreciation_pks'=>0,
    ];
    json_out(['ok'=>true,'data'=>$row]);
}

// ── GET lines ─────────────────────────────────────────────────────────────────
if ($action === 'get_lines') {
    $ut = $_GET['unit_type'] ?? '';
    $sql = "SELECT * FROM cost_plan_lines WHERE year=? AND company_id=?";
    $p   = [$year, $company_id];
    if ($ut) { $sql .= " AND unit_type=?"; $p[] = $ut; }
    $sql .= " ORDER BY unit_type, cost_category, sort_order, id";
    $s = $db->prepare($sql);
    $s->execute($p);
    json_out(['ok'=>true,'data'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── SAVE assumptions + recalculate all volume-based lines ────────────────────
if ($action === 'save_assumptions' && $_SERVER['REQUEST_METHOD']==='POST') { try {

    $company_id = (int)($_POST['company_id'] ?? 1001);
    $f = [
        'daily_wage_kebun'  => (float)($_POST['daily_wage_kebun']   ?? 180000),
        'daily_wage_pks'    => (float)($_POST['daily_wage_pks']     ?? 200000),
        'price_urea'        => (float)($_POST['price_urea']         ?? 3500),
        'price_tsp'         => (float)($_POST['price_tsp']          ?? 6500),
        'price_kcl'         => (float)($_POST['price_kcl']          ?? 7200),
        'price_dolomite'    => (float)($_POST['price_dolomite']     ?? 2800),
        'price_herbicide'   => (float)($_POST['price_herbicide']    ?? 85000),
        'price_insecticide' => (float)($_POST['price_insecticide']  ?? 120000),
        'price_diesel'      => (float)($_POST['price_diesel']       ?? 7000),
        'price_lubricant'   => (float)($_POST['price_lubricant']    ?? 45000),
        'overhead_pct_kebun'=> min(1,max(0,(float)($_POST['overhead_pct_kebun'] ?? 0.08))),
        'overhead_pct_pks'  => min(1,max(0,(float)($_POST['overhead_pct_pks']  ?? 0.10))),
        'depreciation_kebun'=> (float)($_POST['depreciation_kebun'] ?? 0),
        'depreciation_pks'  => (float)($_POST['depreciation_pks']  ?? 0),
    ];

    $db->prepare("INSERT INTO cost_plan_assumptions
        (year,company_id,daily_wage_kebun,daily_wage_pks,price_urea,price_tsp,price_kcl,price_dolomite,
         price_herbicide,price_insecticide,price_diesel,price_lubricant,
         overhead_pct_kebun,overhead_pct_pks,depreciation_kebun,depreciation_pks,updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
         daily_wage_kebun=VALUES(daily_wage_kebun), daily_wage_pks=VALUES(daily_wage_pks),
         price_urea=VALUES(price_urea), price_tsp=VALUES(price_tsp), price_kcl=VALUES(price_kcl),
         price_dolomite=VALUES(price_dolomite), price_herbicide=VALUES(price_herbicide),
         price_insecticide=VALUES(price_insecticide), price_diesel=VALUES(price_diesel),
         price_lubricant=VALUES(price_lubricant),
         overhead_pct_kebun=VALUES(overhead_pct_kebun), overhead_pct_pks=VALUES(overhead_pct_pks),
         depreciation_kebun=VALUES(depreciation_kebun), depreciation_pks=VALUES(depreciation_pks),
         updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP
    ")->execute([$year, $company_id,
        $f['daily_wage_kebun'],$f['daily_wage_pks'],
        $f['price_urea'],$f['price_tsp'],$f['price_kcl'],$f['price_dolomite'],
        $f['price_herbicide'],$f['price_insecticide'],$f['price_diesel'],$f['price_lubricant'],
        $f['overhead_pct_kebun'],$f['overhead_pct_pks'],
        $f['depreciation_kebun'],$f['depreciation_pks'],$user,
    ]);

    // Recalculate planned_amount for lines where unit_price is driven by assumptions
    // Map: cost_item keywords → assumption price field
    $recalc_map = [
        ['keyword'=>'Urea',         'unit_type'=>'kebun','field'=>'price_urea'],
        ['keyword'=>'TSP',          'unit_type'=>'kebun','field'=>'price_tsp'],
        ['keyword'=>'KCl',          'unit_type'=>'kebun','field'=>'price_kcl'],
        ['keyword'=>'Dolomit',      'unit_type'=>'kebun','field'=>'price_dolomite'],
        ['keyword'=>'Herbisida',    'unit_type'=>'kebun','field'=>'price_herbicide'],
        ['keyword'=>'Insektisida',  'unit_type'=>'kebun','field'=>'price_insecticide'],
        ['keyword'=>'Solar',        'unit_type'=>null,   'field'=>'price_diesel'],
        ['keyword'=>'Pelumas',      'unit_type'=>null,   'field'=>'price_lubricant'],
    ];

    // Labor lines (unit = HK) — scoped to company
    foreach (['kebun'=>'daily_wage_kebun','pks'=>'daily_wage_pks'] as $utype => $wfield) {
        $db->prepare("UPDATE cost_plan_lines
            SET unit_price=?, planned_amount=volume*?
            WHERE year=? AND company_id=? AND unit_type=? AND unit='HK'"
        )->execute([$f[$wfield],$f[$wfield],$year,$company_id,$utype]);
    }
    // Fertilizer & chemical & fuel lines by keyword — scoped to company
    foreach ($recalc_map as $m) {
        $price = $f[$m['field']];
        $sql   = "UPDATE cost_plan_lines SET unit_price=?, planned_amount=volume*?
                  WHERE year=? AND company_id=? AND cost_item LIKE ?";
        $p     = [$price,$price,$year,$company_id,'%'.$m['keyword'].'%'];
        if ($m['unit_type']) { $sql .= " AND unit_type=?"; $p[] = $m['unit_type']; }
        $db->prepare($sql)->execute($p);
    }

    // KPI summary — scoped to company
    $kpi = $db->prepare("SELECT unit_type, SUM(planned_amount) AS planned, SUM(actual_amount) AS actual
        FROM cost_plan_lines WHERE year=? AND company_id=? GROUP BY unit_type");
    $kpi->execute([$year, $company_id]);
    $summary = [];
    foreach ($kpi->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $summary[$r['unit_type']] = ['planned'=>(float)$r['planned'],'actual'=>(float)$r['actual']];
    }
    json_out(['ok'=>true,'summary'=>$summary]);

} catch (Exception $e) { json_out(['ok'=>false,'error'=>$e->getMessage()]); } }

// ── SAVE / UPSERT one line ────────────────────────────────────────────────────
if ($action === 'save_line' && $_SERVER['REQUEST_METHOD']==='POST') { try {

    $company_id = (int)($_POST['company_id'] ?? 1001);
    $id         = (int)($_POST['id'] ?? 0);
    $volume   = (float)($_POST['volume']   ?? 0);
    $uprice   = (float)($_POST['unit_price'] ?? 0);
    $planned  = round($volume * $uprice, 0);
    $actual   = (float)($_POST['actual_amount'] ?? 0);
    $months   = [];
    foreach (['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'] as $m) {
        $months[] = (float)($_POST[$m] ?? 0);
    }

    $fields = [
        'unit_type'     => $_POST['unit_type']     ?? 'kebun',
        'cost_category' => $_POST['cost_category'] ?? 'other',
        'cost_item'     => $_POST['cost_item']     ?? '',
        'unit'          => $_POST['unit']          ?? '',
        'volume'        => $volume,
        'unit_price'    => $uprice,
        'planned_amount'=> $planned,
        'actual_amount' => $actual,
        'notes'         => $_POST['notes']         ?? '',
        'sort_order'    => (int)($_POST['sort_order'] ?? 0),
    ];

    if ($id > 0) {
        $db->prepare("UPDATE cost_plan_lines SET
            unit_type=?,cost_category=?,cost_item=?,unit=?,volume=?,unit_price=?,
            planned_amount=?,actual_amount=?,
            jan=?,feb=?,mar=?,apr=?,mei=?,jun=?,jul=?,agu=?,sep=?,okt=?,nov=?,des=?,
            notes=?,sort_order=?,updated_by=?,updated_at=CURRENT_TIMESTAMP
            WHERE id=? AND year=? AND company_id=?
        ")->execute(array_merge(array_values($fields), $months, [$user,$id,$year,$company_id]));
    } else {
        $db->prepare("INSERT INTO cost_plan_lines
            (year,company_id,unit_type,cost_category,cost_item,unit,volume,unit_price,
             planned_amount,actual_amount,
             jan,feb,mar,apr,mei,jun,jul,agu,sep,okt,nov,des,notes,sort_order,updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute(array_merge([$year,$company_id], array_values($fields), $months, [$user]));
        $id = (int)$db->lastInsertId();
    }
    json_out(['ok'=>true,'id'=>$id,'planned_amount'=>$planned]);

} catch (Exception $e) { json_out(['ok'=>false,'error'=>$e->getMessage()]); } }

// ── DELETE one line ───────────────────────────────────────────────────────────
if ($action === 'delete_line' && $_SERVER['REQUEST_METHOD']==='POST') { try {
    $company_id = (int)($_POST['company_id'] ?? 1001);
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) json_out(['ok'=>false,'error'=>'ID tidak valid']);
    $db->prepare("DELETE FROM cost_plan_lines WHERE id=? AND year=? AND company_id=?")->execute([$id,$year,$company_id]);
    json_out(['ok'=>true]);
} catch (Exception $e) { json_out(['ok'=>false,'error'=>$e->getMessage()]); } }

json_out(['ok'=>false,'error'=>'Invalid action']);
