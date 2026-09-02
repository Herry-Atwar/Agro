<?php
/**
 * Check activity_budget_monthly table structure
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "<!DOCTYPE html>";
    echo "<html><head><title>Check Monthly Table</title>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #007bff; color: white; }
        .btn { background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; }
    </style>";
    echo "</head><body>";
    
    echo "<h2>Check activity_budget_monthly Table</h2>";
    
    // Check if table exists
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'activity_budget_monthly'");
        $exists = $stmt->fetch();
        
        if ($exists) {
            echo "<p class='success'>✓ Table 'activity_budget_monthly' exists</p>";
            
            // Show table structure
            echo "<h3>Table Structure:</h3>";
            $stmt = $db->query("DESCRIBE activity_budget_monthly");
            echo "<table>";
            echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo "<tr>";
                echo "<td><strong>{$row['Field']}</strong></td>";
                echo "<td>{$row['Type']}</td>";
                echo "<td>{$row['Null']}</td>";
                echo "<td>{$row['Key']}</td>";
                echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
                echo "<td>{$row['Extra']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Check for budget_month column specifically
            $stmt = $db->query("SHOW COLUMNS FROM activity_budget_monthly LIKE 'budget_month'");
            $hasColumn = $stmt->fetch();
            
            if ($hasColumn) {
                echo "<p class='success'>✓ Column 'budget_month' exists!</p>";
                echo "<p>The table structure is correct. The error might be coming from a different issue.</p>";
            } else {
                echo "<p class='error'>✗ Column 'budget_month' is MISSING!</p>";
                echo "<p class='warning'>The table exists but doesn't have the budget_month column.</p>";
                echo "<p><strong>Solution:</strong> Run the fix script to add the missing column.</p>";
                echo '<a href="create_monthly_table.php" class="btn">Fix Table Structure</a>';
            }
            
            // Show row count
            $stmt = $db->query("SELECT COUNT(*) as count FROM activity_budget_monthly");
            $count = $stmt->fetch()['count'];
            echo "<p>Table has <strong>$count</strong> rows.</p>";
            
        } else {
            echo "<p class='error'>✗ Table 'activity_budget_monthly' does NOT exist!</p>";
            echo "<p><strong>Solution:</strong> Create the table first.</p>";
            echo '<a href="create_monthly_table.php" class="btn">Create Table</a>';
        }
        
    } catch (PDOException $e) {
        echo "<p class='error'>Error checking table: " . $e->getMessage() . "</p>";
    }
    
    echo '<br><a href="activity_budget_monthly.php" class="btn">Try Monthly Budget Page</a>';
    echo '<a href="budget.php" class="btn">Budget Management</a>';
    
    echo "</body></html>";
    
} catch (Exception $e) {
    echo "<pre class='error'>";
    echo "Error: " . $e->getMessage();
    echo "</pre>";
    echo "</body></html>";
}
?>

// Powered by IBM Bob
