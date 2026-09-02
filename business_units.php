<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');

    if ($action == 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO business_units (company_id, parent_unit_id, unit_code, unit_name, unit_type, location, province, district,
                                          total_area, capacity, manager_name, manager_phone, manager_email,
                                          established_date, latitude, longitude, status, notes, created_by)
                VALUES (:company_id, :parent_unit_id, :unit_code, :unit_name, :unit_type, :location, :province, :district,
                        :total_area, :capacity, :manager_name, :manager_phone, :manager_email,
                        :established_date, :latitude, :longitude, :status, :notes, 'admin')
            ");
            $stmt->execute([
                ':company_id'       => post('company_id'),
                ':parent_unit_id'   => post('parent_unit_id') ?: null,
                ':unit_code'        => post('unit_code'),
                ':unit_name'        => post('unit_name'),
                ':unit_type'        => post('unit_type'),
                ':location'         => post('location'),
                ':province'         => post('province'),
                ':district'         => post('district'),
                ':total_area'       => post('total_area', 0),
                ':capacity'         => post('capacity', 0),
                ':manager_name'     => post('manager_name'),
                ':manager_phone'    => post('manager_phone'),
                ':manager_email'    => post('manager_email'),
                ':established_date' => post('established_date'),
                ':latitude'         => post('latitude'),
                ':longitude'        => post('longitude'),
                ':status'           => post('status', 'Active'),
                ':notes'            => post('notes')
            ]);
            set_message('success', __('bu_msg_added'));
            redirect('business_units.php');
        } catch (PDOException $e) {
            set_message('error', __('bu_err_add') . $e->getMessage());
        }
    }

    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE business_units
                SET company_id = :company_id, parent_unit_id = :parent_unit_id, unit_code = :unit_code, unit_name = :unit_name,
                    unit_type = :unit_type, location = :location, province = :province, district = :district,
                    total_area = :total_area, capacity = :capacity, manager_name = :manager_name,
                    manager_phone = :manager_phone, manager_email = :manager_email,
                    established_date = :established_date, latitude = :latitude, longitude = :longitude,
                    status = :status, notes = :notes, updated_by = 'admin'
                WHERE business_unit_id = :id
            ");
            $stmt->execute([
                ':id'               => post('business_unit_id'),
                ':company_id'       => post('company_id'),
                ':parent_unit_id'   => post('parent_unit_id') ?: null,
                ':unit_code'        => post('unit_code'),
                ':unit_name'        => post('unit_name'),
                ':unit_type'        => post('unit_type'),
                ':location'         => post('location'),
                ':province'         => post('province'),
                ':district'         => post('district'),
                ':total_area'       => post('total_area', 0),
                ':capacity'         => post('capacity', 0),
                ':manager_name'     => post('manager_name'),
                ':manager_phone'    => post('manager_phone'),
                ':manager_email'    => post('manager_email'),
                ':established_date' => post('established_date'),
                ':latitude'         => post('latitude'),
                ':longitude'        => post('longitude'),
                ':status'           => post('status'),
                ':notes'            => post('notes')
            ]);
            set_message('success', __('bu_msg_updated'));
            redirect('business_units.php');
        } catch (PDOException $e) {
            set_message('error', __('bu_err_update') . $e->getMessage());
        }
    }

    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM business_units WHERE business_unit_id = :id");
            $stmt->execute([':id' => post('business_unit_id')]);
            set_message('success', __('bu_msg_deleted'));
            redirect('business_units.php');
        } catch (PDOException $e) {
            set_message('error', __('bu_err_delete') . $e->getMessage());
        }
    }
}

// Get business unit for editing (before header)
$edit_unit = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM business_units WHERE business_unit_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_unit = $stmt->fetch();
}

// Include header after form processing
$page_title = __('bu_page_title');
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

// Scope: lock to user's company if set in session
$scope_company_id = $_SESSION['company_id'] ?? null;

