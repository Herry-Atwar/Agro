<?php
/**
 * Diagnostic script to check CPO stock tables
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "<h2>CPO Stock Tables Diagnostic</h2>";
    echo "<pre>";
    
    // Check if storage_tanks table exists
    echo "Checking for storage_tanks table...\n";
    $tables = $db->query("SHOW TABLES LIKE 'storage_tanks'")->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) > 0) {
        echo "✓ storage_tanks table EXISTS\n\n";
        
        // Show table structure
        echo "Table structure:\n";
        $columns = $db->query("DESCRIBE storage_tanks")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }
        
        // Show record count
        $count = $db->query("SELECT COUNT(*) FROM storage_tanks")->fetchColumn();
        echo "\nRecord count: $count\n";
        
        if ($count > 0) {
            echo "\nSample records:\n";
            $samples = $db->query("SELECT * FROM storage_tanks LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($samples as $sample) {
                echo "  " . json_encode($sample) . "\n";
            }
        }
    } else {
        echo "✗ storage_tanks table DOES NOT EXIST\n";
        echo "  Need to create it!\n";
    }
    
    echo "\n========================================\n\n";
    
    // Check if cpo_stock_transactions table exists
    echo "Checking for cpo_stock_transactions table...\n";
    $tables = $db->query("SHOW TABLES LIKE 'cpo_stock_transactions'")->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) > 0) {
        echo "✓ cpo_stock_transactions table EXISTS\n\n";
        
        // Show table structure
        echo "Table structure:\n";
        $columns = $db->query("DESCRIBE cpo_stock_transactions")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }
        
        // Show record count
        $count = $db->query("SELECT COUNT(*) FROM cpo_stock_transactions")->fetchColumn();
        echo "\nRecord count: $count\n";
    } else {
        echo "✗ cpo_stock_transactions table DOES NOT EXIST\n";
        echo "  Need to create it!\n";
    }
    
    echo "\n========================================\n\n";
    
    // Check for views
    echo "Checking for CPO views...\n";
    $views = $db->query("SHOW TABLES LIKE 'vw_%'")->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($views) > 0) {
        echo "Found " . count($views) . " views:\n";
        foreach ($views as $view) {
            echo "  - $view\n";
        }
    } else {
        echo "✗ No views found\n";
    }
    
    echo "\n========================================\n\n";
    
    // Check for mill_production table (referenced in foreign key)
    echo "Checking for mill_production table (required for FK)...\n";
    $tables = $db->query("SHOW TABLES LIKE 'mill_production'")->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) > 0) {
        echo "✓ mill_production table EXISTS\n";
        $count = $db->query("SELECT COUNT(*) FROM mill_production")->fetchColumn();
        echo "  Record count: $count\n";
    } else {
        echo "✗ mill_production table DOES NOT EXIST\n";
        echo "  This is required for foreign key constraint!\n";
        echo "  We'll need to create tables WITHOUT foreign keys\n";
    }
    
    echo "\n========================================\n";
    echo "Diagnostic complete!\n";
    echo "========================================\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre>";
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "</pre>";
}
?>

// Powered by IBM Bob
