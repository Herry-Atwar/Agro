<?php
/**
 * Fix Missing harvest_realizations Table
 * This script creates the harvest_realizations table and related tables
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "Starting database table creation...\n\n";
    
    // Read the SQL file
    $sql_file = __DIR__ . '/database/harvesting_schema.sql';
    
    if (!file_exists($sql_file)) {
        die("Error: SQL file not found at: $sql_file\n");
    }
    
    $sql = file_get_contents($sql_file);
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   !preg_match('/^--/', $stmt) && 
                   !preg_match('/^\/\*/', $stmt);
        }
    );
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            $db->exec($statement);
            $success_count++;
            
            // Extract table name for reporting
            if (preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $statement, $matches)) {
                echo "✓ Created table: {$matches[1]}\n";
            } elseif (preg_match('/INSERT INTO\s+`?(\w+)`?/i', $statement, $matches)) {
                echo "✓ Inserted data into: {$matches[1]}\n";
            } else {
                echo "✓ Executed statement\n";
            }
        } catch (PDOException $e) {
            // Ignore "table already exists" errors
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "✗ Error: " . $e->getMessage() . "\n";
                $error_count++;
            } else {
                echo "ℹ Table already exists (skipped)\n";
            }
        }
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "Summary:\n";
    echo "- Successful operations: $success_count\n";
    echo "- Errors: $error_count\n";
    echo str_repeat("=", 50) . "\n\n";
    
    // Verify the harvest_realizations table exists
    $check_query = "SHOW TABLES LIKE 'harvest_realizations'";
    $result = $db->query($check_query)->fetch();
    
    if ($result) {
        echo "✓ SUCCESS: harvest_realizations table exists!\n\n";
        
        // Show table structure
        echo "Table Structure:\n";
        $columns = $db->query("DESCRIBE harvest_realizations")->fetchAll();
        foreach ($columns as $col) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }
        
        echo "\n✓ You can now access analytics.php without errors!\n";
    } else {
        echo "✗ ERROR: harvest_realizations table was not created!\n";
    }
    
} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>

// Powered by IBM Bob
