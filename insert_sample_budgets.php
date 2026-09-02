<?php
/**
 * Insert Sample Budget Data
 * Populates the budgets table with realistic sample data
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "<!DOCTYPE html>";
    echo "<html><head><title>Insert Sample Budget Data</title>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 3px solid #007bff; padding-bottom: 10px; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #007bff; overflow-x: auto; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #17a2b8; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #007bff; color: white; font-weight: bold; }
        tr:hover { background: #f8f9fa; }
        .btn { background: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; }
        .btn:hover { background: #0056b3; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; }
        .stat-card h3 { margin: 0 0 10px 0; font-size: 14px; opacity: 0.9; }
        .stat-card .value { font-size: 28px; font-weight: bold; }
    </style>";
    echo "</head><body><div class='container'>";
    
    echo "<h2>📊 Insert Sample Budget Data</h2>";
    
    // Check if data already exists
    $stmt = $db->query("SELECT COUNT(*) as count FROM budgets");
    $existing = $stmt->fetch()['count'];
    
    if ($existing > 0) {
        echo "<div class='info'>ℹ️ Found $existing existing budget records.</div>";
        echo "<p>Do you want to:</p>";
        echo "<ul>";
        echo "<li><strong>Add more sample data</strong> (keeps existing records)</li>";
        echo "<li><strong>Replace all data</strong> (deletes existing records first)</li>";
        echo "</ul>";
        
        if (!isset($_GET['confirm'])) {
            echo '<a href="?confirm=add" class="btn">Add More Data</a>';
            echo '<a href="?confirm=replace" class="btn" style="background:#dc3545;">Replace All Data</a>';
            echo '<a href="budget.php" class="btn" style="background:#6c757d;">Cancel</a>';
            echo "</div></body></html>";
            exit;
        }
        
        if ($_GET['confirm'] === 'replace') {
            echo "<p class='info'>🗑️ Clearing existing budget data...</p>";
            $db->exec("DELETE FROM budgets");
            echo "<p class='success'>✓ Existing data cleared</p>";
        }
    }
    
    echo "<p class='info'>📝 Inserting sample budget data...</p>";
    echo "<pre>";
    
    // Read and execute the SQL file
    $sql = file_get_contents(__DIR__ . '/database/insert_sample_budgets.sql');
    
    // Remove comments
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Split statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   stripos($stmt, 'USE') !== 0 &&
                   stripos($stmt, 'DELETE FROM budgets') !== 0; // Skip the DELETE if we already handled it
        }
    );
    
    $insertCount = 0;
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            if (stripos($statement, 'INSERT') === 0) {
                $db->exec($statement);
                $insertCount += $db->query("SELECT ROW_COUNT()")->fetchColumn();
            } elseif (stripos($statement, 'SELECT') === 0) {
                // Execute SELECT statements for summary
                $stmt = $db->query($statement);
                if ($stmt) {
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($results)) {
                        echo "\n";
                        foreach ($results as $row) {
                            foreach ($row as $key => $value) {
                                echo "$key: $value\n";
                            }
                            echo "\n";
                        }
                    }
                }
            }
        }
    }
    
    echo "</pre>";
    
    echo "<p class='success'>✓ Sample data inserted successfully!</p>";
    
    // Display statistics
    echo "<h3>📈 Budget Statistics</h3>";
    
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_budgets,
            COUNT(DISTINCT budget_year) as years,
            COUNT(DISTINCT company_id) as companies,
            COUNT(DISTINCT division_id) as divisions,
            SUM(planned_amount) as total_planned,
            SUM(actual_amount) as total_actual
        FROM budgets
    ");
    $stats = $stmt->fetch();
    
    echo "<div class='stats'>";
    echo "<div class='stat-card'><h3>Total Budgets</h3><div class='value'>" . number_format($stats['total_budgets']) . "</div></div>";
    echo "<div class='stat-card'><h3>Budget Years</h3><div class='value'>" . $stats['years'] . "</div></div>";
    echo "<div class='stat-card'><h3>Companies</h3><div class='value'>" . $stats['companies'] . "</div></div>";
    echo "<div class='stat-card'><h3>Divisions</h3><div class='value'>" . $stats['divisions'] . "</div></div>";
    echo "</div>";
    
    // Summary by year and type
    echo "<h3>📊 Budget Summary by Year & Type</h3>";
    $stmt = $db->query("
        SELECT 
            budget_year,
            budget_type,
            status,
            COUNT(*) as count,
            CONCAT('Rp ', FORMAT(SUM(planned_amount), 0)) as total_planned,
            CONCAT('Rp ', FORMAT(SUM(actual_amount), 0)) as total_actual
        FROM budgets
        GROUP BY budget_year, budget_type, status
        ORDER BY budget_year DESC, budget_type, status
    ");
    
    echo "<table>";
    echo "<tr><th>Year</th><th>Type</th><th>Status</th><th>Count</th><th>Total Planned</th><th>Total Actual</th></tr>";
    while ($row = $stmt->fetch()) {
        echo "<tr>";
        echo "<td>{$row['budget_year']}</td>";
        echo "<td>" . ucfirst($row['budget_type']) . "</td>";
        echo "<td>" . ucfirst($row['status']) . "</td>";
        echo "<td>{$row['count']}</td>";
        echo "<td>{$row['total_planned']}</td>";
        echo "<td>{$row['total_actual']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Summary by category
    echo "<h3>📋 Budget Summary by Category (2026)</h3>";
    $stmt = $db->query("
        SELECT 
            category,
            COUNT(*) as count,
            CONCAT('Rp ', FORMAT(SUM(planned_amount), 0)) as total_planned,
            CONCAT('Rp ', FORMAT(SUM(actual_amount), 0)) as total_actual,
            CONCAT(FORMAT(AVG(variance_percentage), 2), '%') as avg_variance
        FROM budgets
        WHERE budget_year = 2026
        GROUP BY category
        ORDER BY SUM(planned_amount) DESC
    ");
    
    echo "<table>";
    echo "<tr><th>Category</th><th>Count</th><th>Total Planned</th><th>Total Actual</th><th>Avg Variance</th></tr>";
    while ($row = $stmt->fetch()) {
        echo "<tr>";
        echo "<td>{$row['category']}</td>";
        echo "<td>{$row['count']}</td>";
        echo "<td>{$row['total_planned']}</td>";
        echo "<td>{$row['total_actual']}</td>";
        echo "<td>{$row['avg_variance']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo '<p><a href="budget.php" class="btn">Go to Budget Management</a></p>';
    echo '<p><a href="insert_sample_budgets.php" class="btn" style="background:#6c757d;">Refresh This Page</a></p>';
    
    echo "</div></body></html>";
    
} catch (PDOException $e) {
    echo "<div class='container'>";
    echo "<h2 class='error'>❌ Error</h2>";
    echo "<pre class='error'>";
    echo "Error: " . $e->getMessage() . "\n\n";
    echo "Stack trace:\n" . $e->getTraceAsString();
    echo "</pre>";
    echo '<p><a href="budget.php" class="btn">Back to Budget Management</a></p>';
    echo "</div></body></html>";
}
?>

// Powered by IBM Bob
