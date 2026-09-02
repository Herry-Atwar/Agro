<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "CPO Inventory Report";
require_once 'includes/header.php';

// Get filter parameters
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-d'));
$tank_filter = get('tank_id', '');
$report_type = get('report_type', 'summary');

// Check if storage_tanks table exists
try {
    $check = $db->query("SHOW TABLES LIKE 'storage_tanks'")->fetch();
    if (!$check) {
        echo "<div class='alert alert-danger'>";
        echo "<strong>ERROR:</strong> storage_tanks table is missing!<br>";
        echo "You need to create this table first.";
        echo "</div>";
        require_once 'includes/footer.php';
        exit;
    }
} catch (PDOException $e) {
    die("ERROR checking table: " . $e->getMessage());
}

// Fetch tanks with stock data
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
} catch (PDOException $e) {
    try {
        $tanks_stmt = $db->query("SELECT * FROM storage_tanks ORDER BY tank_code");
        $tanks = $tanks_stmt->fetchAll();
    } catch (PDOException $e) {
        die("ERROR fetching tanks: " . $e->getMessage());
    }
}

// Get inventory summary
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
    $inventory_summary = [
        'total_stock_kg' => 0,
        'total_capacity_kg' => 0,
        'total_tanks' => count($tanks),
        'active_tanks' => 0,
        'critical_tanks' => 0,
        'low_tanks' => 0
    ];
}

// Calculate overall utilization
$overall_utilization = 0;
if ($inventory_summary['total_capacity_kg'] > 0) {
    $overall_utilization = ($inventory_summary['total_stock_kg'] / $inventory_summary['total_capacity_kg']) * 100;
}
?>

<style>
.tank-card {
    transition: transform 0.2s, box-shadow 0.2s;
}
.tank-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15) !important;
}
.tank-svg {
    max-width: 120px;
    margin: 0 auto;
    display: block;
}
.stat-card {
    border: none;
    border-radius: 10px;
    transition: transform 0.2s;
}
.stat-card:hover {
    transform: translateY(-3px);
}
</style>

<div class="page-header mb-4">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-droplet-fill"></i> CPO Inventory Report</h1>
            <p class="text-muted mb-0">Visual overview of all CPO storage tanks</p>
        </div>
        <div class="col-auto">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-house"></i> Dashboard
            </a>
        </div>
    </div>
</div>

<!-- Summary Dashboard -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body text-center">
                <div class="display-4 text-primary mb-2">
                    <i class="bi bi-database"></i>
                </div>
                <h3 class="mb-0"><?php echo $inventory_summary['total_tanks'] ?? 0; ?></h3>
                <p class="text-muted mb-0">Total Tanks</p>
                <small class="text-success"><?php echo $inventory_summary['active_tanks'] ?? 0; ?> Active</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body text-center">
                <div class="display-4 text-success mb-2">
                    <i class="bi bi-droplet-fill"></i>
                </div>
                <h3 class="mb-0"><?php echo number_format(($inventory_summary['total_stock_kg'] ?? 0) / 1000, 1); ?></h3>
                <p class="text-muted mb-0">Total Stock (MT)</p>
                <small class="text-muted"><?php echo number_format($inventory_summary['total_stock_kg'] ?? 0); ?> kg</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body text-center">
                <div class="display-4 text-info mb-2">
                    <i class="bi bi-speedometer2"></i>
                </div>
                <h3 class="mb-0"><?php echo number_format($overall_utilization, 1); ?>%</h3>
                <p class="text-muted mb-0">Overall Utilization</p>
                <small class="text-muted"><?php echo number_format(($inventory_summary['total_capacity_kg'] ?? 0) / 1000, 0); ?> MT Capacity</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body text-center">
                <div class="display-4 text-danger mb-2">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <h3 class="mb-0"><?php echo $inventory_summary['critical_tanks'] ?? 0; ?></h3>
                <p class="text-muted mb-0">Critical Tanks</p>
                <small class="text-warning"><?php echo $inventory_summary['low_tanks'] ?? 0; ?> Low Stock</small>
            </div>
        </div>
    </div>
</div>

