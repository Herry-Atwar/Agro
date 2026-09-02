<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add_transaction') {
        try {
            $stmt = $db->prepare("
                INSERT INTO cpo_stock_transactions
                (transaction_date, transaction_time, transaction_type, storage_tank_id,
                 production_id, quantity_kg, reference_no, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('transaction_date'),
                post('transaction_time'),
                post('transaction_type'),
                post('storage_tank_id'),
                post('production_id') ?: null,
                post('quantity_kg'),
                post('reference_no'),
                post('remarks'),
                'admin'
            ]);
            
            set_message('success', 'Stock transaction recorded successfully!');
            redirect('cpo_stock.php');
        } catch (PDOException $e) {
            set_message('error', 'Error recording transaction: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit_transaction') {
        try {
            $stmt = $db->prepare("
                UPDATE cpo_stock_transactions
                SET transaction_date = ?, transaction_time = ?, transaction_type = ?,
                    storage_tank_id = ?, production_id = ?, quantity_kg = ?,
                    reference_no = ?, remarks = ?, updated_by = ?
                WHERE transaction_id = ?
            ");
            
            $stmt->execute([
                post('transaction_date'),
                post('transaction_time'),
                post('transaction_type'),
                post('storage_tank_id'),
                post('production_id') ?: null,
                post('quantity_kg'),
                post('reference_no'),
                post('remarks'),
                'admin',
                post('transaction_id')
            ]);
            
            set_message('success', 'Transaction updated successfully!');
            redirect('cpo_stock.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating transaction: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete_transaction') {
        try {
            $stmt = $db->prepare("DELETE FROM cpo_stock_transactions WHERE transaction_id = ?");
            $stmt->execute([post('transaction_id')]);
            
            set_message('success', 'Transaction deleted successfully!');
            redirect('cpo_stock.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting transaction: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'add_tank') {
        try {
            $stmt = $db->prepare("
                INSERT INTO storage_tanks
                (tank_code, tank_name, tank_type, capacity_kg, location,
                 status, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('tank_code'),
                post('tank_name'),
                post('tank_type'),
                post('capacity_kg'),
                post('location'),
                post('status'),
                post('remarks'),
                'admin'
            ]);
            
            set_message('success', 'Storage tank added successfully!');
            redirect('cpo_stock.php?tab=tanks');
        } catch (PDOException $e) {
            set_message('error', 'Error adding tank: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit_tank') {
        try {
            $stmt = $db->prepare("
                UPDATE storage_tanks
                SET tank_code = ?, tank_name = ?, tank_type = ?, capacity_kg = ?,
                    location = ?, status = ?, remarks = ?, updated_by = ?
                WHERE tank_id = ?
            ");
            
            $stmt->execute([
                post('tank_code'),
                post('tank_name'),
                post('tank_type'),
                post('capacity_kg'),
                post('location'),
                post('status'),
                post('remarks'),
                'admin',
                post('tank_id')
            ]);
            
            set_message('success', 'Tank updated successfully!');
            redirect('cpo_stock.php?tab=tanks');
        } catch (PDOException $e) {
            set_message('error', 'Error updating tank: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_transaction = null;
$edit_tank = null;
if (get('action') == 'edit_transaction' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM cpo_stock_transactions WHERE transaction_id = ?");
    $stmt->execute([get('id')]);
    $edit_transaction = $stmt->fetch();
}
if (get('action') == 'edit_tank' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM storage_tanks WHERE tank_id = ?");
    $stmt->execute([get('id')]);
    $edit_tank = $stmt->fetch();
}

// Now include header after form processing
$page_title = "CPO Stock Management";
require_once 'includes/header.php';

$active_tab = get('tab', 'stock');

// Fetch storage tanks for dropdown
try {
    $tanks_stmt = $db->query("
        SELECT t.*,
               COALESCE(s.current_stock_kg, 0) as current_stock_kg,
               ROUND((COALESCE(s.current_stock_kg, 0) / t.capacity_kg * 100), 2) as utilization_pct
        FROM storage_tanks t
        LEFT JOIN vw_tank_stock_summary s ON t.tank_id = s.tank_id
        ORDER BY t.tank_code
    ");
    $tanks = $tanks_stmt->fetchAll();
} catch (PDOException $e) {
    $tanks = [];
}

// Fetch production records for dropdown
try {
    $productions_stmt = $db->query("
        SELECT p.production_id, p.production_date, p.cpo_produced_kg,
               b.batch_no, m.mill_name
        FROM mill_production p
        INNER JOIN mill_processing_batch b ON p.batch_id = b.batch_id
        INNER JOIN mill_master m ON b.mill_id = m.mill_id
        ORDER BY p.production_date DESC
        LIMIT 100
    ");
    $productions = $productions_stmt->fetchAll();
} catch (PDOException $e) {
    $productions = [];
}

// Fetch stock transactions with filters
$search = get('search', '');
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-d'));
$type_filter = get('transaction_type', '');
$tank_filter = get('tank_id', '');

$sql = "SELECT t.*, 
        s.tank_code, s.tank_name,
        p.production_date, b.batch_no
        FROM cpo_stock_transactions t
        INNER JOIN storage_tanks s ON t.storage_tank_id = s.tank_id
        LEFT JOIN mill_production p ON t.production_id = p.production_id
        LEFT JOIN mill_processing_batch b ON p.batch_id = b.batch_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (t.reference_no LIKE ? OR s.tank_code LIKE ? OR b.batch_no LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($date_from) {
    $sql .= " AND t.transaction_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND t.transaction_date <= ?";
    $params[] = $date_to;
}
if ($type_filter) {
    $sql .= " AND t.transaction_type = ?";
    $params[] = $type_filter;
}
if ($tank_filter) {
    $sql .= " AND t.storage_tank_id = ?";
    $params[] = $tank_filter;
}

$sql .= " ORDER BY t.transaction_date DESC, t.transaction_time DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Calculate summary statistics
$total_in = array_sum(array_map(function($t) {
    return $t['transaction_type'] == 'in' ? $t['quantity_kg'] : 0;
}, $transactions));

$total_out = array_sum(array_map(function($t) {
    return $t['transaction_type'] == 'out' ? $t['quantity_kg'] : 0;
}, $transactions));

$total_adjustment = array_sum(array_map(function($t) {
    return $t['transaction_type'] == 'adjustment' ? $t['quantity_kg'] : 0;
}, $transactions));

// Get overall stock summary
try {
    $stock_summary = $db->query("
        SELECT
            SUM(current_stock_kg) as total_stock,
            SUM(capacity_kg)      as total_capacity,
            COUNT(*)              as total_tanks,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_tanks
        FROM vw_tank_stock_summary
    ")->fetch();
} catch (PDOException $e) {
    $stock_summary = ['total_stock' => 0, 'total_capacity' => 0, 'total_tanks' => 0, 'active_tanks' => 0];
}

$transaction_types = ['in', 'out', 'adjustment', 'transfer'];
$tank_statuses = ['active', 'maintenance', 'inactive'];
$tank_types = ['vertical', 'horizontal', 'underground'];
?>

<style>
    /* CPO blue theme */
    .card-header:not(.bg-warning):not(.bg-danger):not(.bg-success) {
        background-color: #0d6efd !important;
        color: white !important;
    }
    .page-header h1 { color: #0d6efd !important; }
    .page-header { border-bottom-color: #0d6efd !important; }
    .stat-card { border-left-color: #0d6efd !important; }
    .nav-tabs .nav-link.active {
        color: #0d6efd !important;
        border-bottom-color: #0d6efd !important;
    }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-droplet-fill"></i> CPO Stock Management</h1>
            <p class="text-muted">Crude Palm Oil inventory tracking and storage tank management</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                <i class="bi bi-plus-circle"></i> Add Transaction
            </button>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addTankModal">
                <i class="bi bi-database-add"></i> Add Tank
            </button>
            <a href="inventory_cpo.php" class="btn btn-outline-secondary">
                <i class="bi bi-bar-chart-line"></i> Inventory Report
            </a>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($stock_summary['total_stock'] ?? 0, 0); ?> kg</h3>
                <p><i class="bi bi-droplet-fill text-primary"></i> Total Stock</p>
                <small class="text-muted"><?php echo format_number(($stock_summary['total_stock'] ?? 0) / 1000, 2); ?> MT</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($stock_summary['total_capacity'] ?? 0, 0); ?> kg</h3>
                <p><i class="bi bi-database text-info"></i> Total Capacity</p>
                <small class="text-muted">
                    <?php 
                    $utilization = ($stock_summary['total_capacity'] ?? 0) > 0 
                        ? ($stock_summary['total_stock'] ?? 0) / $stock_summary['total_capacity'] * 100 
                        : 0;
                    echo format_number($utilization, 1); 
                    ?>% Utilized
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $stock_summary['active_tanks'] ?? 0; ?> / <?php echo $stock_summary['total_tanks'] ?? 0; ?></h3>
                <p><i class="bi bi-check-circle text-success"></i> Active Tanks</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo count($transactions); ?></h3>
                <p><i class="bi bi-arrow-left-right"></i> Transactions</p>
                <small class="text-muted">Selected Period</small>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo $active_tab == 'stock' ? 'active' : ''; ?>" href="?tab=stock">
            <i class="bi bi-list-ul"></i> Stock Transactions
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $active_tab == 'tanks' ? 'active' : ''; ?>" href="?tab=tanks">
            <i class="bi bi-fuel-pump"></i> Storage Tanks
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $active_tab == 'summary' ? 'active' : ''; ?>" href="?tab=summary">
            <i class="bi bi-bar-chart"></i> Stock Summary
        </a>
    </li>
</ul>

<?php if ($active_tab == 'stock'): ?>
    <!-- Stock Transactions Tab -->
    
    <!-- Transaction Summary -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-graph-up"></i> Transaction Summary (Selected Period)
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h4 class="text-success"><?php echo format_number($total_in, 0); ?> kg</h4>
                            <small>Stock In</small>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-danger"><?php echo format_number($total_out, 0); ?> kg</h4>
                            <small>Stock Out</small>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-warning"><?php echo format_number($total_adjustment, 0); ?> kg</h4>
                            <small>Adjustments</small>
                        </div>
                        <div class="col-md-3">
                            <h4 style="color:#0d6efd"><?php echo format_number($total_in - $total_out + $total_adjustment, 0); ?> kg</h4>
                            <small>Net Change</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Search and Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="tab" value="stock">
                <div class="col-md-2">
                    <input type="text" class="form-control" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="transaction_type">
                        <option value="">All Types</option>
                        <?php foreach ($transaction_types as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $type_filter == $type ? 'selected' : ''; ?>>
                                <?php echo ucfirst($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="tank_id">
                        <option value="">All Tanks</option>
                        <?php foreach ($tanks as $tank): ?>
                            <option value="<?php echo $tank['tank_id']; ?>" <?php echo $tank_filter == $tank['tank_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tank['tank_code']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                    <a href="?tab=stock" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Transactions Table -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-list-ul"></i> Stock Transactions (<?php echo count($transactions); ?>)
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Type</th>
                            <th>Tank</th>
                            <th>Quantity (kg)</th>
                            <th>Reference</th>
                            <th>Batch</th>
                            <th>Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">No transactions found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $trans): ?>
                                <tr>
                                    <td>
                                        <?php echo format_date($trans['transaction_date']); ?><br>
                                        <small class="text-muted"><?php echo date('H:i', strtotime($trans['transaction_time'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php
                                            echo $trans['transaction_type'] == 'in' ? 'success' :
                                                ($trans['transaction_type'] == 'out' ? 'danger' :
                                                ($trans['transaction_type'] == 'transfer' ? 'info' : 'warning'));
                                        ?>">
                                            <?php echo strtoupper($trans['transaction_type']); ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($trans['tank_code']); ?></strong></td>
                                    <td class="text-end">
                                        <strong><?php echo format_number($trans['quantity_kg'], 0); ?></strong> kg
                                        <br><small class="text-muted"><?php echo format_number($trans['quantity_kg']/1000, 2); ?> MT</small>
                                    </td>
                                    <td><?php echo htmlspecialchars($trans['reference_no']); ?></td>
                                    <td><?php echo $trans['batch_no'] ? htmlspecialchars($trans['batch_no']) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars(substr($trans['remarks'] ?? '', 0, 50)); ?></td>
                                    <td>
                                        <a href="?action=edit_transaction&id=<?php echo $trans['transaction_id']; ?>&tab=stock" class="btn btn-sm btn-warning" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this transaction?');">
                                            <input type="hidden" name="action" value="delete_transaction">
                                            <input type="hidden" name="transaction_id" value="<?php echo $trans['transaction_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($active_tab == 'tanks'): ?>
    <!-- Storage Tanks Tab -->
    
    <div class="card">
        <div class="card-header">
            <i class="bi bi-database"></i> Storage Tanks (<?php echo count($tanks); ?>)
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Tank Code</th>
                            <th>Tank Name</th>
                            <th>Type</th>
                            <th>Capacity</th>
                            <th>Current Stock</th>
                            <th>Utilization</th>
                            <th>Status</th>
                            <th>Location</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tanks as $tank): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($tank['tank_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($tank['tank_name']); ?></td>
                                <td><?php echo ucfirst($tank['tank_type']); ?></td>
                                <td class="text-end">
                                    <?php echo format_number($tank['capacity_kg'], 0); ?> kg
                                    <br><small class="text-muted"><?php echo format_number($tank['capacity_kg']/1000, 2); ?> MT</small>
                                </td>
                                <td class="text-end">
                                    <?php echo format_number($tank['current_stock_kg'], 0); ?> kg
                                    <br><small class="text-muted"><?php echo format_number($tank['current_stock_kg']/1000, 2); ?> MT</small>
                                </td>
                                <td>
                                    <div class="progress" style="height: 25px;">
                                        <div class="progress-bar bg-<?php 
                                            echo $tank['utilization_pct'] >= 90 ? 'danger' : 
                                                ($tank['utilization_pct'] >= 70 ? 'warning' : 'success'); 
                                        ?>" 
                                             role="progressbar" 
                                             style="width: <?php echo $tank['utilization_pct']; ?>%">
                                            <?php echo format_number($tank['utilization_pct'], 1); ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $tank['status'] == 'active' ? 'success' : 
                                            ($tank['status'] == 'maintenance' ? 'warning' : 'secondary'); 
                                    ?>">
                                        <?php echo ucfirst($tank['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($tank['location']); ?></td>
                                <td>
                                    <a href="?action=edit_tank&id=<?php echo $tank['tank_id']; ?>&tab=tanks" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- Stock Summary Tab -->

    <?php
    // Get stock by tank
    try {
        $tank_stock = $db->query("
            SELECT * FROM vw_tank_stock_summary
            ORDER BY current_stock_kg DESC
        ")->fetchAll();
    } catch (PDOException $e) {
        $tank_stock = [];
    }

    // Get recent movements
    try {
        $recent_movements = $db->query("
            SELECT
                DATE(transaction_date) as date,
                SUM(CASE WHEN transaction_type = 'in'         THEN quantity_kg ELSE 0 END) as stock_in,
                SUM(CASE WHEN transaction_type = 'out'        THEN quantity_kg ELSE 0 END) as stock_out,
                SUM(CASE WHEN transaction_type = 'adjustment' THEN quantity_kg ELSE 0 END) as adjustments
            FROM cpo_stock_transactions
            WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(transaction_date)
            ORDER BY date DESC
            LIMIT 10
        ")->fetchAll();
    } catch (PDOException $e) {
        $recent_movements = [];
    }
    ?>
    
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-pie-chart"></i> Stock by Tank
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Tank</th>
                                <th class="text-end">Stock (kg)</th>
                                <th class="text-end">Capacity %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tank_stock as $ts): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($ts['tank_code']); ?></strong></td>
                                    <td class="text-end"><?php echo format_number($ts['current_stock_kg'], 0); ?></td>
                                    <td class="text-end">
                                        <span class="badge bg-<?php 
                                            $util = ($ts['current_stock_kg'] / $ts['capacity_kg'] * 100);
                                            echo $util >= 90 ? 'danger' : ($util >= 70 ? 'warning' : 'success'); 
                                        ?>">
                                            <?php echo format_number($util, 1); ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-graph-up"></i> Recent Stock Movements (Last 30 Days)
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-end">In (kg)</th>
                                <th class="text-end">Out (kg)</th>
                                <th class="text-end">Net (kg)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_movements as $mov): ?>
                                <tr>
                                    <td><?php echo format_date($mov['date']); ?></td>
                                    <td class="text-end text-success"><?php echo format_number($mov['stock_in'], 0); ?></td>
                                    <td class="text-end text-danger"><?php echo format_number($mov['stock_out'], 0); ?></td>
                                    <td class="text-end">
                                        <strong><?php echo format_number($mov['stock_in'] - $mov['stock_out'] + $mov['adjustments'], 0); ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recent_movements)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No movements in the last 30 days</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTransactionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="cpo_stock.php">
                <div class="modal-header text-white" style="background-color:#0d6efd">
                    <h5 class="modal-title">
                        <?php echo $edit_transaction ? 'Edit Transaction' : 'Add CPO Stock Transaction'; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_transaction ? 'edit_transaction' : 'add_transaction'; ?>">
                    <?php if ($edit_transaction): ?>
                        <input type="hidden" name="transaction_id" value="<?php echo $edit_transaction['transaction_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Transaction Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="transaction_date" required
                                   value="<?php echo $edit_transaction ? $edit_transaction['transaction_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Transaction Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="transaction_time" required
                                   value="<?php echo $edit_transaction ? date('H:i', strtotime($edit_transaction['transaction_time'])) : date('H:i'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Transaction Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="transaction_type" required>
                                <?php foreach ($transaction_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($edit_transaction && $edit_transaction['transaction_type'] == $type) ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Storage Tank <span class="text-danger">*</span></label>
                            <select class="form-select" name="storage_tank_id" required>
                                <option value="">Select Tank</option>
                                <?php foreach ($tanks as $tank): ?>
                                    <option value="<?php echo $tank['tank_id']; ?>"
                                        <?php echo ($edit_transaction && $edit_transaction['storage_tank_id'] == $tank['tank_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tank['tank_code'] . ' - ' . $tank['tank_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Production Batch (Optional)</label>
                            <select class="form-select" name="production_id">
                                <option value="">Select Production</option>
                                <?php foreach ($productions as $prod): ?>
                                    <option value="<?php echo $prod['production_id']; ?>"
                                        <?php echo ($edit_transaction && $edit_transaction['production_id'] == $prod['production_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($prod['batch_no'] . ' - ' . format_date($prod['production_date'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity (kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="quantity_kg" required
                                   value="<?php echo $edit_transaction ? $edit_transaction['quantity_kg'] : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reference No</label>
                            <input type="text" class="form-control" name="reference_no"
                                   value="<?php echo $edit_transaction ? htmlspecialchars($edit_transaction['reference_no']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="3"><?php echo $edit_transaction ? htmlspecialchars($edit_transaction['remarks']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_transaction ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Tank Modal -->
<div class="modal fade" id="addTankModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="cpo_stock.php">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <?php echo $edit_tank ? 'Edit Storage Tank' : 'Add Storage Tank'; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_tank ? 'edit_tank' : 'add_tank'; ?>">
                    <?php if ($edit_tank): ?>
                        <input type="hidden" name="tank_id" value="<?php echo $edit_tank['tank_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tank Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="tank_code" required
                                   value="<?php echo $edit_tank ? htmlspecialchars($edit_tank['tank_code']) : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tank Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="tank_name" required
                                   value="<?php echo $edit_tank ? htmlspecialchars($edit_tank['tank_name']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Tank Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="tank_type" required>
                                <?php foreach ($tank_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($edit_tank && $edit_tank['tank_type'] == $type) ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Capacity (kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="capacity_kg" required
                                   value="<?php echo $edit_tank ? $edit_tank['capacity_kg'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" name="status" required>
                                <?php foreach ($tank_statuses as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo ($edit_tank && $edit_tank['status'] == $status) ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($status); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <input type="text" class="form-control" name="location"
                               value="<?php echo $edit_tank ? htmlspecialchars($edit_tank['location']) : ''; ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="3"><?php echo $edit_tank ? htmlspecialchars($edit_tank['remarks']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save"></i> <?php echo $edit_tank ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_transaction): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addTransactionModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

<?php if ($edit_tank): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addTankModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
