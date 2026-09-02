<?php
/**
 * Fix Budgets Table - Add missing category column
 * Run this script to fix the "Unknown column 'b.category'" error
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "<!DOCTYPE html>";
    echo "<html><head><title>Fix Budgets Table</title>";
    echo "<style>body{font-family:Arial,sans-serif;margin:20px;} pre{background:#f5f5f5;padding:15px;border-radius:5px;} .success{color:green;} .error{color:red;}</style>";
    echo "</head><body>";
    
    echo "<h2>Fixing Budgets Table</h2>";
    echo "<pre>";
    
    // Step 1: Check if table exists and has required columns
    echo "Step 1: Checking current table structure...\n";
    try {
        $stmt = $db->query("DESCRIBE budgets");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $missingColumns = [];
        if (!in_array('category', $columns)) {
            $missingColumns[] = 'category';
        }
        if (!in_array('notes', $columns)) {
            $missingColumns[] = 'notes';
        }
        
        if (empty($missingColumns)) {
            echo "<span class='success'>✓ Table exists with all required columns!</span>\n\n";
            echo "Current columns: " . implode(', ', $columns) . "\n";
        } else {
            echo "<span class='error'>✗ Table exists but missing columns: " . implode(', ', $missingColumns) . "</span>\n";
            echo "Current columns: " . implode(', ', $columns) . "\n\n";
            
            // Add missing columns
            echo "Step 2: Adding missing columns...\n";
            if (in_array('category', $missingColumns)) {
                $db->exec("ALTER TABLE budgets ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT 'General' COMMENT 'e.g., Labor, Fertilizer, Equipment' AFTER budget_type");
                echo "<span class='success'>✓ Added 'category' column</span>\n";
            }
            if (in_array('notes', $missingColumns)) {
                // Add notes column at the end
                $db->exec("ALTER TABLE budgets ADD COLUMN notes TEXT");
                echo "<span class='success'>✓ Added 'notes' column</span>\n";
            }
            
            // Add other missing columns if needed
            if (!in_array('currency', $columns)) {
                $db->exec("ALTER TABLE budgets ADD COLUMN currency VARCHAR(3) DEFAULT 'IDR' AFTER variance_percentage");
                echo "<span class='success'>✓ Added 'currency' column</span>\n";
            }
            if (!in_array('approval_date', $columns)) {
                $db->exec("ALTER TABLE budgets ADD COLUMN approval_date DATE NULL AFTER status");
                echo "<span class='success'>✓ Added 'approval_date' column</span>\n";
            }
            if (!in_array('approved_by', $columns)) {
                $db->exec("ALTER TABLE budgets ADD COLUMN approved_by VARCHAR(50) NULL AFTER approval_date");
                echo "<span class='success'>✓ Added 'approved_by' column</span>\n";
            }
            if (!in_array('updated_at', $columns)) {
                $db->exec("ALTER TABLE budgets ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
                echo "<span class='success'>✓ Added 'updated_at' column</span>\n";
            }
            if (!in_array('updated_by', $columns)) {
                $db->exec("ALTER TABLE budgets ADD COLUMN updated_by VARCHAR(50) AFTER updated_at");
                echo "<span class='success'>✓ Added 'updated_by' column</span>\n";
            }
        }
    } catch (PDOException $e) {
        if ($e->getCode() == '42S02') {
            // Table doesn't exist
            echo "<span class='error'>✗ Table doesn't exist</span>\n\n";
            echo "Step 2: Creating budgets table...\n";
            
            // Read and execute the SQL file
            $sql = file_get_contents(__DIR__ . '/database/fix_budgets_table.sql');
            
            // Remove comments and split statements
            $sql = preg_replace('/--.*$/m', '', $sql);
            $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
            
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                function($stmt) {
                    return !empty($stmt) && stripos($stmt, 'USE') !== 0;
                }
            );
            
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $db->exec($statement);
                }
            }
            
            echo "<span class='success'>✓ Table created successfully!</span>\n";
        } else {
            throw $e;
        }
    }
    
    // Step 3: Verify the fix
    echo "\nStep 3: Verifying table structure...\n";
    $stmt = $db->query("DESCRIBE budgets");
    echo "\n<strong>Table Structure:</strong>\n";
    echo str_pad("Field", 25) . str_pad("Type", 35) . str_pad("Null", 8) . "Key\n";
    echo str_repeat("-", 80) . "\n";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo str_pad($row['Field'], 25) . 
             str_pad($row['Type'], 35) . 
             str_pad($row['Null'], 8) . 
             ($row['Key'] ?? '') . "\n";
    }
    
    echo "\n<span class='success'>✓ Budgets table is now properly configured!</span>\n";
    echo "</pre>";
    
    echo '<p><strong>The error has been fixed. You can now use budget.php without errors.</strong></p>';
    echo '<p><a href="budget.php" style="background:#007bff;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Go to Budget Management</a></p>';
    
    echo "</body></html>";
    
} catch (PDOException $e) {
    echo "<pre class='error'>";
    echo "❌ Error: " . $e->getMessage() . "\n\n";
    echo "Error Code: " . $e->getCode() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString();
    echo "</pre>";
    echo "</body></html>";
}
?>

// Powered by IBM Bob
