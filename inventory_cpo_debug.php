<?php
/**
 * DEBUG VERSION of inventory_cpo.php
 * This will show exactly where the code fails step by step
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<!-- DEBUG START -->\n";
echo "Step 1: Script started<br>\n";
flush();

// Step 2: Include config
echo "Step 2: Including config/database.php...<br>\n";
flush();
try {
    require_once 'config/database.php';
    echo "✓ Config loaded successfully<br>\n";
    flush();
} catch (Exception $e) {
    die("✗ ERROR loading config: " . $e->getMessage());
}

// Step 3: Include functions
echo "Step 3: Including includes/functions.php...<br>\n";
flush();
try {
    require_once 'includes/functions.php';
    echo "✓ Functions loaded successfully<br>\n";
    flush();
} catch (Exception $e) {
    die("✗ ERROR loading functions: " . $e->getMessage());
}

// Step 4: Get database connection
echo "Step 4: Getting database connection...<br>\n";
flush();
try {
    $db = getDB();
    echo "✓ Database connected<br>\n";
    flush();
} catch (Exception $e) {
    die("✗ ERROR connecting to database: " . $e->getMessage());
}

// Step 5: Set page title
echo "Step 5: Setting page title...<br>\n";
flush();
$page_title = "CPO Inventory Report";
echo "✓ Page title set<br>\n";
flush();

// Step 6: Include header
echo "Step 6: Including header...<br>\n";
flush();
try {
    require_once 'includes/header.php';
    echo "✓ Header included<br>\n";
    flush();
} catch (Exception $e) {
    die("✗ ERROR loading header: " . $e->getMessage());
}

// Step 7: Get filter parameters
echo "Step 7: Getting filter parameters...<br>\n";
flush();
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-d'));
$tank_filter = get('tank_id', '');
$report_type = get('report_type', 'summary');
echo "✓ Filters set: from=$date_from, to=$date_to, tank=$tank_filter<br>\n";
flush();

// Step 8: Check if storage_tanks table exists
echo "Step 8: Checking storage_tanks table...<br>\n";
flush();
try {
    $check = $db->query("SHOW TABLES LIKE 'storage_tanks'")->fetch();
    if ($check) {
        echo "✓ storage_tanks table exists<br>\n";
        flush();
    } else {
        echo "✗ storage_tanks table NOT FOUND<br>\n";
        echo "<div style='background: #ffebee; padding: 15px; border-left: 4px solid #f44336;'>";
        echo "<strong>ERROR:</strong> storage_tanks table is missing!<br>";
        echo "You need to create this table first.<br>";
        echo "Check database/mill_operations_schema.sql or MILL_OPERATIONS_INSTALLATION.md<br>";
        echo "</div>";
        die();
    }
} catch (PDOException $e) {
    die("✗ ERROR checking table: " . $e->getMessage());
}

// Step 9: Check if views exist
echo "Step 9: Checking required views...<br>\n";
flush();
try {
    $views_to_check = ['vw_tank_stock_summary', 'vw_stock_aging', 'vw_tank_utilization_alerts'];
    $missing_views = [];
    
    foreach ($views_to_check as $view) {
        $check = $db->query("SHOW TABLES LIKE '$view'")->fetch();
        if ($check) {
            echo "✓ $view exists<br>\n";
            flush();
        } else {
            echo "⚠ $view NOT FOUND<br>\n";
            $missing_views[] = $view;
            flush();
        }
    }
    
    if (!empty($missing_views)) {
        echo "<div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107;'>";
        echo "<strong>WARNING:</strong> Some views are missing:<br>";
        echo "<ul>";
        foreach ($missing_views as $view) {
            echo "<li>$view</li>";
        }
        echo "</ul>";
        echo "The page may not work correctly. Run the CPO setup scripts.<br>";
        echo "</div>";
    }
} catch (PDOException $e) {
    echo "⚠ WARNING checking views: " . $e->getMessage() . "<br>\n";
    flush();
}

// Step 10: Fetch tanks
echo "Step 10: Fetching storage tanks...<br>\n";
flush();
try {
    $tanks_stmt = $db->query("
        SELECT t.*, 
               COALESCE(s.current_stock_kg, 0) as current_stock_kg,
               COALESCE(s.utilization_percentage, 0) as utilization_percentage
        FROM storage_tanks t
        LEFT JOIN vw_tank_stock_summary s ON t.tank_id = s.tank_id
        ORDER BY t.tank_code
    ");
    $tanks = $tanks_stmt->fetchAll();
    echo "✓ Fetched " . count($tanks) . " tanks<br>\n";
    flush();
} catch (PDOException $e) {
    echo "✗ ERROR fetching tanks: " . $e->getMessage() . "<br>\n";
    echo "Trying without view...<br>\n";
    flush();
    
    try {
        $tanks_stmt = $db->query("SELECT * FROM storage_tanks ORDER BY tank_code");
        $tanks = $tanks_stmt->fetchAll();
        echo "✓ Fetched " . count($tanks) . " tanks (without stock data)<br>\n";
        flush();
    } catch (PDOException $e) {
        die("✗ ERROR: " . $e->getMessage());
    }
}

// Step 11: Get inventory summary
echo "Step 11: Getting inventory summary...<br>\n";
flush();
try {
    $inventory_summary = $db->query("
        SELECT 
            SUM(current_stock_kg) as total_stock_kg,
            SUM(capacity_kg) as total_capacity_kg,
            COUNT(*) as total_tanks,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_tanks,
            SUM(CASE WHEN utilization_percentage >= 90 THEN 1 ELSE 0 END) as critical_tanks,
            SUM(CASE WHEN utilization_percentage <= 20 THEN 1 ELSE 0 END) as low_tanks
        FROM vw_tank_stock_summary
    ")->fetch();
    echo "✓ Inventory summary retrieved<br>\n";
    flush();
} catch (PDOException $e) {
    echo "⚠ WARNING getting summary: " . $e->getMessage() . "<br>\n";
    $inventory_summary = [
        'total_stock_kg' => 0,
        'total_capacity_kg' => 0,
        'total_tanks' => count($tanks),
        'active_tanks' => 0,
        'critical_tanks' => 0,
        'low_tanks' => 0
    ];
    flush();
}

// Step 12: Check cpo_stock_transactions table
echo "Step 12: Checking cpo_stock_transactions table...<br>\n";
flush();
try {
    $check = $db->query("SHOW TABLES LIKE 'cpo_stock_transactions'")->fetch();
    if ($check) {
        echo "✓ cpo_stock_transactions table exists<br>\n";
        $count = $db->query("SELECT COUNT(*) as cnt FROM cpo_stock_transactions")->fetch();
        echo "✓ Found " . $count['cnt'] . " transactions<br>\n";
        flush();
    } else {
        echo "⚠ cpo_stock_transactions table NOT FOUND<br>\n";
        flush();
    }
} catch (PDOException $e) {
    echo "⚠ WARNING: " . $e->getMessage() . "<br>\n";
    flush();
}

echo "Step 13: Rendering page content...<br>\n";
echo "<!-- DEBUG END -->\n\n";
flush();

// Now render a simple page
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-droplet"></i> CPO Inventory Report (DEBUG MODE)</h1>
            <p class="text-muted">Crude Palm Oil Stock Management</p>
        </div>
        <div class="col-auto">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-house"></i> Dashboard
            </a>
        </div>
    </div>
</div>

<div class="alert alert-success">
    <strong>✓ Page loaded successfully!</strong><br>
    All steps completed without fatal errors.
</div>

<!-- Summary Cards -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card stat-card bg-primary text-white">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo number_format($inventory_summary['total_stock_kg'] ?? 0); ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-droplet"></i> Total Stock (Kg)</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card bg-success text-white">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo $inventory_summary['total_tanks'] ?? 0; ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-box"></i> Total Tanks</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card bg-info text-white">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo $inventory_summary['active_tanks'] ?? 0; ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-check-circle"></i> Active Tanks</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card bg-warning text-dark">
            <div class="card-body py-2">
                <h4 class="mb-1 text-dark"><?php echo number_format($inventory_summary['total_capacity_kg'] ?? 0); ?></h4>
                <p class="mb-0 small text-dark"><i class="bi bi-box-seam"></i> Total Capacity (Kg)</p>
            </div>
        </div>
    </div>
</div>

<!-- Tanks List -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Storage Tanks (<?php echo count($tanks); ?> tanks)
    </div>
    <div class="card-body">
        <?php if (empty($tanks)): ?>
            <div class="alert alert-warning">
                <strong>No storage tanks found!</strong><br>
                You need to add storage tanks first. Check STORAGE_TANKS_QUICK_START.md
            </div>
        <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Tank Code</th>
                        <th>Tank Name</th>
                        <th>Capacity (Kg)</th>
                        <th>Current Stock (Kg)</th>
                        <th>Utilization %</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tanks as $tank): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($tank['tank_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($tank['tank_name']); ?></td>
                            <td class="text-end"><?php echo number_format($tank['capacity_kg'] ?? 0); ?></td>
                            <td class="text-end"><?php echo number_format($tank['current_stock_kg'] ?? 0); ?></td>
                            <td class="text-end"><?php echo number_format($tank['utilization_percentage'] ?? 0, 1); ?>%</td>
                            <td><?php echo get_status_badge($tank['status'] ?? 'unknown'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info mt-3">
    <strong>Debug Information:</strong><br>
    - Storage Tanks: <?php echo count($tanks); ?><br>
    - Total Stock: <?php echo number_format($inventory_summary['total_stock_kg'] ?? 0); ?> Kg<br>
    - Date Range: <?php echo $date_from; ?> to <?php echo $date_to; ?><br>
</div>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
