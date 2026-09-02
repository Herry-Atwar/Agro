<?php
/**
 * Plasma Module Diagnostic — run once on cloud, then delete.
 */
require_once 'config/database.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = getDB();
echo "<h2>Plasma Module Diagnostics</h2><pre>";

// ── 1. PHP version ─────────────────────────────────────────
echo "PHP version : " . PHP_VERSION . "\n";
echo "MySQL version: " . $db->query("SELECT VERSION()")->fetchColumn() . "\n\n";

// ── 2. Required files ───────────────────────────────────────
$files = [
    'includes/functions.php',
    'includes/auth.php',
    'includes/lang.php',
    'includes/header.php',
    'includes/footer.php',
    'includes/plasma_accounting.php',
    'lang/en.php',
    'lang/id.php',
];
echo "=== Required Files ===\n";
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    echo ($f) . " : " . (file_exists($path) ? "OK" : "MISSING <<<") . "\n";
}
echo "\n";

// ── 3. Tables ───────────────────────────────────────────────
$needed = [
    'plasma_farmers',
    'plasma_ffb_deliveries',
    'plasma_payments',
    'plasma_payment_deliveries',
    'plasma_payment_journals',
    'companies',
    'business_units',
    'journal_entries',
    'journal_entry_lines',
    'general_ledger_accounts',
];
echo "=== Tables ===\n";
foreach ($needed as $t) {
    $exists = $db->query("SHOW TABLES LIKE '$t'")->fetchColumn();
    echo str_pad($t, 35) . ": " . ($exists ? "OK" : "MISSING <<<") . "\n";
}
echo "\n";

// ── 4. plasma_payments columns ──────────────────────────────
echo "=== plasma_payments columns ===\n";
try {
    $cols = $db->query("SHOW COLUMNS FROM plasma_payments")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $c) echo "  $c\n";
    if (!in_array('journal_posted', $cols)) echo "  journal_posted : MISSING <<<\n";
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// ── 5. Try auto-migrate from plasma_payments.php ────────────
echo "=== Auto-migrate test ===\n";
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS plasma_payments (
            id               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
            payment_no       VARCHAR(30)     NOT NULL UNIQUE,
            farmer_id        INT UNSIGNED    NOT NULL,
            period_start     DATE            NOT NULL,
            period_end       DATE            NOT NULL,
            total_kg         DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            ffb_price_per_kg DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
            gross_amount     DECIMAL(18,2)   GENERATED ALWAYS AS (total_kg * ffb_price_per_kg) STORED,
            deduction_pct    DECIMAL(5,2)    NOT NULL DEFAULT 30.00,
            loan_deduction   DECIMAL(18,2)   GENERATED ALWAYS AS (ROUND(total_kg * ffb_price_per_kg * deduction_pct / 100, 2)) STORED,
            other_deduction  DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
            net_payout       DECIMAL(18,2)   GENERATED ALWAYS AS (
                                 ROUND(total_kg * ffb_price_per_kg, 2)
                               - ROUND(total_kg * ffb_price_per_kg * deduction_pct / 100, 2)
                               - other_deduction
                             ) STORED,
            credit_before    DECIMAL(18,2)   NULL,
            credit_after     DECIMAL(18,2)   NULL,
            status           ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
            journal_posted   TINYINT(1)      NOT NULL DEFAULT 0,
            payment_date     DATE            NULL,
            payment_ref      VARCHAR(100)    NULL,
            notes            TEXT            NULL,
            created_by       VARCHAR(50)     NULL,
            updated_by       VARCHAR(50)     NULL,
            created_at       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_farmer (farmer_id),
            INDEX idx_period (period_start, period_end),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  CREATE TABLE plasma_payments  : OK\n";
} catch (Exception $e) {
    echo "  CREATE TABLE plasma_payments  : ERROR — " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE plasma_payments ADD COLUMN journal_posted TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    echo "  ADD COLUMN journal_posted     : OK (added)\n";
} catch (Exception $e) {
    // error 1060 = duplicate column = already exists = fine
    if (strpos($e->getMessage(), '1060') !== false || stripos($e->getMessage(), 'Duplicate') !== false) {
        echo "  ADD COLUMN journal_posted     : OK (already exists)\n";
    } else {
        echo "  ADD COLUMN journal_posted     : ERROR — " . $e->getMessage() . "\n";
    }
}

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS plasma_payment_journals (
            id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
            payment_id   INT UNSIGNED    NOT NULL,
            journal_id   BIGINT UNSIGNED NOT NULL,
            journal_type ENUM('plasma_ffb_purchase','plasma_loan_repayment','plasma_payment_transfer') NOT NULL,
            created_at   TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payment_type (payment_id, journal_type),
            INDEX idx_payment (payment_id),
            INDEX idx_journal (journal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  CREATE TABLE payment_journals : OK\n";
} catch (Exception $e) {
    echo "  CREATE TABLE payment_journals : ERROR — " . $e->getMessage() . "\n";
}

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS plasma_payment_deliveries (
            payment_id  INT UNSIGNED NOT NULL,
            delivery_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (payment_id, delivery_id),
            INDEX idx_delivery (delivery_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  CREATE TABLE payment_deliveries: OK\n";
} catch (Exception $e) {
    echo "  CREATE TABLE payment_deliveries: ERROR — " . $e->getMessage() . "\n";
}

// ── 6. Quick query test ─────────────────────────────────────
echo "\n=== Query test ===\n";
try {
    $r = $db->query("SELECT COUNT(*) FROM plasma_farmers")->fetchColumn();
    echo "  plasma_farmers rows     : $r\n";
} catch (Exception $e) {
    echo "  plasma_farmers query    : ERROR — " . $e->getMessage() . "\n";
}
try {
    $r = $db->query("SELECT COUNT(*) FROM plasma_payments")->fetchColumn();
    echo "  plasma_payments rows    : $r\n";
} catch (Exception $e) {
    echo "  plasma_payments query   : ERROR — " . $e->getMessage() . "\n";
}

echo "\nDone.\n</pre>";
?>
