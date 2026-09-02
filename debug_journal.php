<?php
/**
 * Journal Entry Debug v3 — DELETE after use
 * Simulates the exact PHP execution order of journal_entries.php
 */
require_once 'config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: text/plain; charset=utf-8');
$db = getDB();
$ok = '✓'; $fail = '✗';

echo "=== journal_entries.php execution trace ===\n\n";

// --- Step 1: user defaults ---
echo "STEP 1: user defaults\n";
$uid = $_SESSION['user_id'] ?? null;
echo "  session user_id = " . ($uid ?? 'NULL') . "\n";
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    echo $ok . " users query OK — " . ($u ? "user found" : "no user row (defaults = null)") . "\n";
} catch (Exception $e) {
    echo $fail . " users query FAILED: " . $e->getMessage() . "\n";
}

// --- Step 2: currency rates map ---
echo "\nSTEP 2: currency rates map\n";
try {
    $stmt = $db->prepare("
        SELECT from_currency, rate FROM currency_rates
        WHERE rate_date = (
            SELECT MAX(rate_date) FROM currency_rates cr2
            WHERE cr2.from_currency = currency_rates.from_currency AND cr2.to_currency = 'IDR'
        ) AND to_currency = 'IDR'
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    echo $ok . " currency rates map OK (" . count($rows) . " currencies)\n";
} catch (Exception $e) {
    echo $fail . " currency rates map FAILED: " . $e->getMessage() . "\n";
}

// --- Step 3: stats ---
echo "\nSTEP 3: stats\n";
try {
    $r = $db->query("SELECT COUNT(*) as total_entries,
        SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) as draft_count,
        SUM(CASE WHEN status='posted' THEN 1 ELSE 0 END) as posted_count,
        SUM(CASE WHEN total_debit != total_credit THEN 1 ELSE 0 END) as unbalanced_count
        FROM journal_entries")->fetch();
    echo $ok . " stats OK\n";
} catch (Exception $e) {
    echo $fail . " stats FAILED: " . $e->getMessage() . "\n";
}

// --- Step 4: GL accounts ---
echo "\nSTEP 4: GL accounts\n";
try {
    $rows = $db->query("SELECT id, account_code, account_name, account_type FROM general_ledger_accounts WHERE is_active = 1 ORDER BY account_code")->fetchAll();
    echo $ok . " GL accounts OK (" . count($rows) . " rows)\n";
} catch (Exception $e) {
    echo $fail . " GL accounts FAILED: " . $e->getMessage() . "\n";
}

// --- Step 5: activities ---
echo "\nSTEP 5: activities\n";
try {
    $rows = $db->query("SELECT id, activity_code, activity_name FROM activities WHERE is_active = 1 ORDER BY activity_code")->fetchAll();
    echo $ok . " activities OK (" . count($rows) . " rows)\n";
} catch (Exception $e) {
    echo $fail . " activities FAILED: " . $e->getMessage() . "\n";
}

// --- Step 6: companies ---
echo "\nSTEP 6: companies\n";
try {
    $rows = $db->query("SELECT company_id, company_name FROM companies WHERE status='Active' ORDER BY company_name")->fetchAll();
    echo $ok . " companies OK (" . count($rows) . " rows)\n";
} catch (Exception $e) {
    echo $fail . " companies FAILED: " . $e->getMessage() . "\n";
}

// --- Step 7: business_units ---
echo "\nSTEP 7: business_units\n";
try {
    $rows = $db->query("SELECT business_unit_id, unit_name FROM business_units WHERE status='Active' ORDER BY unit_name")->fetchAll();
    echo $ok . " business_units OK (" . count($rows) . " rows)\n";
} catch (Exception $e) {
    echo $fail . " business_units FAILED: " . $e->getMessage() . "\n";
}

// --- Step 8: divisions ---
echo "\nSTEP 8: divisions\n";
try {
    $rows = $db->query("SELECT division_id, division_name, business_unit_id FROM divisions WHERE status='Active' ORDER BY division_name")->fetchAll();
    echo $ok . " divisions OK (" . count($rows) . " rows)\n";
} catch (Exception $e) {
    echo $fail . " divisions FAILED: " . $e->getMessage() . "\n";
}

// --- Step 9: blocks ---
echo "\nSTEP 9: blocks\n";
try {
    $rows = $db->query("SELECT block_id AS id, CONCAT(block_code, ' — ', COALESCE(block_name,'')) AS name, division_id FROM blocks ORDER BY block_code")->fetchAll();
    echo $ok . " blocks OK (" . count($rows) . " rows)\n";
} catch (Exception $e) {
    echo $fail . " blocks FAILED: " . $e->getMessage() . "\n";
}

// --- Step 10: available currencies ---
echo "\nSTEP 10: available currencies\n";
try {
    $rows = $db->query("SELECT cr.from_currency, cr.rate, cr.rate_date
        FROM currency_rates cr
        INNER JOIN (
            SELECT from_currency, MAX(rate_date) as max_date
            FROM currency_rates WHERE to_currency = 'IDR' GROUP BY from_currency
        ) latest ON cr.from_currency = latest.from_currency AND cr.rate_date = latest.max_date
        WHERE cr.to_currency = 'IDR' ORDER BY cr.from_currency")->fetchAll();
    echo $ok . " available currencies OK (" . count($rows) . " rows)\n";
} catch (Exception $e) {
    echo $fail . " available currencies FAILED: " . $e->getMessage() . "\n";
}

// --- Step 11: header.php include ---
echo "\nSTEP 11: header.php include\n";
try {
    if (!file_exists(__DIR__ . '/includes/header.php')) {
        echo $fail . " includes/header.php NOT FOUND\n";
    } else {
        echo $ok . " includes/header.php exists\n";
    }
    // Check for auth.php inside header chain
    if (!file_exists(__DIR__ . '/includes/functions.php')) {
        echo $fail . " includes/functions.php NOT FOUND\n";
    } else {
        echo $ok . " includes/functions.php exists\n";
    }
} catch (Exception $e) {
    echo $fail . " header check FAILED: " . $e->getMessage() . "\n";
}

// --- Step 12: check PHP error log tail ---
echo "\nSTEP 12: PHP error_reporting\n";
echo "  display_errors = " . ini_get('display_errors') . "\n";
echo "  error_reporting = " . ini_get('error_reporting') . "\n";
$logfile = ini_get('error_log');
echo "  error_log = " . ($logfile ?: '(not set)') . "\n";
if ($logfile && file_exists($logfile)) {
    $lines = array_slice(file($logfile), -10);
    echo "\n  Last 10 lines of PHP error log:\n";
    foreach ($lines as $line) echo "  " . trim($line) . "\n";
}

echo "\n=== Done — DELETE this file ===\n";