<!-- Visual Tank Cards -->
<div class="row g-4 mb-4">
    <?php if (empty($tanks)): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-database" style="font-size: 4rem; color: #dee2e6;"></i>
                    <h4 class="mt-3">No Storage Tanks Found</h4>
                    <p class="text-muted">Get started by creating your first storage tank</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($tanks as $tank): 
            $utilization = $tank['utilization_percentage'] ?? 0;
            $status = $tank['status'] ?? 'inactive';
            $tank_type = $tank['tank_type'] ?? 'vertical';
            
            // Determine colors
            $header_color = $status === 'active' ? 'bg-primary' : ($status === 'maintenance' ? 'bg-warning' : 'bg-secondary');
            $liquid_color = $utilization >= 90 ? '#dc3545' : ($utilization >= 70 ? '#ffc107' : '#28a745');
            $status_badge = $status === 'active' ? 'bg-success' : ($status === 'maintenance' ? 'bg-warning' : 'bg-secondary');
        ?>
        <div class="col-md-6 col-lg-4 col-xl-3">
            <div class="card h-100 shadow-sm tank-card">
                <div class="card-header <?php echo $header_color; ?> text-white text-center py-3">
                    <h5 class="mb-0"><?php echo htmlspecialchars($tank['tank_code']); ?></h5>
                    <small><?php echo htmlspecialchars($tank['tank_name']); ?></small>
                </div>
                <div class="card-body text-center">
                    <!-- Visual Tank Representation -->
                    <div class="tank-visual mb-3">
                        <?php if ($tank_type === 'vertical'): ?>
                            <!-- Vertical Tank SVG -->
                            <svg viewBox="0 0 100 150" class="tank-svg">
                                <!-- Tank Body -->
                                <rect x="20" y="20" width="60" height="100" 
                                      fill="#e9ecef" stroke="#495057" stroke-width="2" rx="5"/>
                                <!-- Liquid Level -->
                                <rect x="20" y="<?php echo 120 - ($utilization * 0.8); ?>" 
                                      width="60" height="<?php echo $utilization * 0.8; ?>" 
                                      fill="<?php echo $liquid_color; ?>" 
                                      opacity="0.7" rx="5"/>
                                <!-- Tank Top -->
                                <ellipse cx="50" cy="20" rx="30" ry="8" 
                                         fill="#dee2e6" stroke="#495057" stroke-width="2"/>
                                <!-- Tank Bottom -->
                                <ellipse cx="50" cy="120" rx="30" ry="8" 
                                         fill="#adb5bd" stroke="#495057" stroke-width="2"/>
                                <!-- Valve -->
                                <rect x="45" y="120" width="10" height="15" 
                                      fill="#6c757d" stroke="#495057" stroke-width="1"/>
                                <circle cx="50" cy="140" r="5" fill="#495057"/>
                            </svg>
                        <?php else: ?>
                            <!-- Horizontal Tank SVG -->
                            <svg viewBox="0 0 150 80" class="tank-svg" style="max-width: 150px;">
                                <!-- Tank Body -->
                                <ellipse cx="25" cy="40" rx="15" ry="25" 
                                         fill="#e9ecef" stroke="#495057" stroke-width="2"/>
                                <rect x="25" y="15" width="100" height="50" 
                                      fill="#e9ecef" stroke="#495057" stroke-width="2"/>
                                <ellipse cx="125" cy="40" rx="15" ry="25" 
                                         fill="#e9ecef" stroke="#495057" stroke-width="2"/>
                                <!-- Liquid Level -->
                                <rect x="25" y="<?php echo 65 - ($utilization * 0.5); ?>" 
                                      width="100" height="<?php echo $utilization * 0.5; ?>" 
                                      fill="<?php echo $liquid_color; ?>" 
                                      opacity="0.7"/>
                                <!-- Support Legs -->
                                <line x1="40" y1="65" x2="40" y2="75" stroke="#495057" stroke-width="3"/>
                                <line x1="110" y1="65" x2="110" y2="75" stroke="#495057" stroke-width="3"/>
                            </svg>
                        <?php endif; ?>
                    </div>

                    <!-- Tank Stats -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-muted">Current Stock:</small>
                            <strong><?php echo number_format(($tank['current_stock_kg'] ?? 0) / 1000, 2); ?> MT</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-muted">Capacity:</small>
                            <strong><?php echo number_format(($tank['capacity_kg'] ?? 0) / 1000, 2); ?> MT</strong>
                        </div>
                        <div class="progress" style="height: 25px;">
                            <div class="progress-bar <?php echo $utilization >= 90 ? 'bg-danger' : ($utilization >= 70 ? 'bg-warning' : 'bg-success'); ?>" 
                                 role="progressbar" 
                                 style="width: <?php echo min($utilization, 100); ?>%">
                                <strong><?php echo number_format($utilization, 1); ?>%</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Tank Info -->
                    <div class="mb-3">
                        <span class="badge bg-secondary">
                            <i class="bi bi-<?php echo $tank_type === 'vertical' ? 'arrow-up-circle' : 'arrow-left-right'; ?>"></i>
                            <?php echo ucfirst($tank_type); ?>
                        </span>
                        <span class="badge <?php echo $status_badge; ?>">
                            <?php echo ucfirst($status); ?>
                        </span>
                    </div>

                    <small class="text-muted d-block">
                        <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($tank['location'] ?? 'N/A'); ?>
                    </small>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
