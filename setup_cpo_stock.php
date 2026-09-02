<?php
/**
 * Complete CPO Stock System Setup
 * Creates all tables and views needed for inventory_cpo.php
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "<h2>Setting Up CPO Stock Management System</h2>";
    echo "<pre>";
    
    // Read the complete schema file
    $sql_file = __DIR__ . '/database/cpo_stock_schema.sql';
    
    if (!file_exists($sql_file)) {
        throw new Exception("SQL file not found: $sql_file");
    }
    
    echo "Reading SQL file: $sql_file\n\n";
    $sql = file_get_contents($sql_file);
    
    // Remove comments and split by delimiter changes
    $sql = preg_replace('/--[^\n]*\n/', '', $sql);
    
    // Handle DELIMITER changes
    $parts = preg_split('/DELIMITER\s+(\S+)/i', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
    
    $current_delimiter = ';';
    $statements = [];
    
    for ($i = 0; $i < count($parts); $i++) {
        if ($i % 2 == 1) {
            // This is a delimiter declaration
            $current_delimiter = trim($parts[$i]);
        } else {
            // This is SQL content
            $content = trim($parts[$i]);
            if (empty($content)) continue;
            
            // Split by current delimiter
            $stmts = array_filter(
                array_map('trim', explode($current_delimiter, $content)),
                function($s) { return !empty($s); }
            );
            
            $statements = array_merge($statements, $stmts);
        }
    }
    
    echo "Found " . count($statements) . " SQL statements to execute\n";
    echo "========================================\n\n";
    
    $success_count = 0;
    $error_count = 0;
    $skip_count = 0;
    
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;
        
        // Skip USE statements
        if (stripos($statement, 'USE ') === 0) {
            $skip_count++;
            continue;
        }
        
        try {
            $db->exec($statement);
            
            // Provide feedback based on statement type
            if (preg_match('/DROP\s+TABLE/i', $statement)) {
                echo "✓ Dropped existing table\n";
            } else if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $statement, $matches)) {
                echo "✓ Created table: {$matches[1]}\n";
            } else if (preg_match('/CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+(\w+)/i', $statement, $matches)) {
                echo "✓ Created view: {$matches[1]}\n";
            } else if (preg_match('/CREATE\s+(?:OR\s+REPLACE\s+)?TRIGGER\s+(\w+)/i', $statement, $matches)) {
                echo "✓ Created trigger: {$matches[1]}\n";
            } else if (preg_match('/CREATE\s+PROCEDURE\s+(\w+)/i', $statement, $matches)) {
                echo "✓ Created procedure: {$matches[1]}\n";
            } else if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+(\w+)/i', $statement, $matches)) {
                $affected = $db->query("SELECT ROW_COUNT()")->fetchColumn();
                echo "✓ Inserted data into: {$matches[1]}\n";
            } else if (preg_match('/ALTER\s+TABLE\s+(\w+)/i', $statement, $matches)) {
                echo "✓ Altered table: {$matches[1]}\n";
            } else if (preg_match('/SELECT\s+[\'"](.+?)[\'"]/i', $statement, $matches)) {
                $result = $db->query($statement)->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    echo "✓ " . implode(': ', $result) . "\n";
                }
            } else {
                echo "✓ Statement executed\n";
            }
            
            $success_count++;
            
        } catch (PDOException $e) {
            // Check if it's a harmless error
            if (strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), "doesn't exist") !== false && 
                stripos($statement, 'DROP') === 0) {
                echo "⚠ Skipped: " . substr($e->getMessage(), 0, 80) . "...\n";
                $skip_count++;
            } else {
                echo "✗ Error: " . $e->getMessage() . "\n";
                echo "   Statement: " . substr($statement, 0, 100) . "...\n";
                $error_count++;
            }
        }
    }
    
    echo "\n";
    echo "========================================\n";
    echo "Summary:\n";
    echo "  Successful: $success_count\n";
    echo "  Skipped: $skip_count\n";
    echo "  Errors: $error_count\n";
    echo "========================================\n\n";
    
    // Verify tables were created
    echo "Verifying tables...\n";
    $tables = $db->query("SHOW TABLES LIKE '%tank%' OR SHOW TABLES LIKE '%cpo%'")->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) > 0) {
        echo "Found " . count($tables) . " CPO-related tables:\n";
        foreach ($tables as $table) {
            $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "  - $table ($count records)\n";
        }
    }
    
    echo "\n";
    
    // Verify views were created
    echo "Verifying views...\n";
    $views = $db->query("SHOW TABLES LIKE 'vw_%'")->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($views) > 0) {
        echo "Found " . count($views) . " views:\n";
        foreach ($views as $view) {
            echo "  - $view\n";
        }
    }
    
    echo "\n";
    echo "========================================\n";
    if ($error_count == 0) {
        echo "✓ SUCCESS! CPO Stock System is ready!\n";
        echo "You can now access inventory_cpo.php\n";
    } else {
        echo "⚠ Setup completed with $error_count errors\n";
        echo "Please review the errors above\n";
    }
    echo "========================================\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre>";
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString();
    echo "</pre>";
}
?>

// Powered by IBM Bob