// Fetch companies for dropdown — scoped if user has a company assigned
$companies_stmt = $db->prepare("SELECT company_id, company_code, company_name FROM companies WHERE status = 'Active'"
    . ($scope_company_id !== null ? " AND company_id = ?" : "") . " ORDER BY company_name");
$companies_stmt->execute($scope_company_id !== null ? [$scope_company_id] : []);
$companies = $companies_stmt->fetchAll();

// Fetch workers for manager dropdown
$workers_stmt = $db->query("
    SELECT id, employee_code, full_name, position
    FROM workers
    WHERE status = 'active'
    ORDER BY full_name
");
$workers = $workers_stmt->fetchAll();

// Fetch business units with statistics
$search           = get('search', '');
$company_filter   = $scope_company_id !== null ? (string)$scope_company_id : get('company_id', '');
$unit_type_filter = get('unit_type', '');
$status_filter    = get('status', '');

$sql = "SELECT bu.*, c.company_name, c.company_code,
        COALESCE(bu.total_area_ha, 0) + COALESCE(bu.forestry_area_ha, 0) as combined_total_area_ha,
        COALESCE(bu.total_plants, 0) as total_plants,
        COALESCE(bu.forestry_area_ha, 0) as forestry_area_ha,
        COALESCE(bu.total_volume_m3, 0) as total_volume_m3,
        COALESCE(bu.total_carbon_stock_ton, 0) as total_carbon_stock_ton,
        COALESCE(bu.forestry_blocks, 0) as forestry_blocks,
        (SELECT COUNT(*) FROM divisions d WHERE d.business_unit_id = bu.business_unit_id AND d.parent_division_id IS NULL) as total_divisions,
        (SELECT COUNT(*) FROM blocks b
         INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id) as total_blocks,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id AND b.status = 'TM')         as area_tm,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id AND b.status = 'TBM')        as area_tbm,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id AND b.status = 'HL')         as area_hl,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id AND b.status = 'HP')         as area_hp,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id AND b.status = 'HPT')        as area_hpt,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id AND b.status = 'LC')         as area_lc,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id AND b.status = 'Replanting') as area_replanting,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id AND b.status NOT IN ('TM','TBM','HL','HP','HPT','LC','Replanting')) as area_other
        FROM business_units bu
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE 1=1";

if ($search)          { $sql .= " AND (bu.unit_code LIKE :search1 OR bu.unit_name LIKE :search2 OR bu.location LIKE :search3)"; }
if ($company_filter)  { $sql .= " AND bu.company_id = :company_id"; }
if ($unit_type_filter){ $sql .= " AND bu.unit_type = :unit_type"; }
if ($status_filter)   { $sql .= " AND bu.status = :status"; }
$sql .= " ORDER BY c.company_name, bu.unit_code";

