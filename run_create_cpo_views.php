<?php
/**
 * Script to create CPO stock views
 * Run this file once to create the missing database views
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "<h2>Creating CPO Stock Views</h2>";
    echo "<pre>";
    
    // Read the SQL file
    $sql_file = __DIR__ . '/create_cpo_views.sql';
    
    if (!file_exists($sql_file)) {
        throw new Exception("SQL file not found: $sql_file");
    }
    
    $sql = file_get_contents($sql_file);
    
    // Split by semicolons to execute each statement separately
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   stripos($stmt, 'USE ') !== 0 && 
                   stripos($stmt, '--') !== 0;
        }
    );
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            $db->exec($statement);
            
            // Extract view name if it's a CREATE VIEW statement
            if (preg_match('/CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+(\w+)/i', $statement, $matches)) {
                echo "✓ Created view: {$matches[1]}\n";
            } else if (preg_match('/SELECT\s+[\'"](.+?)[\'"]/i', $statement, $matches)) {
                echo "✓ {$matches[1]}\n";
            } else {
                echo "✓ Statement executed successfully\n";
            }
            $success_count++;
        } catch (PDOException $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            $error_count++;
        }
    }
    
    echo "\n";
    echo "========================================\n";
    echo "Summary:\n";
    echo "  Successful: $success_count\n";
    echo "  Errors: $error_count\n";
    echo "========================================\n\n";
    
    // Verify views were created
    echo "Verifying views...\n";
    $views = $db->query("SHOW TABLES LIKE 'vw_%'")->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($views) > 0) {
        echo "Found " . count($views) . " views:\n";
        foreach ($views as $view) {
            echo "  - $view\n";
        }
    } else {
        echo "No views found!\n";
    }
    
    echo "\n";
    echo "========================================\n";
    echo "DONE! You can now access inventory_cpo.php\n";
    echo "========================================\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre>";
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString();
    echo "</pre>";
}
?>

// Powered by IBM Bob
