<?php
/**
 * Cloud diagnostic — lists missing tables & critical errors
 * DELETE THIS FILE after diagnosis.
 */
// Bypass auth for diagnosis
define('BYPASS_AUTH', true);

// Force cloud DB
define('DB_HOST',    'srv1982.hstgr.io');
define('DB_NAME',    'u208932211_inodesain');
define('DB_USER',    'u208932211_admin');
define('DB_PASS',    '12345Abcde@@@');
define('DB_CHARSET', 'utf8mb4');

header('Content-Type: text/plain; charset=utf-8');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "DB CONNECTION: OK\n";
    echo "Host: " . DB_HOST . "\n";
    echo "Database: " . DB_NAME . "\n\n";
} catch (Exception $e) {
    die("DB CONNECTION FAILED: " . $e->getMessage() . "\n");
}

// All tables the app needs
$required = [
    'users', 'user_activity_log',
    'companies', 'business_units', 'divisions', 'blocks', 'planting_years',
    'plant_varieties', 'workers', 'activities',
    'block_area_components', 'area_component_config',
    'general_ledger_accounts', 'account_groups',
    'journal_entries', 'journal_entry_lines',
    'harvest_plans', 'harvest_realizations', 'harvest_productivity', 'harvest_quality',
    'ffb_deliveries',
    'nursery_stock', 'nursery_production', 'nursery_distribution',
    'daily_activity_plans',
    'mill_processing', 'mill_production', 'mill_quality',
    'inventory_cpo', 'inventory_kernel', 'inventory_materials',
    'sales_contracts', 'delivery_orders', 'product_deliveries', 'sales_invoices',
    'delivery_receiving', 'delivery_monitoring', 'delivery_complaints', 'payment_receives',
    'purchase_requisitions', 'purchase_orders', 'grn', 'grn_items',
    'budgets', 'budget_plans', 'activity_norms',
    'cash_bank_accounts', 'cash_transactions',
    'exchange_rates', 'bank_reconciliation',
    'plasma_farmers', 'plasma_ffb_deliveries', 'plasma_payments',
    'areal_statements',
];

// Get existing tables
$existing = [];
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $existing[] = $t;
}

echo "=== EXISTING TABLES (" . count($existing) . ") ===\n";
foreach ($existing as $t) echo "  ✓ $t\n";

echo "\n=== MISSING REQUIRED TABLES ===\n";
$missing = array_diff($required, $existing);
if (empty($missing)) {
    echo "  All required tables present!\n";
} else {
    foreach ($missing as $t) echo "  ✗ MISSING: $t\n";
}

// Check users table
echo "\n=== USERS TABLE ===\n";
if (in_array('users', $existing)) {
    $users = $pdo->query("SELECT id, name, email, role, is_active FROM users LIMIT 5")->fetchAll();
    if (empty($users)) {
        echo "  WARNING: users table is EMPTY — no one can log in!\n";
    } else {
        foreach ($users as $u) {
            echo "  id={$u['id']} email={$u['email']} role={$u['role']} active={$u['is_active']}\n";
        }
    }
} else {
    echo "  MISSING\n";
}

// Check PHP version
echo "\n=== SERVER INFO ===\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";
echo "Host: " . ($_SERVER['HTTP_HOST'] ?? 'unknown') . "\n";
echo "Doc root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown') . "\n";

// Check includes path
echo "\n=== PATH CHECKS ===\n";
$checks = [
    'includes/header.php'   => __DIR__ . '/includes/header.php',
    'includes/lang.php'     => __DIR__ . '/includes/lang.php',
    'includes/auth.php'     => __DIR__ . '/includes/auth.php',
    'includes/functions.php'=> __DIR__ . '/includes/functions.php',
    'config/database.php'   => __DIR__ . '/config/database.php',
    'lang/en.php'           => __DIR__ . '/lang/en.php',
    'lang/id.php'           => __DIR__ . '/lang/id.php',
];
foreach ($checks as $label => $path) {
    echo "  $label: " . (file_exists($path) ? "OK" : "MISSING!") . "\n";
}

// Check .htaccess
echo "\n=== .HTACCESS ===\n";
$htaccess = file_get_contents(__DIR__ . '/.htaccess');
if (strpos($htaccess, 'RewriteBase') !== false) {
    preg_match('/RewriteBase\s+(.+)/', $htaccess, $m);
    echo "  RewriteBase: " . ($m[1] ?? '?') . "\n";
}

echo "\nDone. DELETE cloud_check.php after reading.\n";
