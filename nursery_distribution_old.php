<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            $db->beginTransaction();
            
            // Insert distribution record
            $stmt = $db->prepare("
                INSERT INTO nursery_distribution (stock_id, block_id, distribution_date, quantity_distributed,
                                                  planting_date, receiver_name, vehicle_number, status, notes, created_by)
                VALUES (:stock_id, :block_id, :distribution_date, :quantity_distributed,
                        :planting_date, :receiver_name, :vehicle_number, :status, :notes, :created_by)
            ");
            
            $planting_date = post('planting_date');
            $receiver_name = post('receiver_name');
            $vehicle_number = post('vehicle_number');
            $notes = post('notes');
            
            $stmt->execute([
                ':stock_id' => post('stock_id'),
                ':block_id' => post('block_id'),
                ':distribution_date' => post('distribution_date'),
                ':quantity_distributed' => post('quantity_distributed'),
                ':planting_date' => $planting_date ? $planting_date : null,
                ':receiver_name' => $receiver_name ? $receiver_name : null,
                ':vehicle_number' => $vehicle_number ? $vehicle_number : null,
                ':status' => post('status', 'Planned'),
                ':notes' => $notes ? $notes : null,
                ':created_by' => 'admin'
            ]);
            
            // Update nursery stock quantity_ready
            $qty = post('quantity_distributed');
            $stock_id = post('stock_id');
            $update_stmt = $db->prepare("
                UPDATE nursery_stock 
                SET quantity_ready = quantity_ready - :qty,
                    status = CASE 
                        WHEN quantity_ready - :qty <= 0 THEN 'Distributed'
                        ELSE status
                    END
                WHERE stock_id = :stock_id
            ");
            $update_stmt->execute([':qty' => $qty, ':stock_id' => $stock_id]);
            
            $db->commit();
            set_message('success', 'Distribution record added successfully!');
            redirect('nursery_distribution.php');
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error adding distribution: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE nursery_distribution 
                SET stock_id = :stock_id, block_id = :block_id, distribution_date = :distribution_date,
                    quantity_distributed = :quantity_distributed, planting_date = :planting_date,
                    receiver_name = :receiver_name, vehicle_number = :vehicle_number,
                    status = :status, notes = :notes, updated_by = 'admin'
                WHERE distribution_id = :id
            ");
            
            $planting_date = post('planting_date');
            $receiver_name = post('receiver_name');
            $vehicle_number = post('vehicle_number');
            $notes = post('notes');
            
            $stmt->execute([
                ':id' => post('distribution_id'),
                ':stock_id' => post('stock_id'),
                ':block_id' => post('block_id'),
                ':distribution_date' => post('distribution_date'),
                ':quantity_distributed' => post('quantity_distributed'),
                ':planting_date' => $planting_date ? $planting_date : null,
                ':receiver_name' => $receiver_name ? $receiver_name : null,
                ':vehicle_number' => $vehicle_number ? $vehicle_number : null,
                ':status' => post('status'),
                ':notes' => $notes ? $notes : null
            ]);
            
            set_message('success', 'Distribution updated successfully!');
            redirect('nursery_distribution.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating distribution: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM nursery_distribution WHERE distribution_id = :id");
            $stmt->execute([':id' => post('distribution_id')]);
            
            set_message('success', 'Distribution deleted successfully!');
            redirect('nursery_distribution.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting distribution: ' . $e->getMessage());
        }
    }
}

// Get distribution for editing (before header)
$edit_distribution = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM nursery_distribution WHERE distribution_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_distribution = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Nursery Distribution";
require_once 'includes/header.php';

