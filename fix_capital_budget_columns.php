<?php
/**
 * Fix Activity Budget Items Table
 * Adds missing columns: approval_level, approved_by, approval_date,
 * expected_roi, payback_period_months, start_depreciation_date,
 * actual_purchase_date, notes
 *
 * Run this ONCE, then delete or move the file.
 */

require_once 'config/database.php';

$db = getDB();

echo "<!DOCTYPE html><html><head><title>Fix Activity Budget Columns</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:30px;max-width:800px}
pre{background:#f5f5f5;padding:15px;border-radius:6px;border-left:4px solid #007bff}
.ok{color:#28a745;font-weight:bold}.skip{color:#6c757d}.err{color:#dc3545;font-weight:bold}
a.btn{display:inline-block;margin-top:16px;padding:10px 22px;background:#007bff;color:#fff;text-decoration:none;border-radius:5px}</style>";
echo "</head><body><h2>Fix Activity Budget Items Table</h2><pre>";

// Columns to add: [name, definition]
$columns = [
    'approved_by'             => "VARCHAR(100) NULL AFTER status",
    'approval_date'           => "DATE NULL AFTER approved_by",
    'approval_level'          => "TINYINT(1) NULL AFTER approval_date",
    'actual_purchase_date'    => "DATE NULL AFTER planned_purchase_date",
    'start_depreciation_date' => "DATE NULL AFTER actual_purchase_date",
    'expected_roi'            => "DECIMAL(8,2) NULL AFTER salvage_value",
    'payback_period_months'   => "INT NULL AFTER expected_roi",
    'notes'                   => "TEXT NULL AFTER business_case",
];

// Read current columns
try {
    $existing = $db->query("DESCRIBE account_budget_items")->fetchAll(PDO::FETCH_COLUMN);
    echo "Current columns: " . implode(', ', $existing) . "\n\n";
} catch (PDOException $e) {
    echo "<span class='err'>ERROR: Cannot read table — " . $e->getMessage() . "</span>\n";
    echo "Make sure the account_budget_items table exists first.</pre></body></html>";
    exit;
}

$added = 0;
foreach ($columns as $col => $definition) {
    if (in_array($col, $existing)) {
        echo "<span class='skip'>SKIP  $col — already exists</span>\n";
        continue;
    }
    try {
        $db->exec("ALTER TABLE account_budget_items ADD COLUMN `$col` $definition");
        echo "<span class='ok'>ADDED $col</span>\n";
        $added++;
    } catch (PDOException $e) {
        echo "<span class='err'>ERROR $col — " . $e->getMessage() . "</span>\n";
    }
}

echo "\n" . ($added > 0 ? "<span class='ok'>Done — $added column(s) added.</span>" : "<span class='skip'>Nothing to add — all columns already present.</span>") . "\n";
echo "</pre>";
echo '<a class="btn" href="activity_budget_capital.php">← Back to Activity Budget</a>';
echo "</body></html>";
