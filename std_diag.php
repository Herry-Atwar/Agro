<?php
/**
 * Standards Diagnostic — upload to cloud, visit once, then DELETE immediately.
 * Tests every layer that standards.php depends on.
 * No login required — delete after use.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/plain; charset=utf-8');

echo "=== standards.php DIAGNOSTIC ===\n";
echo "Time    : " . date('Y-m-d H:i:s') . "\n";
echo "PHP     : " . PHP_VERSION . "\n";
echo "Server  : " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";
echo "Host    : " . ($_SERVER['HTTP_HOST'] ?? 'unknown') . "\n";
echo "Self    : " . ($_SERVER['PHP_SELF'] ?? 'unknown') . "\n\n";

// ── 1. File existence checks ──────────────────────────────────────────────────
echo "--- FILE EXISTS ---\n";
$files = [
    'standards.php',
    'config/database.php',
    'config/standards.php',
    'includes/functions.php',
    'includes/header.php',
    'includes/auth.php',
    'includes/footer.php',
    'includes/lang.php',
];
$base = __DIR__ . '/';
foreach ($files as $f) {
    $path = $base . $f;
    $exists = file_exists($path);
    $size   = $exists ? filesize($path) . ' bytes' : '—';
    echo ($exists ? '[OK]' : '[MISSING]') . " {$f} ({$size})\n";
}
echo "\n";

// ── 2. config/database.php load ───────────────────────────────────────────────
echo "--- DATABASE CONFIG ---\n";
try {
    require_once $base . 'config/database.php';
    echo "[OK] config/database.php loaded\n";
} catch (Throwable $e) {
    echo "[FAIL] config/database.php: " . $e->getMessage() . "\n";
    exit;
}

// ── 3. DB connection ──────────────────────────────────────────────────────────
echo "--- DB CONNECTION ---\n";
try {
    $db = getDB();
    echo "[OK] getDB() succeeded\n";
    $row = $db->query("SELECT VERSION() AS v")->fetch();
    echo "[OK] MySQL version: " . ($row['v'] ?? '?') . "\n";
} catch (Throwable $e) {
    echo "[FAIL] getDB(): " . $e->getMessage() . "\n";
    exit;
}
echo "\n";

// ── 4. config/standards.php load ─────────────────────────────────────────────
echo "--- STANDARDS CONFIG ---\n";
try {
    require_once $base . 'config/standards.php';
    echo "[OK] config/standards.php loaded\n";
} catch (Throwable $e) {
    echo "[FAIL] config/standards.php: " . $e->getMessage() . "\n";
    exit;
}

// ── 5. Constants & functions defined ─────────────────────────────────────────
echo "--- CONSTANTS & FUNCTIONS ---\n";
echo (defined('AGRO_STANDARDS') ? "[OK]" : "[FAIL]") . " AGRO_STANDARDS defined\n";
echo (function_exists('agro_std_check')      ? "[OK]" : "[FAIL]") . " agro_std_check()\n";
echo (function_exists('agro_std_categories') ? "[OK]" : "[FAIL]") . " agro_std_categories()\n";
echo (function_exists('agro_std_by_category')? "[OK]" : "[FAIL]") . " agro_std_by_category()\n";

if (defined('AGRO_STANDARDS')) {
    $cnt = count(AGRO_STANDARDS);
    echo "[OK] AGRO_STANDARDS has {$cnt} entries\n";
    $cats = agro_std_categories();
    echo "[OK] Categories: " . implode(', ', $cats) . "\n";
}
echo "\n";

// ── 6. includes/functions.php load ───────────────────────────────────────────
echo "--- FUNCTIONS ---\n";
try {
    require_once $base . 'includes/functions.php';
    echo "[OK] includes/functions.php loaded\n";
} catch (Throwable $e) {
    echo "[FAIL] includes/functions.php: " . $e->getMessage() . "\n";
}
echo "\n";

// ── 7. Session test ───────────────────────────────────────────────────────────
echo "--- SESSION ---\n";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "Session status : " . session_status() . " (2=active)\n";
echo "Session ID     : " . (session_id() ?: '(none)') . "\n";
echo "user_id in session: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '(not set — not logged in)') . "\n";
echo "\n";

// ── 8. includes/auth.php load ─────────────────────────────────────────────────
echo "--- AUTH ---\n";
try {
    require_once $base . 'includes/auth.php';
    echo "[OK] includes/auth.php loaded\n";
    echo "is_logged_in(): " . (is_logged_in() ? 'YES' : 'NO') . "\n";
} catch (Throwable $e) {
    echo "[FAIL] includes/auth.php: " . $e->getMessage() . "\n";
}
echo "\n";

// ── 9. PHP constant compatibility ────────────────────────────────────────────
echo "--- PHP COMPAT ---\n";
echo "short_open_tag : " . ini_get('short_open_tag') . "\n";
echo "mbstring       : " . (extension_loaded('mbstring') ? 'yes' : 'NO') . "\n";
echo "PDO MySQL      : " . (extension_loaded('pdo_mysql') ? 'yes' : 'NO') . "\n";
echo "\n";

// ── DONE ──────────────────────────────────────────────────────────────────────
echo "=== DONE — delete std_diag.php after reading ===\n";
