<?php
/**
 * Fix CPO Views - Create missing vw_tank_stock_summary view
 * Run this script once to create the required database views
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "Creating CPO inventory views...\n";
    
    // Read and execute the CPO stock schema SQL file
    $sql_file = __DIR__ . '/database/cpo_stock_schema.sql';
    
    if (!file_exists($sql_file)) {
        throw new Exception("SQL file not found: $sql_file");
    }
    
    $sql = file_get_contents($sql_file);
    
    // Execute the SQL
    $db->exec($sql);
    
    echo "✓ CPO inventory views created successfully!\n";
    echo "\nViews created:\n";
    echo "- vw_tank_stock_summary\n";
    echo "- vw_daily_stock_movements\n";
    echo "- vw_tank_utilization_alerts\n";
    echo "- vw_stock_aging\n";
    echo "\nYou can now access inventory_cpo.php\n";
    
} catch (PDOException $e) {
    echo "✗ Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Powered by IBM Bob
