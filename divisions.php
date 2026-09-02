<?php
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
            redirect('divisions.php');
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
            redirect('divisions.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating division: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM divisions WHERE division_id = :id");
            $stmt->execute([':id' => post('division_id')]);
            
            set_message('success', 'Division deleted successfully!');
            redirect('divisions.php');
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
?>
<style>
.btn-agro {
    background-color: #3a618c;
    border-color: #3a618c;
    color: #fff;
}
.btn-agro:hover, .btn-agro:focus {
    background-color: #4d7aaa;
    border-color: #4d7aaa;
    color: #fff;
}
.page-header {
    border-bottom-color: #3a618c !important;
}
</style>
<?php

// ── Scope: read from session ──────────────────────────────────────────────────
$scope_company_id = $_SESSION['company_id']      ?? null;  // null = show all
$scope_bu_id      = $_SESSION['business_unit_id'] ?? null;  // null = show all BUs

// Fetch companies for modal dropdown — scoped when user has a company
$companies_stmt = $db->prepare(
    "SELECT company_id, company_name FROM companies WHERE status='Active'"
    . ($scope_company_id !== null ? " AND company_id = ?" : "")
    . " ORDER BY company_name"
);
$companies_stmt->execute($scope_company_id !== null ? [$scope_company_id] : []);
$companies = $companies_stmt->fetchAll();

// Fetch business units — scoped to session's company (and/or BU) when set
$bu_params = [];
$bu_where  = "bu.status = 'Active'";
if ($scope_bu_id !== null) {
    $bu_where   .= " AND bu.business_unit_id = ?";
    $bu_params[] = $scope_bu_id;
} elseif ($scope_company_id !== null) {
    $bu_where   .= " AND bu.company_id = ?";
    $bu_params[] = $scope_company_id;
}
$bu_stmt = $db->prepare("
    SELECT bu.business_unit_id, bu.unit_code, bu.unit_name, bu.company_id, c.company_name
    FROM business_units bu
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE $bu_where
    ORDER BY c.company_name, bu.unit_code
");
$bu_stmt->execute($bu_params);
$business_units = $bu_stmt->fetchAll();

$company_locked = ($scope_company_id !== null);
$bu_locked      = ($scope_bu_id !== null);

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
// Session scope takes priority over GET filter
$business_unit_filter = $scope_bu_id !== null
    ? (string)$scope_bu_id
    : ($scope_company_id !== null ? '' : get('business_unit_id', ''));
$status_filter = get('status', '');

$sql = "SELECT d.*,
        bu.unit_code, bu.unit_name, bu.unit_type,
        c.company_name, c.company_code,
        (SELECT COUNT(DISTINCT py.planting_year_id) FROM planting_years py WHERE py.division_id = d.division_id) as total_planting_years,
        (SELECT COUNT(*) FROM blocks b WHERE b.division_id = d.division_id) as total_blocks,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.division_id = d.division_id AND b.status = 'TM')         as area_tm,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.division_id = d.division_id AND b.status = 'TBM')        as area_tbm,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.division_id = d.division_id AND b.status = 'HL')         as area_hl,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.division_id = d.division_id AND b.status = 'HP')         as area_hp,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.division_id = d.division_id AND b.status = 'HPT')        as area_hpt,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.division_id = d.division_id AND b.status = 'LC')         as area_lc,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.division_id = d.division_id AND b.status = 'Replanting') as area_replanting,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.division_id = d.division_id
         AND b.status NOT IN ('TM','TBM','HL','HP','HPT','LC','Replanting'))          as area_other,
        COALESCE(d.total_area, 0) as plantation_area_ha
        FROM divisions d
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE 1=1";