$stmt = $db->prepare($sql);
if ($search) {
    $stmt->bindValue(':search1', "%$search%");
    $stmt->bindValue(':search2', "%$search%");
    $stmt->bindValue(':search3', "%$search%");
}
if ($company_filter)  { $stmt->bindValue(':company_id', $company_filter); }
if ($unit_type_filter){ $stmt->bindValue(':unit_type',  $unit_type_filter); }
if ($status_filter)   { $stmt->bindValue(':status',     $status_filter); }
$stmt->execute();
$business_units = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color: #3a618c;"><i class="bi bi-diagram-3"></i> <?php echo __('bu_title'); ?></h1>
            <p class="text-muted"><?php echo __('bu_subtitle'); ?></p>
        </div>
        <div class="col-auto">
            <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> <?php echo __('bu_back_btn'); ?>
            </a>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-house"></i> <?php echo __('bu_dashboard_btn'); ?>
            </a>
            <button type="button" class="btn btn-agro" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> <?php echo __('bu_add_btn'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<?php
$top_level_units = array_filter($business_units, function($unit) { return empty($unit['parent_unit_id']); });
$total_units     = count($business_units);
$total_divisions = array_sum(array_column($top_level_units, 'total_divisions'));
$total_area      = array_sum(array_map(function($unit) {
    return ($unit['total_area_ha'] ?? 0) + ($unit['forestry_area_ha'] ?? 0);
}, $top_level_units));
$total_plants    = array_sum(array_column($top_level_units, 'total_plants'));
?>
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card stat-card" style="background-color:#fff;border-left:4px solid #3a618c;">
            <div class="card-body py-2">
                <h4 class="mb-1" style="color:#3a618c;"><?php echo $total_units; ?></h4>
                <p class="mb-0 small text-muted"><i class="bi bi-diagram-3"></i> <?php echo __('bu_stat_units'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <a href="divisions.php" class="text-decoration-none">
            <div class="card stat-card" style="background-color:#fff;border-left:4px solid #3a618c;cursor:pointer;">
                <div class="card-body py-2">
                    <h4 class="mb-1" style="color:#3a618c;"><?php echo $total_divisions; ?></h4>
                    <p class="mb-0 small text-muted"><i class="bi bi-grid-3x3"></i> <?php echo __('bu_stat_divisions'); ?></p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" style="background-color:#fff;border-left:4px solid #3a618c;">
            <div class="card-body py-2">
                <h4 class="mb-1" style="color:#3a618c;"><?php echo format_number($total_area); ?></h4>
                <p class="mb-0 small text-muted"><i class="bi bi-map"></i> <?php echo __('bu_stat_area'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" style="background-color:#fff;border-left:4px solid #3a618c;">
            <div class="card-body py-2">
                <h4 class="mb-1" style="color:#3a618c;"><?php echo format_number($total_plants, 0); ?></h4>
                <p class="mb-0 small text-muted"><i class="bi bi-tree"></i> <?php echo __('bu_stat_plants'); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search"
                       placeholder="<?php echo __('bu_search_placeholder'); ?>"
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="company_id">
                    <option value=""><?php echo __('bu_all_companies'); ?></option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?php echo $company['company_id']; ?>"
                            <?php echo $company_filter == $company['company_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($company['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="unit_type">
                    <option value=""><?php echo __('bu_all_types'); ?></option>
                    <optgroup label="<?php echo __('bu_optgroup_plantation'); ?>">
                        <option value="Estate"         <?php echo $unit_type_filter=='Estate'         ?'selected':''; ?>><?php echo __('bu_type_estate'); ?></option>
                        <option value="Mill"           <?php echo $unit_type_filter=='Mill'           ?'selected':''; ?>><?php echo __('bu_type_mill'); ?></option>
                        <option value="Nursery"        <?php echo $unit_type_filter=='Nursery'        ?'selected':''; ?>><?php echo __('bu_type_nursery'); ?></option>
                    </optgroup>
                    <optgroup label="<?php echo __('bu_optgroup_forestry'); ?>">
                        <option value="Divisi Regional" <?php echo $unit_type_filter=='Divisi Regional'?'selected':''; ?>><?php echo __('bu_type_div_regional'); ?></option>
                        <option value="KPH"            <?php echo $unit_type_filter=='KPH'            ?'selected':''; ?>><?php echo __('bu_type_kph'); ?></option>
                    </optgroup>
                    <optgroup label="<?php echo __('bu_optgroup_other'); ?>">
                        <option value="Workshop"       <?php echo $unit_type_filter=='Workshop'       ?'selected':''; ?>><?php echo __('bu_type_workshop'); ?></option>
                        <option value="Office"         <?php echo $unit_type_filter=='Office'         ?'selected':''; ?>><?php echo __('bu_type_office'); ?></option>
                        <option value="Other"          <?php echo $unit_type_filter=='Other'          ?'selected':''; ?>><?php echo __('bu_type_other'); ?></option>
                    </optgroup>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value=""><?php echo __('bu_all_status'); ?></option>
                    <option value="Active"             <?php echo $status_filter=='Active'            ?'selected':''; ?>><?php echo __('bu_status_active'); ?></option>
                    <option value="Inactive"           <?php echo $status_filter=='Inactive'          ?'selected':''; ?>><?php echo __('bu_status_inactive'); ?></option>
                    <option value="Under Construction" <?php echo $status_filter=='Under Construction'?'selected':''; ?>><?php echo __('bu_status_construction'); ?></option>
                    <option value="Maintenance"        <?php echo $status_filter=='Maintenance'       ?'selected':''; ?>><?php echo __('bu_status_maintenance'); ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-agro"><i class="bi bi-search"></i> <?php echo __('bu_search_btn'); ?></button>
                <a href="business_units.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Business Units Table -->
<div class="card">
    <div class="card-header" style="background-color:#3a618c;color:white;">
        <i class="bi bi-list-ul"></i> <?php echo __('bu_list_header'); ?> (<?php echo count($business_units); ?> records)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th><?php echo __('bu_col_code'); ?></th>
                        <th><?php echo __('bu_col_name'); ?></th>
                        <th><?php echo __('bu_col_company'); ?></th>
                        <th><?php echo __('bu_col_type'); ?></th>
                        <th><?php echo __('bu_col_divisions'); ?></th>
                        <th><?php echo __('bu_col_blocks'); ?></th>
                        <th><?php echo __('bu_col_area'); ?></th>
                        <th><?php echo __('bu_col_plants'); ?></th>
                        <th><?php echo __('bu_col_area_status'); ?></th>
                        <th><?php echo __('bu_col_status'); ?></th>
                        <th><?php echo __('bu_col_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($business_units)): ?>
                        <tr><td colspan="11" class="text-center text-muted"><?php echo __('bu_no_data'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($business_units as $unit): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($unit['unit_code']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($unit['unit_name']); ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($unit['province']); ?></small>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($unit['company_code']); ?></small><br>
                                    <?php echo htmlspecialchars($unit['company_name']); ?>
                                </td>
                                <td><span class="badge bg-info"><?php echo $unit['unit_type']; ?></span></td>
                                <td><span class="badge bg-secondary"><?php echo $unit['total_divisions']; ?></span></td>
                                <td><span class="badge bg-secondary"><?php echo format_number($unit['total_blocks'], 0); ?></span></td>
                                <td class="text-end"><?php echo format_number(($unit['total_area_ha'] ?? 0) + ($unit['forestry_area_ha'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo format_number($unit['total_plants'], 0); ?></td>
                                <td style="min-width:220px;">
                                    <?php
                                    $area_statuses = [
                                        'TM'         => ['area' => (float)$unit['area_tm'],         'color' => 'success'],
                                        'TBM'        => ['area' => (float)$unit['area_tbm'],        'color' => 'warning'],
                                        'HL'         => ['area' => (float)$unit['area_hl'],         'color' => 'info'],
                                        'HP'         => ['area' => (float)$unit['area_hp'],         'color' => 'primary'],
                                        'HPT'        => ['area' => (float)$unit['area_hpt'],        'color' => 'purple'],
                                        'LC'         => ['area' => (float)$unit['area_lc'],         'color' => 'secondary'],
                                        'Replanting' => ['area' => (float)$unit['area_replanting'], 'color' => 'danger'],
                                        'Other'      => ['area' => (float)$unit['area_other'],      'color' => 'dark'],
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
                                <td><?php echo get_status_badge($unit['status']); ?></td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $unit['business_unit_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="divisions.php?business_unit_id=<?php echo $unit['business_unit_id']; ?>" class="btn btn-sm btn-info" title="View Divisions">
                                        <i class="bi bi-grid-3x3"></i>
                                    </a>
                                    <form method="POST" action="business_units.php" style="display:inline;"
                                          onsubmit="return confirmDelete('<?php echo addslashes(__('bu_confirm_delete')); ?>');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="business_unit_id" value="<?php echo $unit['business_unit_id']; ?>">
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
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="business_units.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_unit ? __('bu_modal_edit_title') : __('bu_modal_add_title'); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_unit ? 'edit' : 'add'; ?>">
                    <?php if ($edit_unit): ?>
                        <input type="hidden" name="business_unit_id" value="<?php echo $edit_unit['business_unit_id']; ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo __('bu_field_company'); ?> <span class="text-danger">*</span></label>
                            <select class="form-select" name="company_id" required>
                                <option value=""><?php echo __('bu_field_company_select'); ?></option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo $company['company_id']; ?>"
                                        <?php echo ($edit_unit && $edit_unit['company_id'] == $company['company_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($company['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo __('bu_field_parent'); ?></label>
                            <select class="form-select select2-parent-unit" name="parent_unit_id" id="parent_unit_id">
                                <option value=""><?php echo __('bu_field_parent_none'); ?></option>
                                <?php
                                $parent_units_stmt = $db->query("
                                    SELECT bu.business_unit_id, bu.unit_code, bu.unit_name, bu.unit_type, c.company_name
                                    FROM business_units bu
                                    INNER JOIN companies c ON bu.company_id = c.company_id
                                    ORDER BY c.company_name, bu.unit_code
                                ");
                                $parent_units = $parent_units_stmt->fetchAll();
                                foreach ($parent_units as $pu):
                                    if ($edit_unit && $pu['business_unit_id'] == $edit_unit['business_unit_id']) continue;
                                ?>
                                    <option value="<?php echo $pu['business_unit_id']; ?>"
                                        <?php echo ($edit_unit && $edit_unit['parent_unit_id'] == $pu['business_unit_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($pu['company_name'] . ' - ' . $pu['unit_name'] . ' (' . $pu['unit_type'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted"><?php echo __('bu_field_parent_hint'); ?></small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label"><?php echo __('bu_field_code'); ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="unit_code" required
                                   value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['unit_code']) : ''; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label"><?php echo __('bu_field_type'); ?> <span class="text-danger">*</span></label>
                            <select class="form-select" name="unit_type" required id="unit_type">
                                <optgroup label="<?php echo __('bu_optgroup_plantation'); ?>">
                                    <option value="Estate"         <?php echo ($edit_unit && $edit_unit['unit_type']=='Estate')         ?'selected':''; ?>><?php echo __('bu_type_estate'); ?></option>
                                    <option value="Mill"           <?php echo ($edit_unit && $edit_unit['unit_type']=='Mill')           ?'selected':''; ?>><?php echo __('bu_type_mill'); ?></option>
                                    <option value="Nursery"        <?php echo ($edit_unit && $edit_unit['unit_type']=='Nursery')        ?'selected':''; ?>><?php echo __('bu_type_nursery'); ?></option>
                                </optgroup>
                                <optgroup label="<?php echo __('bu_optgroup_forestry'); ?>">
                                    <option value="Divisi Regional" <?php echo ($edit_unit && $edit_unit['unit_type']=='Divisi Regional')?'selected':''; ?>><?php echo __('bu_type_div_regional'); ?></option>
                                    <option value="KPH"            <?php echo ($edit_unit && $edit_unit['unit_type']=='KPH')            ?'selected':''; ?>><?php echo __('bu_type_kph'); ?></option>
                                </optgroup>
                                <optgroup label="<?php echo __('bu_optgroup_other'); ?>">
                                    <option value="Workshop"       <?php echo ($edit_unit && $edit_unit['unit_type']=='Workshop')       ?'selected':''; ?>><?php echo __('bu_type_workshop'); ?></option>
                                    <option value="Office"         <?php echo ($edit_unit && $edit_unit['unit_type']=='Office')         ?'selected':''; ?>><?php echo __('bu_type_office'); ?></option>
                                    <option value="Other"          <?php echo ($edit_unit && $edit_unit['unit_type']=='Other')          ?'selected':''; ?>><?php echo __('bu_type_other'); ?></option>
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php echo __('bu_field_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="unit_name" required
                               value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['unit_name']) : ''; ?>">
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('bu_field_location'); ?></label>
                            <input type="text" class="form-control" name="location"
                                   value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['location']) : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('bu_field_province'); ?></label>
                            <input type="text" class="form-control" name="province"
                                   value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['province']) : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('bu_field_district'); ?></label>
                            <input type="text" class="form-control" name="district"
                                   value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['district']) : ''; ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12 mb-3" id="capacity_field">
                            <label class="form-label"><?php echo __('bu_field_capacity'); ?></label>
                            <input type="number" step="0.01" class="form-control" name="capacity"
                                   value="<?php echo $edit_unit ? $edit_unit['capacity'] : '0.00'; ?>">
                            <small class="text-muted"><?php echo __('bu_field_capacity_hint'); ?></small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php echo __('bu_field_manager'); ?></label>
                        <select class="form-select select2-manager" name="manager_name" id="manager_select">
                            <option value=""><?php echo __('bu_field_manager_select'); ?></option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?php echo htmlspecialchars($worker['full_name']); ?>"
                                    <?php echo ($edit_unit && $edit_unit['manager_name'] == $worker['full_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($worker['employee_code'] . ' - ' . $worker['full_name'] . ' (' . $worker['position'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted"><?php echo __('bu_field_manager_hint'); ?></small>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('bu_field_est_date'); ?></label>
                            <input type="date" class="form-control" name="established_date"
                                   value="<?php echo $edit_unit ? $edit_unit['established_date'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('bu_field_latitude'); ?></label>
                            <input type="number" step="0.00000001" class="form-control" name="latitude"
                                   value="<?php echo $edit_unit ? $edit_unit['latitude'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('bu_field_longitude'); ?></label>
                            <input type="number" step="0.00000001" class="form-control" name="longitude"
                                   value="<?php echo $edit_unit ? $edit_unit['longitude'] : ''; ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php echo __('bu_field_status'); ?></label>
                        <select class="form-select" name="status">
                            <option value="Active"             <?php echo ($edit_unit && $edit_unit['status']=='Active')            ?'selected':''; ?>><?php echo __('bu_status_active'); ?></option>
                            <option value="Inactive"           <?php echo ($edit_unit && $edit_unit['status']=='Inactive')          ?'selected':''; ?>><?php echo __('bu_status_inactive'); ?></option>
                            <option value="Under Construction" <?php echo ($edit_unit && $edit_unit['status']=='Under Construction')?'selected':''; ?>><?php echo __('bu_status_construction'); ?></option>
                            <option value="Maintenance"        <?php echo ($edit_unit && $edit_unit['status']=='Maintenance')       ?'selected':''; ?>><?php echo __('bu_status_maintenance'); ?></option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php echo __('bu_field_notes'); ?></label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo $edit_unit ? htmlspecialchars($edit_unit['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('bu_modal_cancel'); ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_unit ? __('bu_modal_update') : __('bu_modal_save'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_unit): ?>
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
// Show/hide capacity field based on unit type
document.getElementById('unit_type').addEventListener('change', function() {
    document.getElementById('capacity_field').style.display = (this.value === 'Mill') ? 'block' : 'none';
});
document.getElementById('unit_type').dispatchEvent(new Event('change'));

function confirmDelete(message) { return confirm(message); }
</script>

<?php require_once 'includes/footer.php'; ?>

<!-- Select2 JS (loaded after jQuery from footer) -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('.select2-manager').select2({
        theme: 'bootstrap-5',
        placeholder: '<?php echo addslashes(__('bu_field_manager_select')); ?>',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#addModal')
    });
    $('.select2-parent-unit').select2({
        theme: 'bootstrap-5',
        placeholder: '<?php echo addslashes(__('bu_field_parent_none')); ?>',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#addModal')
    });
});
</script>
