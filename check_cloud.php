<?php
/**
 * Cloud Diagnostic Check — agro
 * Upload to public_html/agro/check_cloud.php
 * Open in browser, then DELETE this file after checking.
 */

// Show all errors for diagnosis
error_reporting(E_ALL);
ini_set('display_errors', 1);

$ok   = '✅';
$fail = '❌';
$warn = '⚠️';

echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Cloud Diagnostic – agro</title>
<style>
  body { font-family: monospace; font-size: 14px; padding: 20px; background:#f7f8fa; color:#1f2328; }
  h2   { font-size: 16px; margin: 24px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
  .ok  { color: #15803d; }
  .fail{ color: #dc2626; }
  .warn{ color: #d97706; }
  pre  { background:#fff; border:1px solid #e5e7eb; padding:10px; border-radius:4px; overflow-x:auto; }
  .box { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:16px; margin-bottom:16px; }
</style></head><body>';

echo '<h1>🔍 Cloud Diagnostic — agro</h1>';
echo '<p style="color:#57606a">Run this on the cloud server to identify what\'s broken. <strong>Delete after use.</strong></p>';

// ── 1. PHP Info ──────────────────────────────────────────────────────────────
echo '<div class="box"><h2>1. PHP Environment</h2>';
echo '<b>PHP Version:</b> ' . phpversion() . '<br>';
echo '<b>Server:</b> ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . '<br>';
echo '<b>Document Root:</b> ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown') . '<br>';
echo '<b>Script Path:</b> ' . __FILE__ . '<br>';
echo '<b>display_errors:</b> ' . ini_get('display_errors') . '<br>';
echo '<b>memory_limit:</b> ' . ini_get('memory_limit') . '<br>';
echo '<b>max_execution_time:</b> ' . ini_get('max_execution_time') . 's<br>';

$required_ext = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'session'];
echo '<br><b>Required Extensions:</b><br>';
foreach ($required_ext as $ext) {
    $loaded = extension_loaded($ext);
    echo '&nbsp;&nbsp;' . ($loaded ? '<span class="ok">✅' : '<span class="fail">❌') . ' ' . $ext . '</span><br>';
}
echo '</div>';

// ── 2. File & Directory Checks ───────────────────────────────────────────────
echo '<div class="box"><h2>2. Critical Files</h2>';
$files = [
    'index.php',
    '.htaccess',
    'config/database.php',
    'includes/header.php',
    'includes/footer.php',
    'includes/functions.php',
    'login.php',
];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    if (file_exists($path)) {
        $size = round(filesize($path) / 1024, 1);
        echo '<span class="ok">✅ ' . $f . '</span> <span style="color:#57606a">(' . $size . ' KB)</span><br>';
    } else {
        echo '<span class="fail">❌ ' . $f . ' — NOT FOUND</span><br>';
    }
}
echo '</div>';

// ── 3. Database Connection ───────────────────────────────────────────────────
echo '<div class="box"><h2>3. Database Connection</h2>';
$db_config_file = __DIR__ . '/config/database.php';
if (!file_exists($db_config_file)) {
    echo '<span class="fail">❌ config/database.php not found</span>';
} else {
    // Read and show which credentials are active (without exposing password)
    $db_content = file_get_contents($db_config_file);

    // Extract active (uncommented) defines
    preg_match_all("/^define\('(DB_\w+)',\s*'([^']*)'\)/m", $db_content, $matches);
    echo '<b>Active DB settings in config/database.php:</b><br>';
    foreach ($matches[1] as $i => $key) {
        $val = ($key === 'DB_PASS') ? str_repeat('*', strlen($matches[2][$i])) : htmlspecialchars($matches[2][$i]);
        echo '&nbsp;&nbsp;<b>' . $key . ':</b> ' . $val . '<br>';
    }

    // Warn if still pointing to localhost/root
    if (strpos($db_content, "define('DB_USER', 'root')") !== false) {
        echo '<br><span class="warn">⚠️ Still using <b>root</b> user — this is the local XAMPP config, not the production one!</span><br>';
        echo '<span class="warn">⚠️ You need to update config/database.php on the server with the production credentials.</span><br>';
    }

    // Try actual connection
    echo '<br><b>Connection test:</b><br>';
    try {
        require_once $db_config_file;
        $db = getDB();
        echo '<span class="ok">✅ Database connected successfully!</span><br>';

        // List tables
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo '<br><b>Tables found (' . count($tables) . '):</b><br>';
        if (empty($tables)) {
            echo '<span class="fail">❌ No tables found — database may be empty or wrong DB selected</span><br>';
        } else {
            $key_tables = ['companies', 'business_units', 'divisions', 'blocks', 'users', 'planting_years'];
            foreach ($key_tables as $t) {
                $exists = in_array($t, $tables);
                echo '&nbsp;&nbsp;' . ($exists ? '<span class="ok">✅' : '<span class="fail">❌') . ' ' . $t . '</span><br>';
            }
            $others = array_diff($tables, $key_tables);
            if (!empty($others)) {
                echo '&nbsp;&nbsp;<span style="color:#57606a">+ ' . count($others) . ' more: ' . implode(', ', $others) . '</span><br>';
            }
        }
    } catch (Exception $e) {
        echo '<span class="fail">❌ Connection failed: ' . htmlspecialchars($e->getMessage()) . '</span><br>';
    }
}
echo '</div>';

// ── 4. Session Test ──────────────────────────────────────────────────────────
echo '<div class="box"><h2>4. Session Test</h2>';
session_start();
$_SESSION['test_key'] = 'works_' . time();
if (isset($_SESSION['test_key'])) {
    echo '<span class="ok">✅ Sessions working</span><br>';
    echo '<b>Session save path:</b> ' . session_save_path() . '<br>';
    echo '<b>Session ID:</b> ' . session_id() . '<br>';
} else {
    echo '<span class="fail">❌ Sessions not working</span><br>';
}
echo '</div>';

// ── 5. includes/functions.php spot check ────────────────────────────────────
echo '<div class="box"><h2>5. Includes Check</h2>';
$inc_files = ['includes/functions.php', 'includes/footer.php', 'includes/header.php'];
foreach ($inc_files as $f) {
    $path = __DIR__ . '/' . $f;
    if (file_exists($path)) {
        // Check for BOM
        $raw = file_get_contents($path, false, null, 0, 3);
        $has_bom = ($raw === "\xEF\xBB\xBF");
        if ($has_bom) {
            echo '<span class="fail">❌ ' . $f . ' has BOM (Byte Order Mark) — will cause header issues!</span><br>';
        } else {
            echo '<span class="ok">✅ ' . $f . ' — no BOM</span><br>';
        }
    } else {
        echo '<span class="fail">❌ ' . $f . ' — missing</span><br>';
    }
}
echo '</div>';

echo '<div class="box" style="background:#fef2f2;border-color:#fecaca;">
<b style="color:#991b1b">⚠️ SECURITY REMINDER</b><br>
Delete this file from the server immediately after use!<br>
<code>public_html/agro/check_cloud.php</code>
</div>';

echo '</body></html>';
