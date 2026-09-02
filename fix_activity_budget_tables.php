<?php
/**
 * Fix Activity Budget Tables
 * Ensures activity_budget_plans and activity_budget_monthly tables exist
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "<!DOCTYPE html>";
    echo "<html><head><title>Fix Activity Budget Tables</title>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 3px solid #007bff; padding-bottom: 10px; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #007bff; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #17a2b8; }
        .btn { background: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; }
        .btn:hover { background: #0056b3; }
    </style>";
    echo "</head><body><div class='container'>";
    
    echo "<h2>🔧 Fix Activity Budget Tables</h2>";
    echo "<pre>";
    
    // Read and execute the activity budget system SQL
    echo "Reading activity_budget_system.sql...\n";
    $sql = file_get_contents(__DIR__ . '/database/activity_budget_system.sql');
    
    // Remove comments
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Split statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   stripos($stmt, 'USE') !== 0 &&
                   stripos($stmt, 'DELIMITER') !== 0 &&
                   stripos($stmt, 'DROP TABLE') !== 0; // Skip DROP statements
        }
    );
    
    echo "Executing SQL statements...\n\n";
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            // Only execute CREATE TABLE and CREATE PROCEDURE statements
            if (stripos($statement, 'CREATE TABLE') === 0 || 
                stripos($statement, 'CREATE OR REPLACE VIEW') === 0) {
                try {
                    $db->exec($statement);
                    
                    // Extract table/view name
                    if (preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $statement, $matches)) {
                        echo "<span class='success'>✓ Table '{$matches[1]}' created/verified</span>\n";
                    } elseif (preg_match('/CREATE OR REPLACE VIEW.*?`?(\w+)`?/i', $statement, $matches)) {
                        echo "<span class='success'>✓ View '{$matches[1]}' created/verified</span>\n";
                    }
                } catch (PDOException $e) {
                    // Ignore "table already exists" errors
                    if ($e->getCode() != '42S01') {
                        echo "<span class='error'>✗ Error: " . $e->getMessage() . "</span>\n";
                    }
                }
            }
        }
    }
    
    echo "\n<span class='success'>✓ Activity budget tables verified!</span>\n";
    echo "</pre>";
    
    // Verify tables exist
    echo "<h3>📋 Table Verification</h3>";
    echo "<pre>";
    
    $tables = ['activity_budget_plans', 'activity_budget_monthly'];
    foreach ($tables as $table) {
        try {
            $stmt = $db->query("DESCRIBE $table");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "<span class='success'>✓ Table '$table' exists</span>\n";
            echo "  Columns: " . implode(', ', $columns) . "\n\n";
        } catch (PDOException $e) {
            echo "<span class='error'>✗ Table '$table' does not exist</span>\n\n";
        }
    }
    
    echo "</pre>";
    
    echo '<p><a href="activity_budget_plans.php" class="btn">Activity Budget Plans</a></p>';
    echo '<p><a href="activity_budget_monthly.php" class="btn">Monthly Budget</a></p>';
    echo '<p><a href="budget.php" class="btn">Budget Management</a></p>';
    
    echo "</div></body></html>";
    
} catch (Exception $e) {
    echo "<div class='container'>";
    echo "<h2 class='error'>❌ Error</h2>";
    echo "<pre class='error'>";
    echo "Error: " . $e->getMessage() . "\n\n";
    echo "Stack trace:\n" . $e->getTraceAsString();
    echo "</pre>";
    echo "</div></body></html>";
}
?>

// Powered by IBM Bob