// Apply session scope
if ($scope_bu_id !== null) {
    $sql .= " AND d.business_unit_id = :scope_bu";
} elseif ($scope_company_id !== null) {
    $sql .= " AND bu.company_id = :scope_company";
}
if ($search) {
    $sql .= " AND (d.division_code LIKE :search1 OR d.division_name LIKE :search2)";
}
if ($business_unit_filter && $scope_bu_id === null) {
    $sql .= " AND d.business_unit_id = :business_unit_id";
}
if ($status_filter) {
    $sql .= " AND d.status = :status";
}

$sql .= " ORDER BY c.company_name, bu.unit_code, d.division_code";

$stmt = $db->prepare($sql);
if ($scope_bu_id !== null) {
    $stmt->bindValue(':scope_bu', $scope_bu_id, PDO::PARAM_INT);
} elseif ($scope_company_id !== null) {
    $stmt->bindValue(':scope_company', $scope_company_id, PDO::PARAM_INT);
}
if ($search) {
    $stmt->bindValue(':search1', "%$search%");
    $stmt->bindValue(':search2', "%$search%");
}
if ($business_unit_filter && $scope_bu_id === null) {
    $stmt->bindValue(':business_unit_id', $business_unit_filter);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$divisions = $stmt->fetchAll();

// Calculate summary statistics from recursive summary columns
// Only count top-level divisions (without parent) to avoid double-counting
$top_level_divisions = array_filter($divisions, function($div) {
    return empty($div['parent_division_id']);
});

$total_divisions = count($divisions); // Count all divisions for display
$total_planting_years = array_sum(array_column($top_level_divisions, 'total_planting_years'));
$total_blocks = array_sum(array_column($top_level_divisions, 'total_blocks'));
$total_area = array_sum(array_column($top_level_divisions, 'total_area_ha'));
$total_plants = array_sum(array_column($top_level_divisions, 'total_plants'));
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color: #3a618c;"><i class="bi bi-grid-3x3"></i> Divisions Management</h1>
            <p class="text-muted mb-0">Manage divisions (Afdeling) within business units</p>
            <?php if ($scope_bu_id !== null): ?>
                <small class="text-success"><i class="bi bi-lock-fill"></i> Showing your assigned business unit only</small>
            <?php elseif ($scope_company_id !== null): ?>
                <small class="text-success"><i class="bi bi-lock-fill"></i> Showing your assigned company only</small>
            <?php endif; ?>
        </div>
        <div class="col-auto">
            <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-house"></i> Dashboard
            </a>
            <button type="button" class="btn btn-agro" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New Division
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card stat-card" style="background-color: #fff; border-left: 4px solid #3a618c;">
            <div class="card-body py-2">
                <h4 class="mb-1" style="color: #3a618c;"><?php echo $total_divisions; ?></h4>
                <p class="mb-0 small text-muted"><i class="bi bi-grid-3x3"></i> Total Divisions</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <a href="planting_years.php" class="text-decoration-none">
            <div class="card stat-card" style="background-color: #fff; border-left: 4px solid #3a618c; cursor: pointer;">
                <div class="card-body py-2">
                    <h4 class="mb-1" style="color: #3a618c;"><?php echo $total_planting_years; ?></h4>
                    <p class="mb-0 small text-muted"><i class="bi bi-calendar-event"></i> Planting Years</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" style="background-color: #fff; border-left: 4px solid #3a618c;">
            <div class="card-body py-2">
                <h4 class="mb-1" style="color: #3a618c;"><?php echo format_number($total_area); ?></h4>
                <p class="mb-0 small text-muted"><i class="bi bi-map"></i> Total Area (Ha)</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" style="background-color: #fff; border-left: 4px solid #3a618c;">
            <div class="card-body py-2">
                <h4 class="mb-1" style="color: #3a618c;"><?php echo format_number($total_plants, 0); ?></h4>
                <p class="mb-0 small text-muted"><i class="bi bi-tree"></i> Total Plants</p>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="Search by code or name..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-4">
                <?php if ($bu_locked): ?>
                    <?php $locked_bu = $business_units[0] ?? null; ?>
                    <input type="hidden" name="business_unit_id" value="<?php echo $scope_bu_id; ?>">
                    <div class="form-control bg-light d-flex align-items-center gap-2" style="height:auto;">
                        <i class="bi bi-lock-fill text-success"></i>
                        <span><?php echo $locked_bu ? htmlspecialchars($locked_bu['company_name'] . ' — ' . $locked_bu['unit_name']) : 'Your Business Unit'; ?></span>
                    </div>
                <?php else: ?>
                    <select class="form-select" name="business_unit_id">
                        <option value="">All Business Units</option>
                        <?php foreach ($business_units as $unit): ?>
                            <option value="<?php echo $unit['business_unit_id']; ?>" <?php echo $business_unit_filter == $unit['business_unit_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($scope_company_id ? '' : $unit['company_name'] . ' — ') . $unit['unit_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="Active" <?php echo $status_filter == 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo $status_filter == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-agro"><i class="bi bi-search"></i> Search</button>
                <a href="divisions.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Divisions Table -->
<div class="card">
    <div class="card-header" style="background-color: #3a618c; color: white;">
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
                        <th>Planting Years</th>
                        <th>Blocks</th>
                        <th>Area (Ha)</th>
                        <th>Plants</th>
                        <th>Area Status</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($divisions)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No divisions found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($divisions as $division): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($division['division_code']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($division['division_name']); ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($division['division_type']); ?></small>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($division['company_code']); ?></small><br>
                                    <?php echo htmlspecialchars($division['unit_name']); ?>
                                </td>
                                <td><span class="badge bg-secondary"><?php echo $division['total_planting_years']; ?></span></td>
                                <td><span class="badge bg-secondary"><?php echo format_number($division['total_blocks'], 0); ?></span></td>
                                <td class="text-end"><?php echo format_number($division['total_area_ha']); ?></td>
                                <td class="text-end"><?php echo format_number($division['total_plants'], 0); ?></td>
                                <td style="min-width:220px;">
                                    <?php
                                    $area_statuses = [
                                        'TM'         => ['area' => (float)$division['area_tm'],         'color' => 'success'],
                                        'TBM'        => ['area' => (float)$division['area_tbm'],        'color' => 'warning'],
                                        'HL'         => ['area' => (float)$division['area_hl'],         'color' => 'info'],
                                        'HP'         => ['area' => (float)$division['area_hp'],         'color' => 'primary'],
                                        'HPT'        => ['area' => (float)$division['area_hpt'],        'color' => 'purple'],
                                        'LC'         => ['area' => (float)$division['area_lc'],         'color' => 'secondary'],
                                        'Replanting' => ['area' => (float)$division['area_replanting'], 'color' => 'danger'],
                                        'Other'      => ['area' => (float)$division['area_other'],      'color' => 'dark'],
                                    ];
                                    ?>
                                    <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($area_statuses as $label => $s):
                                        if ($s['area'] <= 0) continue;
                                        $style = $s['color'] === 'purple' ? 'background:#6f42c1;color:#fff;' : '';
                                        $cls   = $s['color'] === 'purple' ? 'badge' : 'badge bg-' . $s['color'];
                                    ?>
                                        <span class="<?php echo $cls; ?>" <?php echo $style ? 'style="'.$style.'"' : ''; ?>
                                              title="<?php echo $label; ?>: <?php echo format_number($s['area']); ?> Ha">
                                            <?php echo $label; ?> <?php echo format_number($s['area'], 0); ?>
                                        </span>
                                    <?php endforeach; ?>
                                    </div>
                                </td>
                                <td><?php echo get_status_badge($division['status']); ?></td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $division['division_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="planting_years.php?division_id=<?php echo $division['division_id']; ?>" class="btn btn-sm btn-info" title="View Planting Years">
                                        <i class="bi bi-calendar-event"></i>
                                    </a>
                                    <form method="POST" action="divisions.php" style="display:inline;" onsubmit="return confirmDelete('Delete this division and all related data?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="division_id" value="<?php echo $division['division_id']; ?>">
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
            <form method="POST" action="divisions.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_division ? 'Edit Division' : 'Add New Division'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_division ? 'edit' : 'add'; ?>">
                    <?php if ($edit_division): ?>
                        <input type="hidden" name="division_id" value="<?php echo $edit_division['division_id']; ?>">
                    <?php endif; ?>
                    
                    <!-- Company selector (admin) or locked display (company-scoped user) -->
                    <div class="mb-3">
                        <label class="form-label">Company</label>
                        <?php if ($company_locked): ?>
                            <?php
                            $locked_company_name = '';
                            foreach ($companies as $c) {
                                if ($c['company_id'] == $scope_company_id) { $locked_company_name = $c['company_name']; break; }
                            }
                            ?>
                            <input type="hidden" name="modal_company_id" value="<?php echo $scope_company_id; ?>">
                            <input type="text" class="form-control bg-light"
                                   value="<?php echo htmlspecialchars($locked_company_name); ?>" disabled>
                            <small class="text-muted"><i class="bi bi-lock-fill"></i> Fixed to your account</small>
                        <?php else: ?>
                            <select class="form-select" id="modal_company_id" name="modal_company_id">
                                <option value="">— All Companies —</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?php echo $c['company_id']; ?>"
                                        <?php if ($edit_division): ?>
                                            data-selected-bu="<?php echo $edit_division['business_unit_id']; ?>"
                                        <?php endif; ?>>
                                        <?php echo htmlspecialchars($c['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Business Unit <span class="text-danger">*</span></label>
                        <select class="form-select" id="modal_bu_id" name="business_unit_id" required>
                            <option value="">Select Business Unit</option>
                            <?php foreach ($business_units as $unit): ?>
                                <option value="<?php echo $unit['business_unit_id']; ?>"
                                        data-company-id="<?php echo $unit['company_id']; ?>"
                                    <?php echo ($edit_division && $edit_division['business_unit_id'] == $unit['business_unit_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($company_locked ? $unit['unit_name'] : $unit['company_name'] . ' - ' . $unit['unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Parent Division</label>
                        <select class="form-select" name="parent_division_id" id="parent_division_id">
                            <option value="">None (Top Level)</option>
                            <?php
                            // Fetch all divisions for parent selection
                            $parent_divisions_stmt = $db->query("
                                SELECT d.division_id, d.division_code, d.division_name, d.division_type,
                                       bu.unit_name, c.company_name
                                FROM divisions d
                                INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
                                INNER JOIN companies c ON bu.company_id = c.company_id
                                ORDER BY c.company_name, bu.unit_name, d.division_name
                            ");
                            $parent_divisions = $parent_divisions_stmt->fetchAll();
                            foreach ($parent_divisions as $pd):
                                // Don't show self as parent option when editing
                                if ($edit_division && $pd['division_id'] == $edit_division['division_id']) continue;
                            ?>
                                <option value="<?php echo $pd['division_id']; ?>"
                                    <?php echo ($edit_division && $edit_division['parent_division_id'] == $pd['division_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pd['company_name'] . ' - ' . $pd['unit_name'] . ' - ' . $pd['division_name'] . ' (' . $pd['division_type'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Optional: Select parent division (e.g., RPH under BKPH)</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Division Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="division_code" required 
                                   value="<?php echo $edit_division ? htmlspecialchars($edit_division['division_code']) : ''; ?>"
                                   placeholder="e.g., AFD-A">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Division Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="division_type" required>
                                <optgroup label="Plantation">
                                    <option value="Afdeling" <?php echo ($edit_division && $edit_division['division_type'] == 'Afdeling') ? 'selected' : ''; ?>>Afdeling</option>
                                </optgroup>
                                <optgroup label="Forestry">
                                    <option value="BKPH" <?php echo ($edit_division && $edit_division['division_type'] == 'BKPH') ? 'selected' : ''; ?>>BKPH (Bagian KPH)</option>
                                    <option value="RPH" <?php echo ($edit_division && $edit_division['division_type'] == 'RPH') ? 'selected' : ''; ?>>RPH (Resort Pengelolaan Hutan)</option>
                                </optgroup>
                                <optgroup label="Other">
                                    <option value="Other" <?php echo ($edit_division && $edit_division['division_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Division Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="division_name" required
                               value="<?php echo $edit_division ? htmlspecialchars($edit_division['division_name']) : ''; ?>"
                               placeholder="e.g., Afdeling A">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Assistant/Supervisor</label>
                        <select class="form-select select2-assistant" name="assistant_name" id="assistant_select">
                            <option value="">Select Worker</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?php echo htmlspecialchars($worker['full_name']); ?>"
                                    <?php echo ($edit_division && $edit_division['assistant_name'] == $worker['full_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($worker['employee_code'] . ' - ' . $worker['full_name'] . ' (' . $worker['position'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Type to search from active workers</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="Active" <?php echo ($edit_division && $edit_division['status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo ($edit_division && $edit_division['status'] == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo $edit_division ? htmlspecialchars($edit_division['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_division ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_division): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

<script>
// Confirm delete
function confirmDelete(message) {
    return confirm(message);
}

// -------------------------------------------------------
// Company → Business Unit cascade in Add/Edit modal
// -------------------------------------------------------
(function () {
    const companyLocked = <?php echo $company_locked ? 'true' : 'false'; ?>;
    const buLocked      = <?php echo $bu_locked ? 'true' : 'false'; ?>;
    const coSel = document.getElementById('modal_company_id');
    const buSel = document.getElementById('modal_bu_id');

    function filterBUs(companyId) {
        if (!buSel) return;
        Array.from(buSel.options).forEach(function(opt) {
            if (!opt.value) return;
            opt.style.display = (!companyId || opt.dataset.companyId == companyId) ? '' : 'none';
        });
        // Clear BU selection if now hidden
        const cur = buSel.value;
        if (cur && buSel.querySelector('option[value="' + cur + '"]')?.style.display === 'none') {
            buSel.value = '';
        }
    }

    if (!companyLocked && coSel) {
        coSel.addEventListener('change', function() {
            filterBUs(this.value);
        });

        // When modal opens for edit: pre-select the company that owns the current BU
        var addModal = document.getElementById('addModal');
        if (addModal) {
            addModal.addEventListener('show.bs.modal', function() {
                var selectedBUOpt = buSel ? buSel.options[buSel.selectedIndex] : null;
                if (selectedBUOpt && selectedBUOpt.value) {
                    var ownerCompany = selectedBUOpt.dataset.companyId || '';
                    coSel.value = ownerCompany;
                    filterBUs(ownerCompany);
                    // Re-apply the BU value after filter (options may have been hidden/shown)
                    buSel.value = selectedBUOpt.value;
                }
            });
        }

        // Apply on initial page load (for edit mode where modal auto-opens)
        var selectedBUOpt = buSel ? buSel.options[buSel.selectedIndex] : null;
        if (selectedBUOpt && selectedBUOpt.value) {
            var ownerCompany = selectedBUOpt.dataset.companyId || '';
            coSel.value = ownerCompany;
            filterBUs(ownerCompany);
            buSel.value = selectedBUOpt.value;
        }
    }
})();
</script>

<?php require_once 'includes/footer.php'; ?>

<!-- Select2 JS (loaded after jQuery from footer) -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
// Initialize Select2 for searchable dropdowns
$(document).ready(function() {
    $('.select2-assistant').select2({
        theme: 'bootstrap-5',
        placeholder: 'Select Assistant/Supervisor',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#addModal')
    });
});
</script>