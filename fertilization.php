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
                INSERT INTO fertilization_records 
                (work_order_id, block_id, application_date, fertilizer_type, fertilizer_grade,
                 quantity_kg, application_method, dosage_per_tree, area_covered, labor_count,
                 labor_hours, cost, weather_condition, performed_by, supervisor, status, notes, created_by)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('work_order_id') ?: null,
                post('block_id'),
                post('application_date'),
                post('fertilizer_type'),
                post('fertilizer_grade') ?: null,
                post('quantity_kg'),
                post('application_method'),
                post('dosage_per_tree') ?: null,
                post('area_covered') ?: null,
                post('labor_count') ?: null,
                post('labor_hours') ?: null,
                post('cost') ?: null,
                post('weather_condition') ?: null,
                post('performed_by') ?: null,
                post('supervisor') ?: null,
                post('status') ?: 'Planned',
                post('notes') ?: null,
                'admin'
            ]);
            
            set_message('success', 'Fertilization record added successfully!');
            redirect('fertilization.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding fertilization: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE fertilization_records 
                SET work_order_id = ?, block_id = ?, application_date = ?, fertilizer_type = ?,
                    fertilizer_grade = ?, quantity_kg = ?, application_method = ?, dosage_per_tree = ?,
                    area_covered = ?, labor_count = ?, labor_hours = ?, cost = ?,
                    weather_condition = ?, performed_by = ?, supervisor = ?, status = ?, notes = ?
                WHERE fertilization_id = ?
            ");
            
            $stmt->execute([
                post('work_order_id') ?: null,
                post('block_id'),
                post('application_date'),
                post('fertilizer_type'),
                post('fertilizer_grade') ?: null,
                post('quantity_kg'),
                post('application_method'),
                post('dosage_per_tree') ?: null,
                post('area_covered') ?: null,
                post('labor_count') ?: null,
                post('labor_hours') ?: null,
                post('cost') ?: null,
                post('weather_condition') ?: null,
                post('performed_by') ?: null,
                post('supervisor') ?: null,
                post('status'),
                post('notes') ?: null,
                post('fertilization_id')
            ]);
            
            set_message('success', 'Fertilization record updated successfully!');
            redirect('fertilization.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating fertilization: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM fertilization_records WHERE fertilization_id = ?");
            $stmt->execute([post('fertilization_id')]);
            
            set_message('success', 'Fertilization record deleted successfully!');
            redirect('fertilization.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting fertilization: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_record = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM fertilization_records WHERE fertilization_id = ?");
    $stmt->execute([get('id')]);
    $edit_record = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Fertilization Management";
require_once 'includes/header.php';
?>

<style>
    /* Custom teal theme for fertilization page */
    .card-header {
        background-color: #006359 !important;
        color: white !important;
    }
    
    .page-header h1 {
        color: #006359 !important;
    }
    
    .page-header {
        border-bottom-color: #006359 !important;
    }
    
    .stat-card {
        border-left-color: #006359 !important;
    }
    
    .stat-card h3 {
        color: #006359 !important;
    }
    
    .btn-primary {
        background-color: #006359 !important;
        border-color: #006359 !important;
    }
    
    .btn-primary:hover {
        background-color: #004d45 !important;
        border-color: #004d45 !important;
    }
    
    .text-primary {
        color: #006359 !important;
    }
</style>

<?php
// Fetch blocks for dropdown
$blocks_stmt = $db->query("
    SELECT b.block_id, b.block_code, b.block_name, b.total_plants,
           py.year, d.division_name, bu.unit_name, c.company_name
    FROM blocks b
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE b.status IN ('TBM', 'TM', 'TR')
    ORDER BY c.company_name, bu.unit_name, d.division_name, b.block_name
");
$blocks = $blocks_stmt->fetchAll();

// Fetch work orders for dropdown
$work_orders_stmt = $db->query("
    SELECT wo.work_order_id, wo.work_order_number, b.block_name
    FROM work_orders wo
    INNER JOIN blocks b ON wo.block_id = b.block_id
    WHERE wo.status IN ('Planned', 'Assigned', 'In Progress') AND wo.work_type = 'Fertilization'
    ORDER BY wo.work_order_number DESC
");
$work_orders = $work_orders_stmt->fetchAll();

// Fetch fertilization records with filters
$search = get('search', '');
$fertilizer_filter = get('fertilizer_type', '');
$status_filter = get('status', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');

$sql = "SELECT fr.*,
        b.block_code, b.block_name, b.total_plants,
        py.year as planting_year,
        d.division_name,
        bu.unit_name as estate_name,
        c.company_name,
        wo.work_order_number
        FROM fertilization_records fr
        INNER JOIN blocks b ON fr.block_id = b.block_id
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        LEFT JOIN work_orders wo ON fr.work_order_id = wo.work_order_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (b.block_name LIKE ? OR fr.fertilizer_type LIKE ? OR wo.work_order_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($fertilizer_filter) {
    $sql .= " AND fr.fertilizer_type LIKE ?";
    $params[] = "%$fertilizer_filter%";
}
if ($status_filter) {
    $sql .= " AND fr.status = ?";
    $params[] = $status_filter;
}
if ($date_from) {
    $sql .= " AND fr.application_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND fr.application_date <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY fr.application_date DESC, fr.fertilization_id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// -------------------------------------------------------
// Pivot query — independent of the main table filter
// -------------------------------------------------------
$pivot_year  = get('pivot_year',  date('Y'));
$pivot_month = get('pivot_month', '');  // '' = all months

$pivot_sql = "
    SELECT fr.block_id,
           b.block_code, b.block_name,
           d.division_name,
           bu.unit_name  AS estate_name,
           fr.fertilizer_type,
           SUM(fr.quantity_kg) AS total_kg
    FROM fertilization_records fr
    INNER JOIN blocks b         ON fr.block_id          = b.block_id
    INNER JOIN planting_years py ON b.planting_year_id  = py.planting_year_id
    INNER JOIN divisions d       ON py.division_id       = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id  = bu.business_unit_id
    INNER JOIN companies c       ON bu.company_id        = c.company_id
    WHERE YEAR(fr.application_date) = ?";
$pivot_params = [(int)$pivot_year];

if ($pivot_month !== '') {
    $pivot_sql .= " AND MONTH(fr.application_date) = ?";
    $pivot_params[] = (int)$pivot_month;
}

$pivot_sql .= "
    GROUP BY fr.block_id, b.block_code, b.block_name,
             d.division_name, bu.unit_name, fr.fertilizer_type
    ORDER BY bu.unit_name, d.division_name, b.block_name, fr.fertilizer_type";

$pivot_stmt = $db->prepare($pivot_sql);
$pivot_stmt->execute($pivot_params);
$pivot_rows = $pivot_stmt->fetchAll();

// Get available years for the pivot year selector
$years_stmt = $db->query("SELECT DISTINCT YEAR(application_date) AS yr FROM fertilization_records ORDER BY yr DESC");
$pivot_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($pivot_years)) {
    $pivot_years = [date('Y')];
}

// Calculate summary statistics
$total_records = count($records);
$total_quantity = array_sum(array_column($records, 'quantity_kg'));
$total_area = array_sum(array_column($records, 'area_covered'));
$total_cost = array_sum(array_column($records, 'cost'));
$completed_count = count(array_filter($records, function($r) { return $r['status'] == 'Completed'; }));

// Application methods and statuses
$application_methods = ['Broadcasting', 'Pocket', 'Ring', 'Foliar', 'Other'];
$statuses = ['Planned', 'In Progress', 'Completed'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-droplet-fill"></i> Fertilization Management</h1>
            <p class="text-muted">Track fertilizer application activities</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Record Fertilization
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_records; ?></h3>
                <p><i class="bi bi-list-check"></i> Total Applications</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_quantity, 0); ?> Kg</h3>
                <p><i class="bi bi-box-seam"></i> Total Fertilizer</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_area, 1); ?> Ha</h3>
                <p><i class="bi bi-map"></i> Area Covered</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3>Rp <?php echo format_number($total_cost, 0); ?></h3>
                <p><i class="bi bi-cash"></i> Total Cost</p>
            </div>
        </div>
    </div>
</div>

<!-- Fertilizer Type Breakdown -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bar-chart"></i> Fertilizer Usage by Type
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <?php
                    $fertilizer_types = [];
                    foreach ($records as $r) {
                        $type = $r['fertilizer_type'];
                        if (!isset($fertilizer_types[$type])) {
                            $fertilizer_types[$type] = 0;
                        }
                        $fertilizer_types[$type] += $r['quantity_kg'];
                    }
                    foreach ($fertilizer_types as $type => $qty):
                    ?>
                    <div class="col-md-3">
                        <h4 class="text-success"><?php echo format_number($qty, 0); ?> Kg</h4>
                        <small><?php echo htmlspecialchars($type); ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Pivot Table: Fertilization by Block -->
<?php
// Build pivot matrices from $pivot_rows (dedicated query, current year by default)
$pivot_data   = [];   // [block_id] => [fert_type => qty]
$pivot_meta   = [];   // [block_id] => [estate, division, block_name, block_code]
$pivot_ftypes = [];   // unique fertilizer types

foreach ($pivot_rows as $r) {
    $bkey  = $r['block_id'];
    $ftype = $r['fertilizer_type'];
    if (!isset($pivot_data[$bkey])) {
        $pivot_data[$bkey] = [];
        $pivot_meta[$bkey] = [
            'estate'     => $r['estate_name'],
            'division'   => $r['division_name'],
            'block_name' => $r['block_name'],
            'block_code' => $r['block_code'],
        ];
    }
    $pivot_data[$bkey][$ftype] = ($pivot_data[$bkey][$ftype] ?? 0) + (float)$r['total_kg'];
    $pivot_ftypes[$ftype] = true;
}

ksort($pivot_ftypes);
$pivot_ftypes = array_keys($pivot_ftypes);

// Sort rows: estate > division > block_name
$sorted_keys = array_keys($pivot_meta);
usort($sorted_keys, function($a, $b) use ($pivot_meta) {
    $cmp = strcmp($pivot_meta[$a]['estate'],   $pivot_meta[$b]['estate']);
    if ($cmp !== 0) return $cmp;
    $cmp = strcmp($pivot_meta[$a]['division'], $pivot_meta[$b]['division']);
    if ($cmp !== 0) return $cmp;
    return strcmp($pivot_meta[$a]['block_name'], $pivot_meta[$b]['block_name']);
});

// Grand totals per fertilizer type
$grand_totals    = array_fill_keys($pivot_ftypes, 0);
foreach ($pivot_data as $bdata) {
    foreach ($pivot_ftypes as $ft) {
        $grand_totals[$ft] += ($bdata[$ft] ?? 0);
    }
}
$grand_row_total = array_sum($grand_totals);

$month_names = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'];
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-table"></i> Analisa Pemupukan menurut Blok (Pivot Table)</span>
        <!-- Period filter — submits GET, preserves main search params -->
        <form method="GET" class="d-flex gap-2 align-items-center mb-0">
            <?php /* keep main-table filter params intact */ ?>
            <?php foreach (['search','fertilizer_type','status','date_from','date_to'] as $pk): ?>
                <?php if (!empty($$pk)): ?>
                    <input type="hidden" name="<?php echo $pk; ?>" value="<?php echo htmlspecialchars($$pk); ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <select name="pivot_year" class="form-select form-select-sm" style="width:90px;" onchange="this.form.submit()">
                <?php foreach ($pivot_years as $yr): ?>
                    <option value="<?php echo $yr; ?>" <?php echo ($yr == $pivot_year) ? 'selected' : ''; ?>>
                        <?php echo $yr; ?>
                    </option>
                <?php endforeach; ?>
                <?php if (!in_array((int)$pivot_year, array_map('intval', $pivot_years))): ?>
                    <option value="<?php echo (int)$pivot_year; ?>" selected><?php echo (int)$pivot_year; ?></option>
                <?php endif; ?>
            </select>
            <select name="pivot_month" class="form-select form-select-sm" style="width:90px;" onchange="this.form.submit()">
                <option value="">Semua Bulan</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo ($pivot_month == $m) ? 'selected' : ''; ?>>
                        <?php echo $month_names[$m]; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pivot_data)): ?>
            <p class="text-center text-muted py-3">
                Tidak ada data pemupukan untuk tahun <?php echo (int)$pivot_year; ?>
                <?php echo $pivot_month ? ' bulan ' . $month_names[(int)$pivot_month] : ''; ?>
            </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-hover mb-0" id="pivotBlockTable">
                <thead class="table-dark">
                    <tr>
                        <th rowspan="2" class="align-middle">Estate</th>
                        <th rowspan="2" class="align-middle">Divisi</th>
                        <th rowspan="2" class="align-middle">Blok</th>
                        <?php foreach ($pivot_ftypes as $ft): ?>
                            <th class="text-center text-nowrap"><?php echo htmlspecialchars($ft); ?></th>
                        <?php endforeach; ?>
                        <th class="text-center text-nowrap">Total (Kg)</th>
                    </tr>
                    <tr>
                        <?php foreach ($pivot_ftypes as $ft): ?>
                            <th class="text-center small fw-normal text-white-50" style="background:#2b3035;">Kg</th>
                        <?php endforeach; ?>
                        <th style="background:#2b3035;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $prev_estate   = null;
                $prev_division = null;
                foreach ($sorted_keys as $bkey):
                    $meta      = $pivot_meta[$bkey];
                    $bdata     = $pivot_data[$bkey];
                    $row_total = array_sum($bdata);

                    $show_estate   = ($meta['estate']   !== $prev_estate);
                    $show_division = ($meta['division'] !== $prev_division || $show_estate);

                    if ($show_estate) {
                        $estate_span = count(array_filter($sorted_keys,
                            fn($k) => $pivot_meta[$k]['estate'] === $meta['estate']));
                    }
                    if ($show_division) {
                        $div_span = count(array_filter($sorted_keys,
                            fn($k) => $pivot_meta[$k]['estate']   === $meta['estate']
                                   && $pivot_meta[$k]['division'] === $meta['division']));
                    }

                    $prev_estate   = $meta['estate'];
                    $prev_division = $meta['division'];
                ?>
                    <tr>
                        <?php if ($show_estate): ?>
                            <td rowspan="<?php echo $estate_span; ?>" class="align-middle fw-semibold text-nowrap bg-light">
                                <?php echo htmlspecialchars($meta['estate']); ?>
                            </td>
                        <?php endif; ?>
                        <?php if ($show_division): ?>
                            <td rowspan="<?php echo $div_span; ?>" class="align-middle text-nowrap bg-light">
                                <?php echo htmlspecialchars($meta['division']); ?>
                            </td>
                        <?php endif; ?>
                        <td class="text-nowrap">
                            <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($meta['block_code']); ?></span>
                            <?php echo htmlspecialchars($meta['block_name']); ?>
                        </td>
                        <?php foreach ($pivot_ftypes as $ft): ?>
                            <td class="text-end">
                                <?php $qty = $bdata[$ft] ?? 0;
                                echo $qty > 0 ? number_format($qty, 0, '.', ',') : '<span class="text-muted">-</span>'; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-end fw-bold text-success"><?php echo number_format($row_total, 0, '.', ','); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-success">
                    <tr>
                        <th colspan="3" class="text-end">Grand Total</th>
                        <?php foreach ($pivot_ftypes as $ft): ?>
                            <th class="text-end"><?php echo number_format($grand_totals[$ft], 0, '.', ','); ?></th>
                        <?php endforeach; ?>
                        <th class="text-end"><?php echo number_format($grand_row_total, 0, '.', ','); ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
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
                <input type="text" class="form-control" name="fertilizer_type" placeholder="Fertilizer type..." value="<?php echo htmlspecialchars($fertilizer_filter); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $status_filter == $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- Fertilization Records Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Fertilization Records (<?php echo count($records); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>WO Number</th>
                        <th>Block</th>
                        <th>Fertilizer Type</th>
                        <th>Grade</th>
                        <th>Quantity (Kg)</th>
                        <th>Method</th>
                        <th>Area (Ha)</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">No fertilization records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?php echo format_date($record['application_date']); ?></td>
                                <td><?php echo $record['work_order_number'] ? htmlspecialchars($record['work_order_number']) : '-'; ?></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($record['estate_name']); ?></small><br>
                                    <?php echo htmlspecialchars($record['block_name']); ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($record['fertilizer_type']); ?></strong></td>
                                <td><?php echo htmlspecialchars($record['fertilizer_grade']); ?></td>
                                <td><?php echo format_number($record['quantity_kg'], 0); ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo htmlspecialchars($record['application_method']); ?>
                                    </span>
                                </td>
                                <td><?php echo $record['area_covered'] ? format_number($record['area_covered'], 1) : '-'; ?></td>
                                <td>Rp <?php echo format_number($record['cost'], 0); ?></td>
                                <td><?php echo get_status_badge($record['status']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $record['fertilization_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $record['fertilization_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="fertilization.php" style="display:inline;" onsubmit="return confirmDelete('Delete this record?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="fertilization_id" value="<?php echo $record['fertilization_id']; ?>">
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

<!-- View Details Modals (outside table to keep valid HTML) -->
<?php foreach ($records as $record): ?>
<div class="modal fade" id="viewModal<?php echo $record['fertilization_id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Fertilization Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr>
                                <th width="40%">Application Date:</th>
                                <td><?php echo format_date($record['application_date']); ?></td>
                            </tr>
                            <tr>
                                <th>WO Number:</th>
                                <td><?php echo $record['work_order_number'] ? htmlspecialchars($record['work_order_number']) : '-'; ?></td>
                            </tr>
                            <tr>
                                <th>Block:</th>
                                <td><?php echo htmlspecialchars($record['block_name']); ?></td>
                            </tr>
                            <tr>
                                <th>Estate:</th>
                                <td><?php echo htmlspecialchars($record['estate_name']); ?></td>
                            </tr>
                            <tr>
                                <th>Fertilizer Type:</th>
                                <td><?php echo htmlspecialchars($record['fertilizer_type']); ?></td>
                            </tr>
                            <tr>
                                <th>Grade:</th>
                                <td><?php echo htmlspecialchars($record['fertilizer_grade'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th>Quantity:</th>
                                <td><?php echo format_number($record['quantity_kg'], 0); ?> Kg</td>
                            </tr>
                            <tr>
                                <th>Method:</th>
                                <td><?php echo htmlspecialchars($record['application_method'] ?? ''); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr>
                                <th width="40%">Dosage/Tree:</th>
                                <td><?php echo $record['dosage_per_tree'] ? format_number($record['dosage_per_tree'], 2) . ' Kg' : '-'; ?></td>
                            </tr>
                            <tr>
                                <th>Area Covered:</th>
                                <td><?php echo $record['area_covered'] ? format_number($record['area_covered'], 1) . ' Ha' : '-'; ?></td>
                            </tr>
                            <tr>
                                <th>Labor Count:</th>
                                <td><?php echo $record['labor_count'] ? $record['labor_count'] : '-'; ?></td>
                            </tr>
                            <tr>
                                <th>Labor Hours:</th>
                                <td><?php echo $record['labor_hours'] ? format_number($record['labor_hours'], 1) : '-'; ?></td>
                            </tr>
                            <tr>
                                <th>Cost:</th>
                                <td>Rp <?php echo format_number($record['cost'], 0); ?></td>
                            </tr>
                            <tr>
                                <th>Weather:</th>
                                <td><?php echo htmlspecialchars($record['weather_condition'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th>Performed By:</th>
                                <td><?php echo htmlspecialchars($record['performed_by'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th>Supervisor:</th>
                                <td><?php echo htmlspecialchars($record['supervisor'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td><?php echo htmlspecialchars($record['status'] ?? ''); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                <?php if ($record['notes']): ?>
                <div class="mt-3">
                    <h6>Notes:</h6>
                    <p><?php echo nl2br(htmlspecialchars($record['notes'] ?? '')); ?></p>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="fertilization.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_record ? 'Edit Fertilization Record' : 'Record Fertilization'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_record ? 'edit' : 'add'; ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="fertilization_id" value="<?php echo $edit_record['fertilization_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Work Order (Optional)</label>
                            <select class="form-select" name="work_order_id">
                                <option value="">No Work Order</option>
                                <?php foreach ($work_orders as $wo): ?>
                                    <option value="<?php echo $wo['work_order_id']; ?>" 
                                        <?php echo ($edit_record && $edit_record['work_order_id'] == $wo['work_order_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($wo['work_order_number'] . ' - ' . $wo['block_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Block <span class="text-danger">*</span></label>
                            <select class="form-select" name="block_id" required id="block_select">
                                <option value="">Select Block</option>
                                <?php foreach ($blocks as $block): ?>
                                    <option value="<?php echo $block['block_id']; ?>" 
                                        data-plants="<?php echo $block['total_plants']; ?>"
                                        <?php echo ($edit_record && $edit_record['block_id'] == $block['block_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($block['company_name'] . ' - ' . $block['unit_name'] . ' - ' . $block['block_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" id="plant_count"></small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Application Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="application_date" required
                                   value="<?php echo $edit_record ? $edit_record['application_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Fertilizer Type <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="fertilizer_type" required
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['fertilizer_type']) : ''; ?>"
                                   placeholder="e.g., NPK Compound, Urea">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Grade</label>
                            <input type="text" class="form-control" name="fertilizer_grade"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['fertilizer_grade']) : ''; ?>"
                                   placeholder="e.g., NPK 15-15-15">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Quantity (Kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="quantity_kg" required
                                   value="<?php echo $edit_record ? $edit_record['quantity_kg'] : ''; ?>"
                                   placeholder="e.g., 1000">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Application Method <span class="text-danger">*</span></label>
                            <select class="form-select" name="application_method" required>
                                <?php foreach ($application_methods as $method): ?>
                                    <option value="<?php echo $method; ?>" <?php echo ($edit_record && $edit_record['application_method'] == $method) ? 'selected' : ''; ?>>
                                        <?php echo $method; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Dosage per Tree (Kg)</label>
                            <input type="number" step="0.001" class="form-control" name="dosage_per_tree"
                                   value="<?php echo $edit_record ? $edit_record['dosage_per_tree'] : ''; ?>"
                                   placeholder="e.g., 2.5">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Area Covered (Ha)</label>
                            <input type="number" step="0.01" class="form-control" name="area_covered"
                                   value="<?php echo $edit_record ? $edit_record['area_covered'] : ''; ?>"
                                   placeholder="e.g., 5.5">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Labor Count</label>
                            <input type="number" class="form-control" name="labor_count"
                                   value="<?php echo $edit_record ? $edit_record['labor_count'] : ''; ?>"
                                   placeholder="Number of workers">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Labor Hours</label>
                            <input type="number" step="0.1" class="form-control" name="labor_hours"
                                   value="<?php echo $edit_record ? $edit_record['labor_hours'] : ''; ?>"
                                   placeholder="e.g., 40.5">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Cost (Rp)</label>
                            <input type="number" class="form-control" name="cost"
                                   value="<?php echo $edit_record ? $edit_record['cost'] : ''; ?>"
                                   placeholder="e.g., 1500000">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Weather Condition</label>
                            <input type="text" class="form-control" name="weather_condition"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['weather_condition']) : ''; ?>"
                                   placeholder="e.g., Sunny, Cloudy">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Performed By</label>
                            <input type="text" class="form-control" name="performed_by"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['performed_by']) : ''; ?>"
                                   placeholder="Team or person name">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Supervisor</label>
                            <input type="text" class="form-control" name="supervisor"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['supervisor']) : ''; ?>"
                                   placeholder="Supervisor name">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo $status; ?>" <?php echo ($edit_record && $edit_record['status'] == $status) ? 'selected' : ((!$edit_record && $status == 'Planned') ? 'selected' : ''); ?>>
                                    <?php echo $status; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes..."><?php echo $edit_record ? htmlspecialchars($edit_record['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_record ? 'Update' : 'Record'; ?> Fertilization
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_record): ?>
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

// Show plant count when block is selected
document.getElementById('block_select').addEventListener('change', function() {
    var selected = this.options[this.selectedIndex];
    var plants = selected.getAttribute('data-plants');
    if (plants) {
        document.getElementById('plant_count').textContent = 'Total plants: ' + plants;
    } else {
        document.getElementById('plant_count').textContent = '';
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
