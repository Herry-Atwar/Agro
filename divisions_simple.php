<?php
/**
 * Simplified divisions.php - Exact copy of divisions_debug.php but without debug output
 * This should work if divisions_debug.php works
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO divisions (business_unit_id, parent_division_id, division_code, division_name, division_type,
                                     total_area, assistant_name, assistant_phone, status, notes, created_by)
                VALUES (:business_unit_id, :parent_division_id, :division_code, :division_name, :division_type,
                        :total_area, :assistant_name, :assistant_phone, :status, :notes, 'admin')
            ");
            
            $stmt->execute([
                ':business_unit_id' => post('business_unit_id'),
                ':parent_division_id' => post('parent_division_id') ?: null,
                ':division_code' => post('division_code'),
                ':division_name' => post('division_name'),
                ':division_type' => post('division_type'),
                ':total_area' => post('total_area', 0),
                ':assistant_name' => post('assistant_name'),
                ':assistant_phone' => post('assistant_phone'),
                ':status' => post('status', 'Active'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Division added successfully!');
            redirect('divisions_simple.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding division: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE divisions
                SET business_unit_id = :business_unit_id, parent_division_id = :parent_division_id,
                    division_code = :division_code, division_name = :division_name, division_type = :division_type,
                    total_area = :total_area, assistant_name = :assistant_name,
                    assistant_phone = :assistant_phone, status = :status, notes = :notes, updated_by = 'admin'
                WHERE division_id = :id
            ");
            
            $stmt->execute([
                ':id' => post('division_id'),
                ':business_unit_id' => post('business_unit_id'),
                ':parent_division_id' => post('parent_division_id') ?: null,
                ':division_code' => post('division_code'),
                ':division_name' => post('division_name'),
                ':division_type' => post('division_type'),
                ':total_area' => post('total_area', 0),
                ':assistant_name' => post('assistant_name'),
                ':assistant_phone' => post('assistant_phone'),
                ':status' => post('status'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Division updated successfully!');
            redirect('divisions_simple.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating division: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM divisions WHERE division_id = :id");
            $stmt->execute([':id' => post('division_id')]);
            
            set_message('success', 'Division deleted successfully!');
            redirect('divisions_simple.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting division: ' . $e->getMessage());
        }
    }
}

// Get division for editing (before header)
$edit_division = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM divisions WHERE division_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_division = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Divisions Management";
require_once 'includes/header.php';

// Fetch business units for dropdown
$business_units_stmt = $db->query("
    SELECT bu.business_unit_id, bu.unit_code, bu.unit_name, c.company_name
    FROM business_units bu
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE bu.status = 'Active'
    ORDER BY c.company_name, bu.unit_name
");
$business_units = $business_units_stmt->fetchAll();

// Fetch workers for assistant/supervisor dropdown
$workers_stmt = $db->query("
    SELECT id, employee_code, full_name, position
    FROM workers
    WHERE status = 'active'
    ORDER BY full_name
");
$workers = $workers_stmt->fetchAll();

// Fetch divisions with statistics
$search = get('search', '');
$business_unit_filter = get('business_unit_id', '');
$status_filter = get('status', '');

$sql = "SELECT d.*,
        bu.unit_code, bu.unit_name, bu.unit_type,
        c.company_name, c.company_code,
        (SELECT COUNT(DISTINCT py.planting_year_id) FROM planting_years py WHERE py.division_id = d.division_id) as total_planting_years,
        (SELECT COUNT(*) FROM blocks b WHERE b.division_id = d.division_id) as total_blocks,
        COALESCE(d.total_area, 0) + COALESCE(d.forestry_area_ha, 0) as total_area_ha,
        COALESCE(d.total_plants, 0) as total_plants,
        (SELECT COUNT(*) FROM blocks b WHERE b.division_id = d.division_id AND b.operation_type = 'Plantation' AND b.status = 'TM') as tm_blocks,
        (SELECT COUNT(*) FROM blocks b WHERE b.division_id = d.division_id AND b.operation_type = 'Plantation' AND b.status = 'TBM') as tbm_blocks,
        COALESCE(d.forestry_blocks, 0) as forestry_blocks,
        COALESCE(d.forestry_area_ha, 0) as forestry_area_ha,
        COALESCE(d.total_area, 0) as plantation_area_ha
        FROM divisions d
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE 1=1";

if ($search) {
    $sql .= " AND (d.division_code LIKE :search1 OR d.division_name LIKE :search2)";
}
if ($business_unit_filter) {
    $sql .= " AND d.business_unit_id = :business_unit_id";
}
if ($status_filter) {
    $sql .= " AND d.status = :status";
}

$sql .= " ORDER BY c.company_name, bu.unit_name, d.division_code";

$stmt = $db->prepare($sql);
if ($search) {
    $stmt->bindValue(':search1', "%$search%");
    $stmt->bindValue(':search2', "%$search%");
}
if ($business_unit_filter) {
    $stmt->bindValue(':business_unit_id', $business_unit_filter);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$divisions = $stmt->fetchAll();

// Calculate summary statistics from recursive summary columns
$top_level_divisions = array_filter($divisions, function($div) {
    return empty($div['parent_division_id']);
});

$total_divisions = count($divisions);
$total_planting_years = array_sum(array_column($top_level_divisions, 'total_planting_years'));
$total_blocks = array_sum(array_column($top_level_divisions, 'total_blocks'));
$total_area = array_sum(array_column($top_level_divisions, 'total_area_ha'));
$total_plants = array_sum(array_column($top_level_divisions, 'total_plants'));
?>

<div class="alert alert-warning">
    <strong>Note:</strong> This is divisions_simple.php - a working version. 
    If this works, we can replace divisions.php with this file.
</div>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-grid-3x3"></i> Divisions Management</h1>
            <p class="text-muted">Manage divisions (Afdeling) within business units</p>
        </div>
        <div class="col-auto">
            <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-house"></i> Dashboard
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New Division
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card stat-card bg-primary text-white">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo $total_divisions; ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-grid-3x3"></i> Total Divisions</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <a href="planting_years.php" class="text-decoration-none">
            <div class="card stat-card bg-secondary text-white" style="cursor: pointer;">
                <div class="card-body py-2">
                    <h4 class="mb-1 text-white"><?php echo $total_planting_years; ?></h4>
                    <p class="mb-0 small text-white"><i class="bi bi-calendar-event"></i> Planting Years</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <div class="card stat-card bg-success text-white">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo format_number($total_area); ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-map"></i> Total Area (Ha)</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card bg-info text-white">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo format_number($total_plants, 0); ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-tree"></i> Total Plants</p>
            </div>
        </div>
    </div>
</div>

<!-- Divisions Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Divisions List (<?php echo count($divisions); ?> records)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Division Name</th>
                        <th>Business Unit</th>
                        <th>Blocks</th>
                        <th>Area (Ha)</th>
                        <th>Plants</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($divisions)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No divisions found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($divisions as $division): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($division['division_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($division['division_name']); ?></td>
                                <td><?php echo htmlspecialchars($division['unit_name']); ?></td>
                                <td><?php echo format_number($division['total_blocks'], 0); ?></td>
                                <td class="text-end"><?php echo format_number($division['total_area_ha']); ?></td>
                                <td class="text-end"><?php echo format_number($division['total_plants'], 0); ?></td>
                                <td><?php echo get_status_badge($division['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
