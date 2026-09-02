<?php
/**
 * Quick Fix for FFB Delivery System
 * Runs the ffb_delivery_schema.sql to create required tables
 */

require_once 'config/database.php';

try {
    $db = getDB();
    echo "<h2>Setting Up FFB Delivery System</h2><pre>";
    
    // Read the schema file
    $sql_file = __DIR__ . '/database/ffb_delivery_schema.sql';
    
    if (!file_exists($sql_file)) {
        throw new Exception("Schema file not found: $sql_file");
    }
    
    echo "Reading schema file...\n";
    $sql = file_get_contents($sql_file);
    
    // Remove comments
    $sql = preg_replace('/--[^\n]*\n/', '', $sql);
    
    // Handle DELIMITER changes
    $parts = preg_split('/DELIMITER\s+(\S+)/i', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
    
    $current_delimiter = ';';
    $statements = [];
    
    for ($i = 0; $i < count($parts); $i++) {
        if ($i % 2 == 1) {
            $current_delimiter = trim($parts[$i]);
        } else {
            $content = trim($parts[$i]);
            if (empty($content)) continue;
            
            $stmts = array_filter(
                array_map('trim', explode($current_delimiter, $content)),
                function($s) { return !empty($s) && stripos($s, 'USE ') !== 0; }
            );
            
            $statements = array_merge($statements, $stmts);
        }
    }
    
    echo "Found " . count($statements) . " SQL statements\n";
    echo "========================================\n\n";
    
    $success = 0;
    $errors = 0;
    $skipped = 0;
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;
        
        try {
            $db->exec($statement);
            
            if (preg_match('/DROP\s+TABLE/i', $statement)) {
                echo "✓ Dropped existing table\n";
            } else if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $statement, $matches)) {
                echo "✓ Created table: {$matches[1]}\n";
            } else if (preg_match('/CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+(\w+)/i', $statement, $matches)) {
                echo "✓ Created view: {$matches[1]}\n";
            } else if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+(\w+)/i', $statement, $matches)) {
                echo "✓ Inserted data into: {$matches[1]}\n";
            } else if (preg_match('/CREATE\s+(?:OR\s+REPLACE\s+)?TRIGGER\s+(\w+)/i', $statement, $matches)) {
                echo "✓ Created trigger: {$matches[1]}\n";
            } else if (preg_match('/CREATE\s+PROCEDURE\s+(\w+)/i', $statement, $matches)) {
                echo "✓ Created procedure: {$matches[1]}\n";
            } else {
                echo "✓ Statement executed\n";
            }
            
            $success++;
            
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false ||
                (strpos($e->getMessage(), "doesn't exist") !== false && stripos($statement, 'DROP') === 0)) {
                $skipped++;
            } else {
                echo "✗ Error: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }
    
    echo "\n========================================\n";
    echo "Summary:\n";
    echo "  Successful: $success\n";
    echo "  Skipped: $skipped\n";
    echo "  Errors: $errors\n";
    echo "========================================\n\n";
    
    // Verify tables
    echo "Verifying FFB tables...\n";
    $tables = $db->query("SHOW TABLES LIKE '%ffb%'")->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) > 0) {
        echo "Found " . count($tables) . " FFB-related tables:\n";
        foreach ($tables as $table) {
            $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "  - $table ($count records)\n";
        }
    }
    
    echo "\n========================================\n";
    if ($errors == 0) {
        echo "✓ SUCCESS! FFB Delivery System is ready!\n";
        echo "You can now access ffb_delivery.php\n";
    } else {
        echo "⚠ Setup completed with $errors errors\n";
    }
    echo "========================================\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre>";
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "</pre>";
}
?>

// Powered by IBM Bob
