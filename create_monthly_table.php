<?php
/**
 * Create activity_budget_monthly table
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "<!DOCTYPE html>";
    echo "<html><head><title>Create Monthly Budget Table</title>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; }
        .btn { background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; }
    </style>";
    echo "</head><body>";
    
    echo "<h2>Create activity_budget_monthly Table</h2>";
    echo "<pre>";
    
    // Read SQL file
    $sql = file_get_contents(__DIR__ . '/database/create_activity_budget_monthly.sql');
    
    // Remove USE statement and comments
    $sql = preg_replace('/USE.*?;/i', '', $sql);
    $sql = preg_replace('/--.*$/m', '', $sql);
    
    // Split by semicolon
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            if (stripos($statement, 'CREATE TABLE') === 0) {
                echo "Creating table...\n";
                $db->exec($statement);
                echo "<span class='success'>✓ Table created successfully!</span>\n\n";
            } elseif (stripos($statement, 'SELECT') === 0) {
                $stmt = $db->query($statement);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($results as $row) {
                    foreach ($row as $key => $value) {
                        echo "$key: $value\n";
                    }
                }
                echo "\n";
            } elseif (stripos($statement, 'DESCRIBE') === 0) {
                echo "Table structure:\n";
                $stmt = $db->query($statement);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    echo "  {$row['Field']} - {$row['Type']}\n";
                }
            }
        }
    }
    
    echo "</pre>";
    echo "<p class='success'>✓ Done! The activity_budget_monthly table is ready.</p>";
    echo '<a href="activity_budget_monthly.php" class="btn">Go to Monthly Budget</a>';
    echo '<a href="budget.php" class="btn">Go to Budget Management</a>';
    
    echo "</body></html>";
    
} catch (PDOException $e) {
    echo "<pre class='error'>";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    echo "</pre>";
    echo "</body></html>";
}
?>

// Powered by IBM Bob
