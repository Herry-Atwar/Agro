<?php
/**
 * Debug helper — Rencana Biaya setup diagnostics
 * Run: https://inodesain.com/agro/debug_cost_plan.php
 * DELETE after use.
 */
// Must be first — before any require
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Flush ob_start from database.php
require_once 'config/database.php';
while (ob_get_level() > 0) ob_end_clean();

header('Content-Type: text/plain; charset=utf-8');

echo "=== Rencana Biaya — Cloud Debug ===\n\n";

try {
    $db = getDB();
    echo "[OK] DB connected\n";
} catch (Exception $e) {
    die("[FAIL] DB: " . $e->getMessage() . "\n");
}

// MySQL version
$ver = $db->query("SELECT VERSION()")->fetchColumn();
echo "[OK] MySQL version: $ver\n";

// sql_mode
$mode = $db->query("SELECT @@sql_mode")->fetchColumn();
echo "[OK] sql_mode: $mode\n\n";

// ── Step 1: CREATE cost_plan_assumptions ──────────────────────────────────────
echo "--- Step 1: CREATE cost_plan_assumptions ---\n";
try {
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
    echo "[OK] cost_plan_assumptions created/exists\n";
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
}

// ── Step 2: CREATE cost_plan_lines ────────────────────────────────────────────
echo "\n--- Step 2: CREATE cost_plan_lines ---\n";
try {
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
    echo "[OK] cost_plan_lines created/exists\n";
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
}

// ── Step 3: INSERT one assumption row ─────────────────────────────────────────
echo "\n--- Step 3: INSERT one assumption ---\n";
try {
    $db->prepare("INSERT IGNORE INTO cost_plan_assumptions
        (year,company_id,daily_wage_kebun,daily_wage_pks,
         price_urea,price_tsp,price_kcl,price_dolomite,
         price_herbicide,price_insecticide,price_diesel,price_lubricant,
         overhead_pct_kebun,overhead_pct_pks,depreciation_kebun,depreciation_pks,updated_by)
        VALUES (2026,1001,180000,200000,3500,6500,7200,2800,85000,120000,7000,45000,
                0.08,0.10,1200000000,800000000,'system')"
    )->execute();
    echo "[OK] assumption inserted\n";
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
}

// ── Step 4: INSERT one test line ──────────────────────────────────────────────
echo "\n--- Step 4: INSERT one test cost_plan_line ---\n";
try {
    $stmt = $db->prepare("INSERT INTO cost_plan_lines
        (year,company_id,unit_type,cost_category,cost_item,unit,volume,unit_price,
         planned_amount,actual_amount,
         jan,feb,mar,apr,mei,jun,jul,agu,sep,okt,nov,des,
         notes,sort_order,updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        2026, 1001, 'kebun', 'labor', 'TEST ITEM', 'HK',
        100, 180000, 18000000, 18000000,
        1500000,1500000,4500000,4500000,4500000,4500000,3000000,1500000,4500000,4500000,4500000,3000000,
        'test note', 0, 'system'
    ]);
    $test_id = $db->lastInsertId();
    echo "[OK] test line inserted, id=$test_id\n";

    // Clean up test row
    $db->prepare("DELETE FROM cost_plan_lines WHERE id=?")->execute([$test_id]);
    echo "[OK] test line cleaned up\n";
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
}

// ── Step 5: Check existing data ───────────────────────────────────────────────
echo "\n--- Step 5: Row counts ---\n";
$ca = $db->query("SELECT COUNT(*) FROM cost_plan_assumptions")->fetchColumn();
$cl = $db->query("SELECT COUNT(*) FROM cost_plan_lines")->fetchColumn();
echo "cost_plan_assumptions: $ca rows\n";
echo "cost_plan_lines      : $cl rows\n";

echo "\n=== All steps done. If all [OK], run setup_cost_plan.php ===\n";
echo "DELETE this file when done.\n";
