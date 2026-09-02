<?php
/**
 * diagnose.php — erpAgro Cloud Diagnostics
 * Upload to inodesain.com/agro/diagnose.php, run once, then DELETE.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Do NOT require header/auth — we need this to run before anything else
$ok   = '<span style="color:#15803d">✅';
$fail = '<span style="color:#dc2626">❌';
$warn = '<span style="color:#d97706">⚠️';
$end  = '</span>';

echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>erpAgro — Cloud Diagnostics</title>
<style>
 body{font-family:monospace;font-size:13px;padding:24px;background:#f7f8fa;color:#1f2328;max-width:900px;margin:auto}
 h1{font-size:18px;margin-bottom:4px} h2{font-size:14px;margin:20px 0 6px;border-bottom:1px solid #d1d5db;padding-bottom:3px}
 .box{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:16px 20px;margin-bottom:16px}
 .ok{color:#15803d} .fail{color:#dc2626} .warn{color:#d97706}
 pre{background:#f3f4f6;padding:10px;border-radius:4px;overflow-x:auto;font-size:12px}
 .red-box{background:#fef2f2;border-color:#fca5a5}
 .tag{display:inline-block;background:#e5e7eb;border-radius:3px;padding:1px 7px;font-size:11px;margin:1px}
</style></head><body>';

echo '<h1>🔍 erpAgro — Cloud Diagnostics</h1>';
echo '<p style="color:#57606a;margin-bottom:20px"><strong>⚠️ Delete this file immediately after use!</strong> It exposes server internals.</p>';

// ── 1. Environment ────────────────────────────────────────────────────────────
echo '<div class="box"><h2>1. PHP Environment</h2>';
$php_ver = phpversion();
$php_ok  = version_compare($php_ver, '7.4', '>=');
echo 'PHP Version: ' . ($php_ok ? $ok : $fail) . ' ' . $php_ver . $end . '<br>';
echo 'Server Software: ' . htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . '<br>';
echo 'Document Root: ' . htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? 'unknown') . '<br>';
echo 'Script: ' . htmlspecialchars(__FILE__) . '<br>';
echo 'HTTP_HOST: ' . htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'n/a') . '<br>';
echo 'display_errors: ' . ini_get('display_errors') . '<br>';
echo 'error_reporting: ' . ini_get('error_reporting') . '<br>';
echo 'memory_limit: ' . ini_get('memory_limit') . '<br>';
echo 'max_execution_time: ' . ini_get('max_execution_time') . 's<br>';
echo 'session.save_path: ' . (ini_get('session.save_path') ?: '<em>default</em>') . '<br>';

echo '<br><b>Required Extensions:</b><br>';
foreach (['pdo','pdo_mysql','mbstring','json','session','openssl'] as $ext) {
    $loaded = extension_loaded($ext);
    echo '&nbsp;&nbsp;' . ($loaded ? $ok : $fail) . ' ' . $ext . $end . '<br>';
}
echo '</div>';

// ── 2. Database ───────────────────────────────────────────────────────────────
echo '<div class="box"><h2>2. Database Connection</h2>';

// Manually inline the cloud credentials so this file can test DB without
// pulling in the full config stack (which might itself fail)
$cloud_host = 'localhost'; // Hostinger internal — always localhost from same server
$cloud_db   = 'u208932211_inodesain';
$cloud_user = 'u208932211_admin';
$cloud_pass = '12345Abcde@@@';

echo 'Trying: <b>' . $cloud_user . '@' . $cloud_host . '/' . $cloud_db . '</b><br><br>';
try {
    $pdo = new PDO(
        "mysql:host={$cloud_host};dbname={$cloud_db};charset=utf8mb4",
        $cloud_user, $cloud_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    echo $ok . ' Connected successfully!' . $end . '<br>';

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo '<br><b>Tables (' . count($tables) . '):</b><br>';
    $required = ['companies','business_units','divisions','blocks','users','planting_years'];
    foreach ($required as $t) {
        $exists = in_array($t, $tables);
        echo '&nbsp;&nbsp;' . ($exists ? $ok : $fail) . ' ' . $t . $end . '<br>';
    }
    $extra = array_diff($tables, $required);
    if ($extra) echo '<br>&nbsp;&nbsp;<span style="color:#57606a">+ ' . count($extra) . ' more: ' . implode(', ', $extra) . '</span><br>';

    // Check users table has at least one row
    try {
        $cnt = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        echo '<br>' . ($cnt > 0 ? $ok : $warn) . ' users table: <b>' . $cnt . '</b> rows' . $end . '<br>';
        if ($cnt == 0) echo $warn . ' No users found — you must import the database!' . $end . '<br>';
    } catch (Exception $e) {
        echo $fail . ' users table error: ' . htmlspecialchars($e->getMessage()) . $end . '<br>';
    }

} catch (PDOException $e) {
    echo $fail . ' Connection FAILED: ' . htmlspecialchars($e->getMessage()) . $end . '<br>';
    echo '<br><b>Common causes:</b><br>';
    echo '&nbsp;&nbsp;• Database host <b>srv1982.hstgr.io</b> may be wrong — check Hostinger panel<br>';
    echo '&nbsp;&nbsp;• DB user <b>' . $cloud_user . '</b> may not have privileges<br>';
    echo '&nbsp;&nbsp;• Database <b>' . $cloud_db . '</b> may not exist or tables not imported<br>';
    echo '&nbsp;&nbsp;• Firewall may block outbound MySQL from this server<br>';
}
echo '</div>';

// ── 3. Critical Files ─────────────────────────────────────────────────────────
echo '<div class="box"><h2>3. Critical Files</h2>';
$files = [
    'index.php','login.php','.htaccess',
    'config/database.php',
    'includes/header.php','includes/footer.php',
    'includes/functions.php','includes/auth.php','includes/lang.php',
    'lang/en.php','lang/id.php',
];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    if (file_exists($path)) {
        $size = round(filesize($path)/1024,1);
        // BOM check
        $raw = file_get_contents($path, false, null, 0, 3);
        $bom = ($raw === "\xEF\xBB\xBF");
        echo ($bom ? $warn : $ok) . ' ' . $f . ' (' . $size . ' KB)' . ($bom ? ' <b>⚠️ HAS BOM!</b>' : '') . $end . '<br>';
    } else {
        echo $fail . ' ' . $f . ' — NOT FOUND' . $end . '<br>';
    }
}
echo '</div>';

// ── 4. .htaccess ─────────────────────────────────────────────────────────────
echo '<div class="box"><h2>4. .htaccess Analysis</h2>';
$ht = __DIR__ . '/.htaccess';
if (file_exists($ht)) {
    $content = file_get_contents($ht);
    echo $ok . ' .htaccess exists' . $end . '<br>';
    if (strpos($content,'RewriteBase /agro/') !== false)
        echo $ok . ' RewriteBase /agro/ — correct' . $end . '<br>';
    else
        echo $fail . ' RewriteBase /agro/ NOT found — redirect loops possible' . $end . '<br>';
    if (preg_match('/RewriteCond.*HTTPS.*off/i', $content) && preg_match('/^[^#]*RewriteRule.*https/mi', $content))
        echo $ok . ' HTTPS redirect active' . $end . '<br>';
    else
        echo $warn . ' HTTPS redirect commented out (OK if no SSL)' . $end . '<br>';
    // check for php_value in case server is LiteSpeed (ignores php_value in .htaccess)
    if (strpos($content,'php_value') !== false || strpos($content,'php_flag') !== false) {
        echo $warn . ' php_value/php_flag directives found — may cause 500 error on LiteSpeed/Nginx. Consider removing them.' . $end . '<br>';
    }
} else {
    echo $fail . ' .htaccess NOT found — routing will fail' . $end . '<br>';
}
echo '</div>';

// ── 5. Session ────────────────────────────────────────────────────────────────
echo '<div class="box"><h2>5. Session Test</h2>';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['diag_test'] = 'ok_' . time();
if (isset($_SESSION['diag_test'])) {
    echo $ok . ' Sessions working' . $end . '<br>';
    echo 'Session save path: ' . (session_save_path() ?: '<em>default</em>') . '<br>';
    echo 'Session ID: ' . session_id() . '<br>';
    echo 'Session name: ' . session_name() . '<br>';
} else {
    echo $fail . ' Sessions NOT working — check session.save_path permissions' . $end . '<br>';
}
echo '</div>';

// ── 6. login.php redirect issue ───────────────────────────────────────────────
echo '<div class="box"><h2>6. Login Redirect Check</h2>';
$login = __DIR__ . '/login.php';
if (file_exists($login)) {
    $src = file_get_contents($login);
    if (strpos($src, "redirect('index.php')") !== false || strpos($src, "header('Location: index.php')") !== false) {
        echo $warn . ' login.php redirects to <b>index.php</b> (relative) — on cloud this may go to domain root instead of /agro/index.php' . $end . '<br>';
        echo '&nbsp;&nbsp;→ Fix: use <b>header("Location: index.php")</b> — Apache should resolve relative correctly when RewriteBase is set.<br>';
    } else {
        echo $ok . ' login.php redirect looks OK' . $end . '<br>';
    }
}
echo '</div>';

// ── 7. auth.php redirect issue ────────────────────────────────────────────────
echo '<div class="box"><h2>7. Auth Redirect Check</h2>';
$auth = __DIR__ . '/includes/auth.php';
if (file_exists($auth)) {
    $src = file_get_contents($auth);
    if (strpos($src, "header('Location: login.php')") !== false) {
        echo $warn . ' auth.php redirects to <b>login.php</b> (relative). If the current page is in a subdirectory this will 404.' . $end . '<br>';
        echo '&nbsp;&nbsp;→ Check if any pages live in subdirectories that include header.php with a relative path.<br>';
    } else {
        echo $ok . ' auth.php redirect: OK' . $end . '<br>';
    }
    // Check require_login path logic
    if (strpos($src, "\$login_path = (basename") !== false) {
        echo $ok . ' require_login() path logic present' . $end . '<br>';
    }
}
echo '</div>';

// ── 8. PHP error log ─────────────────────────────────────────────────────────
echo '<div class="box"><h2>8. PHP Error Log (last 20 lines)</h2>';
$log_candidates = [
    ini_get('error_log'),
    __DIR__ . '/logs/php_error.log',
    dirname($_SERVER['DOCUMENT_ROOT']) . '/logs/php_error.log',
    '/var/log/php_errors.log',
];
$found_log = false;
foreach ($log_candidates as $log) {
    if ($log && file_exists($log) && is_readable($log)) {
        $found_log = true;
        $lines = array_slice(file($log), -20);
        echo '<b>File:</b> ' . htmlspecialchars($log) . '<br>';
        echo '<pre>' . htmlspecialchars(implode('', $lines) ?: '(empty)') . '</pre>';
        break;
    }
}
if (!$found_log) {
    echo $warn . ' No readable PHP error log found. Errors are being silently suppressed.' . $end . '<br>';
    echo 'Candidates checked:<br>';
    foreach ($log_candidates as $l) { if ($l) echo '&nbsp;&nbsp;' . htmlspecialchars($l) . '<br>'; }
}
echo '</div>';

// ── 9. Summary & Recommended Fixes ───────────────────────────────────────────
echo '<div class="box"><h2>9. Most Common Fixes for "localhost OK, cloud broken"</h2>';
echo '<ol>
<li><b>Database not imported</b> — Export local <code>agro</code> DB from phpMyAdmin and import into <code>u208932211_inodesain</code> on Hostinger.</li>
<li><b>Wrong DB host</b> — Hostinger often uses <code>localhost</code> internally even though the external hostname is listed. Check Hostinger → MySQL → "Connection details".</li>
<li><b>LiteSpeed + php_value in .htaccess</b> → causes 500 error. Remove or wrap in <code>&lt;IfModule mod_php8.c&gt;</code>.</li>
<li><b>Session path not writable</b> — Hostinger sometimes restricts /tmp; check session.save_path.</li>
<li><b>display_errors=Off</b> → you see blank page with no clue. Temporarily add to top of index.php:<br>
  <code>error_reporting(E_ALL); ini_set("display_errors","1");</code></li>
<li><b>.htaccess blocked</b> — Hostinger shared hosting: AllowOverride may be None for some dirs.</li>
<li><b>Files not uploaded</b> — new files (like presentation.php) may not exist on cloud yet. Re-sync via FileZilla/cPanel.</li>
<li><b>PHP version mismatch</b> — local might be PHP 8.x while cloud is PHP 7.x (or vice versa). Set PHP version in Hostinger → PHP Config.</li>
</ol>';
echo '</div>';

echo '<div class="box red-box"><b style="color:#991b1b">⚠️ DELETE THIS FILE AFTER USE</b><br>
rm public_html/agro/diagnose.php<br>
It exposes DB credentials and server internals.</div>';
echo '</body></html>';
