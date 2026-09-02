<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Kernel Inventory Report";
require_once 'includes/header.php';

// Get date range from filters
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-d'));
$storage_filter = get('storage_id', '');

// Check if kernel_storage table exists
try {
    $check = $db->query("SHOW TABLES LIKE 'kernel_storage'")->fetch();
    if (!$check) {
        echo "<div class='alert alert-danger'>";
        echo "<strong>ERROR:</strong> kernel_storage table is missing!<br>";
        echo "You need to create this table first.";
        echo "</div>";
        require_once 'includes/footer.php';
        exit;
    }
} catch (PDOException $e) {
    die("ERROR checking table: " . $e->getMessage());
}

// Fetch all kernel storage locations with stock data
try {
    $storages_stmt = $db->query("
        SELECT s.*, 
               COALESCE(st.current_stock_kg, 0) as current_stock_kg,
               COALESCE(st.utilization_percentage, 0) as utilization_percentage
        FROM kernel_storage s
        LEFT JOIN vw_kernel_stock_summary st ON s.storage_id = st.storage_id
        ORDER BY s.storage_code
    ");
    $storages = $storages_stmt->fetchAll();
} catch (PDOException $e) {
    try {
        $storages_stmt = $db->query("SELECT * FROM kernel_storage ORDER BY storage_code");
        $storages = $storages_stmt->fetchAll();
    } catch (PDOException $e) {
        die("ERROR fetching storages: " . $e->getMessage());
    }
}

// Get overall inventory summary
try {
    $inventory_summary = $db->query("
        SELECT 
            SUM(current_stock_kg) as total_stock_kg,
            SUM(capacity_kg) as total_capacity_kg,
            COUNT(*) as total_storages,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_storages,
            SUM(CASE WHEN utilization_percentage >= 90 THEN 1 ELSE 0 END) as critical_storages,
            SUM(CASE WHEN utilization_percentage <= 20 THEN 1 ELSE 0 END) as low_storages
        FROM vw_kernel_stock_summary
    ")->fetch();
} catch (PDOException $e) {
    $inventory_summary = [
        'total_stock_kg' => 0,
        'total_capacity_kg' => 0,
        'total_storages' => count($storages),
        'active_storages' => 0,
        'critical_storages' => 0,
        'low_storages' => 0
    ];
}

// Calculate overall utilization
$overall_utilization = 0;
if ($inventory_summary['total_capacity_kg'] > 0) {
    $overall_utilization = ($inventory_summary['total_stock_kg'] / $inventory_summary['total_capacity_kg']) * 100;
}
?>

<style>
/* Kernel theme - Orange/Brown colors */
.kernel-theme {
    color: #bc5420 !important;
}
.kernel-bg {
    background-color: #bc5420 !important;
}
.storage-card {
    transition: transform 0.2s, box-shadow 0.2s;
}
.storage-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1rem rgba(188, 84, 32, 0.2) !important;
}
.storage-svg {
    max-width: 140px;
    margin: 0 auto;
    display: block;
}
.stat-card {
    border: none;
    border-radius: 10px;
    transition: transform 0.2s;
    border-left: 4px solid #bc5420;
}
.stat-card:hover {
    transform: translateY(-3px);
}
.btn-kernel {
    background-color: #bc5420 !important;
    border-color: #bc5420 !important;
    color: white !important;
}
.btn-kernel:hover {
    background-color: #9a4419 !important;
    border-color: #9a4419 !important;
}
</style>

<div class="page-header mb-4">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="kernel-theme"><i class="bi bi-box-seam-fill"></i> Kernel Inventory Report</h1>
            <p class="text-muted mb-0">Visual overview of all palm kernel storage facilities</p>
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
                <div class="display-4 kernel-theme mb-2">
                    <i class="bi bi-box-seam"></i>
                </div>
                <h3 class="mb-0"><?php echo $inventory_summary['total_storages'] ?? 0; ?></h3>
                <p class="text-muted mb-0">Total Storage</p>
                <small class="text-success"><?php echo $inventory_summary['active_storages'] ?? 0; ?> Active</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body text-center">
                <div class="display-4 text-success mb-2">
                    <i class="bi bi-basket-fill"></i>
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
                <h3 class="mb-0"><?php echo $inventory_summary['critical_storages'] ?? 0; ?></h3>
                <p class="text-muted mb-0">Critical Storage</p>
                <small class="text-warning"><?php echo $inventory_summary['low_storages'] ?? 0; ?> Low Stock</small>
            </div>
        </div>
    </div>
</div>

<!-- Visual Storage Cards -->
<div class="row g-4 mb-4">
    <?php if (empty($storages)): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-box-seam" style="font-size: 4rem; color: #dee2e6;"></i>
                    <h4 class="mt-3">No Kernel Storage Found</h4>
                    <p class="text-muted">Get started by creating your first kernel storage facility</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($storages as $storage): 
            $utilization = $storage['utilization_percentage'] ?? 0;
            $status = $storage['status'] ?? 'inactive';
            $storage_type = $storage['storage_type'] ?? 'silo';
            
            // Determine colors - Kernel uses orange/brown theme
            $header_color = $status === 'active' ? 'kernel-bg' : ($status === 'maintenance' ? 'bg-warning' : 'bg-secondary');
            $liquid_color = $utilization >= 90 ? '#dc3545' : ($utilization >= 70 ? '#ffc107' : '#bc5420');
            $status_badge = $status === 'active' ? 'bg-success' : ($status === 'maintenance' ? 'bg-warning' : 'bg-secondary');
        ?>
        <div class="col-md-6 col-lg-4 col-xl-3">
            <div class="card h-100 shadow-sm storage-card">
                <div class="card-header <?php echo $header_color; ?> text-white text-center py-3">
                    <h5 class="mb-0"><?php echo htmlspecialchars($storage['storage_code']); ?></h5>
                    <small><?php echo htmlspecialchars($storage['storage_name']); ?></small>
                </div>
                <div class="card-body text-center">
                    <!-- Visual Storage Representation -->
                    <div class="storage-visual mb-3">
                        <?php if ($storage_type === 'silo'): ?>
                            <!-- Silo Storage SVG -->
                            <svg viewBox="0 0 120 160" class="storage-svg">
                                <!-- Silo Body -->
                                <rect x="30" y="30" width="60" height="90" 
                                      fill="#f5f5f5" stroke="#666" stroke-width="2" rx="3"/>
                                <!-- Kernel Level -->
                                <rect x="30" y="<?php echo 120 - ($utilization * 0.9); ?>" 
                                      width="60" height="<?php echo $utilization * 0.9; ?>" 
                                      fill="<?php echo $liquid_color; ?>" 
                                      opacity="0.8" rx="3"/>
                                <!-- Silo Top (Cone) -->
                                <polygon points="30,30 60,10 90,30" 
                                         fill="#ddd" stroke="#666" stroke-width="2"/>
                                <!-- Silo Bottom (Cone) -->
                                <polygon points="30,120 60,140 90,120" 
                                         fill="#ccc" stroke="#666" stroke-width="2"/>
                                <!-- Discharge Valve -->
                                <rect x="55" y="140" width="10" height="10" 
                                      fill="#666" stroke="#333" stroke-width="1"/>
                                <!-- Ladder -->
                                <line x1="25" y1="30" x2="25" y2="120" stroke="#999" stroke-width="2"/>
                                <line x1="20" y1="45" x2="25" y2="45" stroke="#999" stroke-width="1"/>
                                <line x1="20" y1="60" x2="25" y2="60" stroke="#999" stroke-width="1"/>
                                <line x1="20" y1="75" x2="25" y2="75" stroke="#999" stroke-width="1"/>
                                <line x1="20" y1="90" x2="25" y2="90" stroke="#999" stroke-width="1"/>
                                <line x1="20" y1="105" x2="25" y2="105" stroke="#999" stroke-width="1"/>
                            </svg>
                        <?php elseif ($storage_type === 'warehouse'): ?>
                            <!-- Warehouse Storage SVG -->
                            <svg viewBox="0 0 140 120" class="storage-svg">
                                <!-- Warehouse Building -->
                                <rect x="20" y="40" width="100" height="70" 
                                      fill="#f5f5f5" stroke="#666" stroke-width="2"/>
                                <!-- Roof -->
                                <polygon points="15,40 70,15 125,40" 
                                         fill="#bc5420" stroke="#666" stroke-width="2"/>
                                <!-- Kernel Pile -->
                                <ellipse cx="70" cy="<?php echo 105 - ($utilization * 0.4); ?>" 
                                         rx="<?php echo 30 + ($utilization * 0.2); ?>" 
                                         ry="<?php echo 15 + ($utilization * 0.15); ?>" 
                                         fill="<?php echo $liquid_color; ?>" 
                                         opacity="0.8"/>
                                <!-- Door -->
                                <rect x="60" y="80" width="20" height="30" 
                                      fill="#999" stroke="#666" stroke-width="1"/>
                                <!-- Windows -->
                                <rect x="35" y="55" width="15" height="15" 
                                      fill="#add8e6" stroke="#666" stroke-width="1"/>
                                <rect x="90" y="55" width="15" height="15" 
                                      fill="#add8e6" stroke="#666" stroke-width="1"/>
                            </svg>
                        <?php else: ?>
                            <!-- Bunker/Pile Storage SVG -->
                            <svg viewBox="0 0 140 100" class="storage-svg">
                                <!-- Ground -->
                                <rect x="10" y="80" width="120" height="5" 
                                      fill="#8b7355" stroke="#666" stroke-width="1"/>
                                <!-- Kernel Pile (Triangle/Cone shape) -->
                                <ellipse cx="70" cy="80" 
                                         rx="<?php echo 40 + ($utilization * 0.3); ?>" 
                                         ry="10" 
                                         fill="<?php echo $liquid_color; ?>" 
                                         opacity="0.6"/>
                                <ellipse cx="70" cy="<?php echo 80 - ($utilization * 0.5); ?>" 
                                         rx="<?php echo 30 + ($utilization * 0.2); ?>" 
                                         ry="8" 
                                         fill="<?php echo $liquid_color; ?>" 
                                         opacity="0.8"/>
                                <polygon points="<?php echo 30 - ($utilization * 0.2); ?>,80 70,<?php echo 30 - ($utilization * 0.3); ?> <?php echo 110 + ($utilization * 0.2); ?>,80" 
                                         fill="<?php echo $liquid_color; ?>" 
                                         opacity="0.7"/>
                                <!-- Retaining Wall -->
                                <line x1="15" y1="60" x2="15" y2="85" stroke="#666" stroke-width="3"/>
                                <line x1="125" y1="60" x2="125" y2="85" stroke="#666" stroke-width="3"/>
                            </svg>
                        <?php endif; ?>
                    </div>

                    <!-- Storage Stats -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-muted">Current Stock:</small>
                            <strong><?php echo number_format(($storage['current_stock_kg'] ?? 0) / 1000, 2); ?> MT</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-muted">Capacity:</small>
                            <strong><?php echo number_format(($storage['capacity_kg'] ?? 0) / 1000, 2); ?> MT</strong>
                        </div>
                        <div class="progress" style="height: 25px;">
                            <div class="progress-bar <?php echo $utilization >= 90 ? 'bg-danger' : ($utilization >= 70 ? 'bg-warning' : ''); ?>" 
                                 style="width: <?php echo min($utilization, 100); ?>%; <?php echo $utilization < 70 ? 'background-color: #bc5420 !important;' : ''; ?>"
                                 role="progressbar">
                                <strong><?php echo number_format($utilization, 1); ?>%</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Storage Info -->
                    <div class="mb-3">
                        <span class="badge bg-secondary">
                            <i class="bi bi-<?php echo $storage_type === 'silo' ? 'building' : ($storage_type === 'warehouse' ? 'house' : 'stack'); ?>"></i>
                            <?php echo ucfirst($storage_type); ?>
                        </span>
                        <span class="badge <?php echo $status_badge; ?>">
                            <?php echo ucfirst($status); ?>
                        </span>
                    </div>

                    <small class="text-muted d-block">
                        <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($storage['location'] ?? 'N/A'); ?>
                    </small>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
