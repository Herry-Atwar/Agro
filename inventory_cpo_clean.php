<?php
// Enable all error reporting
// Step 2: Include config
require_once 'config/database.php';

// Step 3: Include functions
require_once 'includes/functions.php';

// Step 4: Get database connection
try {
    $db = getDB();
    } catch (Exception $e) {
    die("✗ ERROR connecting to database: " . $e->getMessage());
}

// Step 5: Set page title
$page_title = "CPO Inventory Report";
// Step 6: Include header
require_once 'includes/header.php';

// Step 7: Get filter parameters
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-d'));
$tank_filter = get('tank_id', '');
$report_type = get('report_type', 'summary');
// Step 8: Check if storage_tanks table exists
try {
    $check = $db->query("SHOW TABLES LIKE 'storage_tanks'")->fetch();
    if ($check) {
        } else {
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
try {
    $views_to_check = ['vw_tank_stock_summary', 'vw_stock_aging', 'vw_tank_utilization_alerts'];
    $missing_views = [];
    
    foreach ($views_to_check as $view) {
        $check = $db->query("SHOW TABLES LIKE '$view'")->fetch();
        if ($check) {
            } else {
            $missing_views[] = $view;
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
    . $e->getMessage() . "<br>\n";
    }

// Step 10: Fetch tanks
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
    . count($tanks) . " tanks<br>\n";
    } catch (PDOException $e) {
    . $e->getMessage() . "<br>\n";
    echo "Trying without view...<br>\n";
    try {
        $tanks_stmt = $db->query("SELECT * FROM storage_tanks ORDER BY tank_code");
        $tanks = $tanks_stmt->fetchAll();
        . count($tanks) . " tanks (without stock data)<br>\n";
        } catch (PDOException $e) {
        die("✗ ERROR: " . $e->getMessage());
    }
}

// Step 11: Get inventory summary
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
    } catch (PDOException $e) {
    . $e->getMessage() . "<br>\n";
    $inventory_summary = [
        'total_stock_kg' => 0,
        'total_capacity_kg' => 0,
        'total_tanks' => count($tanks),
        'active_tanks' => 0,
        'critical_tanks' => 0,
        'low_tanks' => 0
    ];
    }

// Step 12: Check cpo_stock_transactions table
try {
    $check = $db->query("SHOW TABLES LIKE 'cpo_stock_transactions'")->fetch();
    if ($check) {
        $count = $db->query("SELECT COUNT(*) as cnt FROM cpo_stock_transactions")->fetch();
        . $count['cnt'] . " transactions<br>\n";
        } else {
        }
} catch (PDOException $e) {
    . $e->getMessage() . "<br>\n";
    }

// Now render a simple page
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-droplet"></i> CPO Inventory Report </h1>
            <p class="text-muted">Crude Palm Oil Stock Management</p>
        </div>
        <div class="col-auto">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-house"></i> Dashboard
            </a>
        </div>
    </div>
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



<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