// Fetch available nursery stock (Ready status)
$stocks_stmt = $db->query("
    SELECT ns.stock_id, ns.batch_number, ns.quantity_ready,
           bu.unit_name as nursery_name,
           pv.variety_name
    FROM nursery_stock ns
    INNER JOIN business_units bu ON ns.business_unit_id = bu.business_unit_id
    INNER JOIN plant_varieties pv ON ns.variety_id = pv.variety_id
    WHERE ns.status IN ('Ready', 'Polybag') AND ns.quantity_ready > 0
    ORDER BY ns.germination_date
");
$stocks = $stocks_stmt->fetchAll();

// Fetch blocks
$blocks_stmt = $db->query("
    SELECT b.block_id, b.block_code, b.block_name,
           py.year, d.division_name, bu.unit_name, c.company_name
    FROM blocks b
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE b.status IN ('TBM', 'TM', 'TR', 'Replanting')
    ORDER BY c.company_name, bu.unit_name, d.division_name, py.year, b.block_name
");
$blocks = $blocks_stmt->fetchAll();

// Fetch distributions
$search = get('search', '');
$status_filter = get('status', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');

$sql = "SELECT nd.*,
        ns.batch_number,
        bu_nursery.unit_name as nursery_name,
        pv.variety_name,
        b.block_code, b.block_name,
        py.year as planting_year,
        d.division_name,
        bu_estate.unit_name as estate_name,
        c.company_name
        FROM nursery_distribution nd
        INNER JOIN nursery_stock ns ON nd.stock_id = ns.stock_id
        INNER JOIN business_units bu_nursery ON ns.business_unit_id = bu_nursery.business_unit_id
        INNER JOIN plant_varieties pv ON ns.variety_id = pv.variety_id
        INNER JOIN blocks b ON nd.block_id = b.block_id
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu_estate ON d.business_unit_id = bu_estate.business_unit_id
        INNER JOIN companies c ON bu_estate.company_id = c.company_id
        WHERE 1=1";

if ($search) {
    $sql .= " AND (ns.batch_number LIKE :search1 OR b.block_name LIKE :search2 OR nd.receiver_name LIKE :search3)";
}
if ($status_filter) {
    $sql .= " AND nd.status = :status";
}
if ($date_from) {
    $sql .= " AND nd.distribution_date >= :date_from";
}
if ($date_to) {
    $sql .= " AND nd.distribution_date <= :date_to";
}

$sql .= " ORDER BY nd.distribution_date DESC, nd.distribution_id DESC";

$stmt = $db->prepare($sql);
if ($search) {
    $stmt->bindValue(':search1', "%$search%");
    $stmt->bindValue(':search2', "%$search%");
    $stmt->bindValue(':search3', "%$search%");
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
if ($date_from) {
    $stmt->bindValue(':date_from', $date_from);
}
if ($date_to) {
    $stmt->bindValue(':date_to', $date_to);
}
$stmt->execute();
$distributions = $stmt->fetchAll();

// Calculate summary statistics
$total_distributed = array_sum(array_column($distributions, 'quantity_distributed'));
$planned_count = count(array_filter($distributions, function($d) { return $d['status'] == 'Planned'; }));
$in_transit_count = count(array_filter($distributions, function($d) { return $d['status'] == 'In Transit'; }));
$delivered_count = count(array_filter($distributions, function($d) { return $d['status'] == 'Delivered'; }));
$planted_count = count(array_filter($distributions, function($d) { return $d['status'] == 'Planted'; }));
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-truck"></i> Nursery Distribution</h1>
            <p class="text-muted">Track seedling distribution to planting blocks</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add Distribution
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_distributed, 0); ?></h3>
                <p><i class="bi bi-box-seam"></i> Total Distributed</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $planned_count; ?></h3>
                <p><i class="bi bi-calendar"></i> Planned</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $in_transit_count; ?></h3>
                <p><i class="bi bi-truck"></i> In Transit</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $delivered_count; ?></h3>
                <p><i class="bi bi-box-arrow-down"></i> Delivered</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $planted_count; ?></h3>
                <p><i class="bi bi-check-circle"></i> Planted</p>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="Planned" <?php echo $status_filter == 'Planned' ? 'selected' : ''; ?>>Planned</option>
                    <option value="In Transit" <?php echo $status_filter == 'In Transit' ? 'selected' : ''; ?>>In Transit</option>
                    <option value="Delivered" <?php echo $status_filter == 'Delivered' ? 'selected' : ''; ?>>Delivered</option>
                    <option value="Planted" <?php echo $status_filter == 'Planted' ? 'selected' : ''; ?>>Planted</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" placeholder="From Date" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" placeholder="To Date" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                <a href="nursery_distribution.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Distribution Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Distribution Records (<?php echo count($distributions); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Batch Number</th>
                        <th>Variety</th>
                        <th>From Nursery</th>
                        <th>To Block</th>
                        <th>Quantity</th>
                        <th>Receiver</th>
                        <th>Vehicle</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($distributions)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No distribution records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($distributions as $dist): ?>
                            <tr>
                                <td><?php echo format_date($dist['distribution_date']); ?></td>
                                <td><strong><?php echo htmlspecialchars($dist['batch_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($dist['variety_name']); ?></td>
                                <td><?php echo htmlspecialchars($dist['nursery_name']); ?></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($dist['estate_name']); ?></small><br>
                                    <?php echo htmlspecialchars($dist['block_name']); ?>
                                </td>
                                <td><?php echo format_number($dist['quantity_distributed'], 0); ?></td>
                                <td><?php echo htmlspecialchars($dist['receiver_name']); ?></td>
                                <td><?php echo htmlspecialchars($dist['vehicle_number']); ?></td>
                                <td><?php echo get_status_badge($dist['status']); ?></td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $dist['distribution_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="nursery_distribution.php" style="display:inline;" onsubmit="return confirmDelete('Delete this distribution?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="distribution_id" value="<?php echo $dist['distribution_id']; ?>">
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

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="nursery_distribution.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_distribution ? 'Edit Distribution' : 'Add Distribution'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_distribution ? 'edit' : 'add'; ?>">
                    <?php if ($edit_distribution): ?>
                        <input type="hidden" name="distribution_id" value="<?php echo $edit_distribution['distribution_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nursery Stock Batch <span class="text-danger">*</span></label>
                            <select class="form-select" name="stock_id" required id="stock_select">
                                <option value="">Select Stock Batch</option>
                                <?php foreach ($stocks as $stock): ?>
                                    <option value="<?php echo $stock['stock_id']; ?>" 
                                        data-available="<?php echo $stock['quantity_ready']; ?>"
                                        <?php echo ($edit_distribution && $edit_distribution['stock_id'] == $stock['stock_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($stock['batch_number'] . ' - ' . $stock['variety_name'] . ' (' . format_number($stock['quantity_ready'], 0) . ' available)'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" id="available_qty"></small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Destination Block <span class="text-danger">*</span></label>
                            <select class="form-select" name="block_id" required>
                                <option value="">Select Block</option>
                                <?php foreach ($blocks as $block): ?>
                                    <option value="<?php echo $block['block_id']; ?>" 
                                        <?php echo ($edit_distribution && $edit_distribution['block_id'] == $block['block_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($block['company_name'] . ' - ' . $block['unit_name'] . ' - ' . $block['block_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Distribution Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="distribution_date" required
                                   value="<?php echo $edit_distribution ? $edit_distribution['distribution_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity to Distribute <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="quantity_distributed" required
                                   value="<?php echo $edit_distribution ? $edit_distribution['quantity_distributed'] : ''; ?>"
                                   placeholder="Enter quantity">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Planting Date</label>
                            <input type="date" class="form-control" name="planting_date"
                                   value="<?php echo $edit_distribution ? $edit_distribution['planting_date'] : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Receiver Name</label>
                            <input type="text" class="form-control" name="receiver_name"
                                   value="<?php echo $edit_distribution ? htmlspecialchars($edit_distribution['receiver_name']) : ''; ?>"
                                   placeholder="e.g., John Doe">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Vehicle Number</label>
                            <input type="text" class="form-control" name="vehicle_number"
                                   value="<?php echo $edit_distribution ? htmlspecialchars($edit_distribution['vehicle_number']) : ''; ?>"
                                   placeholder="e.g., B 1234 XYZ">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Planned" <?php echo ($edit_distribution && $edit_distribution['status'] == 'Planned') ? 'selected' : ''; ?>>Planned</option>
                                <option value="In Transit" <?php echo ($edit_distribution && $edit_distribution['status'] == 'In Transit') ? 'selected' : ''; ?>>In Transit</option>
                                <option value="Delivered" <?php echo ($edit_distribution && $edit_distribution['status'] == 'Delivered') ? 'selected' : ''; ?>>Delivered</option>
                                <option value="Planted" <?php echo ($edit_distribution && $edit_distribution['status'] == 'Planted') ? 'selected' : ''; ?>>Planted</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo $edit_distribution ? htmlspecialchars($edit_distribution['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_distribution ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_distribution): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

<script>
function confirmDelete(message) {
    return confirm(message);
}

// Show available quantity when stock is selected
document.getElementById('stock_select').addEventListener('change', function() {
    var selected = this.options[this.selectedIndex];
    var available = selected.getAttribute('data-available');
    if (available) {
        document.getElementById('available_qty').textContent = 'Available: ' + available + ' seedlings';
    } else {
        document.getElementById('available_qty').textContent = '';
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
