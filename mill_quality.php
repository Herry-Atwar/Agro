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
                INSERT INTO mill_quality_control
                (production_id, test_date, test_time, tested_by, lab_technician,
                 ffa_percentage, moisture_content, dirt_content, iodine_value,
                 peroxide_value, color_lovibond_red, color_lovibond_yellow,
                 dobi_value, carotene_content, vitamin_e_content,
                 melting_point, slip_melting_point, cloud_point,
                 test_result, compliance_status, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('production_id'),
                post('test_date'),
                post('test_time'),
                post('tested_by'),
                post('lab_technician'),
                post('ffa_percentage'),
                post('moisture_content'),
                post('dirt_content'),
                post('iodine_value'),
                post('peroxide_value'),
                post('color_lovibond_red'),
                post('color_lovibond_yellow'),
                post('dobi_value'),
                post('carotene_content'),
                post('vitamin_e_content'),
                post('melting_point'),
                post('slip_melting_point'),
                post('cloud_point'),
                post('test_result'),
                post('compliance_status'),
                post('remarks'),
                'admin'
            ]);
            
            set_message('success', 'Quality test record added successfully!');
            redirect('mill_quality.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding quality test: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE mill_quality_control
                SET production_id = ?, test_date = ?, test_time = ?, tested_by = ?,
                    lab_technician = ?, ffa_percentage = ?, moisture_content = ?,
                    dirt_content = ?, iodine_value = ?, peroxide_value = ?,
                    color_lovibond_red = ?, color_lovibond_yellow = ?, dobi_value = ?,
                    carotene_content = ?, vitamin_e_content = ?, melting_point = ?,
                    slip_melting_point = ?, cloud_point = ?, test_result = ?,
                    compliance_status = ?, remarks = ?, updated_by = ?
                WHERE quality_id = ?
            ");
            
            $stmt->execute([
                post('production_id'),
                post('test_date'),
                post('test_time'),
                post('tested_by'),
                post('lab_technician'),
                post('ffa_percentage'),
                post('moisture_content'),
                post('dirt_content'),
                post('iodine_value'),
                post('peroxide_value'),
                post('color_lovibond_red'),
                post('color_lovibond_yellow'),
                post('dobi_value'),
                post('carotene_content'),
                post('vitamin_e_content'),
                post('melting_point'),
                post('slip_melting_point'),
                post('cloud_point'),
                post('test_result'),
                post('compliance_status'),
                post('remarks'),
                'admin',
                post('quality_id')
            ]);
            
            set_message('success', 'Quality test updated successfully!');
            redirect('mill_quality.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating quality test: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM mill_quality_control WHERE quality_id = ?");
            $stmt->execute([post('quality_id')]);
            
            set_message('success', 'Quality test deleted successfully!');
            redirect('mill_quality.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting quality test: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_record = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM mill_quality_control WHERE quality_id = ?");
    $stmt->execute([get('id')]);
    $edit_record = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Mill Quality Control";
require_once 'includes/header.php';

// Fetch production records for dropdown
$productions = [];
try {
    $productions_stmt = $db->query("
        SELECT p.production_id, p.production_date, p.cpo_produced_kg,
               b.batch_no, m.mill_name
        FROM mill_production p
        INNER JOIN mill_processing_batch b ON p.batch_id = b.batch_id
        INNER JOIN mill_master m ON b.mill_id = m.mill_id
        ORDER BY p.production_date DESC
    ");
    $productions = $productions_stmt->fetchAll();
} catch (PDOException $e) {
    $productions = [];
}

// Fetch quality test records with filters
$search = get('search', '');
$date_from = get('date_from', date('Y-01-01'));
$date_to = get('date_to', date('Y-m-d'));
$compliance_filter = get('compliance_status', '');
$db_error = null;

try {
    $sql = "SELECT q.*,
            p.production_date, p.cpo_produced_kg,
            b.batch_no, m.mill_name
            FROM mill_quality_control q
            INNER JOIN mill_production p ON q.production_id = p.production_id
            INNER JOIN mill_processing_batch b ON p.batch_id = b.batch_id
            INNER JOIN mill_master m ON b.mill_id = m.mill_id
            WHERE 1=1";

    $params = [];
    if ($search) {
        $sql .= " AND (b.batch_no LIKE ? OR m.mill_name LIKE ? OR q.tested_by LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($date_from) {
        $sql .= " AND q.test_date >= ?";
        $params[] = $date_from;
    }
    if ($date_to) {
        $sql .= " AND q.test_date <= ?";
        $params[] = $date_to;
    }
    if ($compliance_filter) {
        $sql .= " AND q.compliance_status = ?";
        $params[] = $compliance_filter;
    }

    $sql .= " ORDER BY q.test_date DESC, q.test_time DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $quality_tests = $stmt->fetchAll();
} catch (PDOException $e) {
    $quality_tests = [];
    $db_error = $e->getMessage();
}

// Calculate summary statistics
$total_tests = count($quality_tests);
$passed_tests = count(array_filter($quality_tests, function($q) { return $q['test_result'] == 'passed'; }));
$failed_tests = count(array_filter($quality_tests, function($q) { return $q['test_result'] == 'failed'; }));
$compliant = count(array_filter($quality_tests, function($q) { return $q['compliance_status'] == 'compliant'; }));
$avg_ffa = $total_tests > 0 ? array_sum(array_column($quality_tests, 'ffa_percentage')) / $total_tests : 0;
$avg_moisture = $total_tests > 0 ? array_sum(array_column($quality_tests, 'moisture_content')) / $total_tests : 0;

$test_results = ['passed', 'failed', 'pending'];
$compliance_statuses = ['compliant', 'non_compliant', 'conditional'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color: #d6a51e;"><i class="bi bi-clipboard-check" style="color: #d6a51e;"></i> Mill Quality Control</h1>
            <p class="text-muted">CPO quality testing and compliance monitoring</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-mill" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add Quality Test
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_tests; ?></h3>
                <p><i class="bi bi-clipboard-data"></i> Total Tests</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $passed_tests; ?></h3>
                <p><i class="bi bi-check-circle text-success"></i> Passed</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $compliant; ?></h3>
                <p><i class="bi bi-shield-check text-primary"></i> Compliant</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($avg_ffa, 2); ?>%</h3>
                <p><i class="bi bi-graph-up"></i> Avg FFA</p>
            </div>
        </div>
    </div>
</div>

<!-- Quality Parameters Dashboard -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header text-white" style="background-color: #d6a51e;">
                <i class="bi bi-bar-chart"></i> Quality Parameters Overview
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-2">
                        <h5 class="text-<?php echo $avg_ffa <= 5 ? 'success' : 'danger'; ?>"><?php echo format_number($avg_ffa, 2); ?>%</h5>
                        <small>Avg FFA</small><br>
                        <small class="text-muted">Standard: ≤5%</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-<?php echo $avg_moisture <= 0.25 ? 'success' : 'danger'; ?>"><?php echo format_number($avg_moisture, 2); ?>%</h5>
                        <small>Avg Moisture</small><br>
                        <small class="text-muted">Standard: ≤0.25%</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-success"><?php echo $passed_tests; ?></h5>
                        <small>Passed Tests</small><br>
                        <small class="text-muted"><?php echo $total_tests > 0 ? format_number($passed_tests/$total_tests*100, 1) : 0; ?>%</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-danger"><?php echo $failed_tests; ?></h5>
                        <small>Failed Tests</small><br>
                        <small class="text-muted"><?php echo $total_tests > 0 ? format_number($failed_tests/$total_tests*100, 1) : 0; ?>%</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-primary"><?php echo $compliant; ?></h5>
                        <small>Compliant</small><br>
                        <small class="text-muted"><?php echo $total_tests > 0 ? format_number($compliant/$total_tests*100, 1) : 0; ?>%</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-warning"><?php echo $total_tests - $compliant; ?></h5>
                        <small>Non-Compliant</small><br>
                        <small class="text-muted"><?php echo $total_tests > 0 ? format_number(($total_tests-$compliant)/$total_tests*100, 1) : 0; ?>%</small>
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
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="compliance_status">
                    <option value="">All Compliance</option>
                    <option value="compliant" <?php echo $compliance_filter == 'compliant' ? 'selected' : ''; ?>>Compliant</option>
                    <option value="non_compliant" <?php echo $compliance_filter == 'non_compliant' ? 'selected' : ''; ?>>Non-Compliant</option>
                    <option value="conditional" <?php echo $compliance_filter == 'conditional' ? 'selected' : ''; ?>>Conditional</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-mill"><i class="bi bi-search"></i> Search</button>
                <a href="mill_quality.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Quality Tests Table -->
<div class="card">
    <div class="card-header text-white" style="background-color: #d6a51e;">
        <i class="bi bi-list-ul"></i> Quality Test Records (<?php echo count($quality_tests); ?>)
    </div>
    <div class="card-body">
        <?php if (!empty($db_error)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <strong>Database Error:</strong> <?php echo htmlspecialchars($db_error); ?>
                <br><small>Please ensure the <code>mill_quality_control</code> table exists. Run <code>database/mill_quality_schema.sql</code> to create it.</small>
            </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered table-sm">
                <thead>
                    <tr>
                        <th>Test Date</th>
                        <th>Batch #</th>
                        <th>Mill</th>
                        <th>FFA %</th>
                        <th>Moisture %</th>
                        <th>Dirt %</th>
                        <th>DOBI</th>
                        <th>Tested By</th>
                        <th>Result</th>
                        <th>Compliance</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($quality_tests)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">No quality test records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($quality_tests as $test): ?>
                            <tr>
                                <td>
                                    <?php echo format_date($test['test_date']); ?><br>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($test['test_time'])); ?></small>
                                </td>
                                <td><strong><?php echo htmlspecialchars($test['batch_no']); ?></strong></td>
                                <td><?php echo htmlspecialchars($test['mill_name']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $test['ffa_percentage'] <= 5 ? 'success' : 'danger'; ?>">
                                        <?php echo format_number($test['ffa_percentage'], 2); ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $test['moisture_content'] <= 0.25 ? 'success' : 'danger'; ?>">
                                        <?php echo format_number($test['moisture_content'], 2); ?>%
                                    </span>
                                </td>
                                <td><?php echo format_number($test['dirt_content'], 3); ?>%</td>
                                <td><?php echo format_number($test['dobi_value'], 2); ?></td>
                                <td><?php echo htmlspecialchars($test['tested_by']); ?></td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo $test['test_result'] == 'passed' ? 'success' :
                                            ($test['test_result'] == 'failed' ? 'danger' : 'warning');
                                    ?>">
                                        <?php echo ucfirst($test['test_result']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo $test['compliance_status'] == 'compliant' ? 'primary' :
                                            ($test['compliance_status'] == 'non_compliant' ? 'danger' : 'warning');
                                    ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $test['compliance_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $test['quality_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $test['quality_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this test?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="quality_id" value="<?php echo $test['quality_id']; ?>">
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
<?php foreach ($quality_tests as $test): ?>
<div class="modal fade" id="viewModal<?php echo $test['quality_id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Quality Test Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4">
                        <h6 class="border-bottom pb-2">Test Information</h6>
                        <table class="table table-sm">
                            <tr><th width="50%">Test Date:</th><td><?php echo format_date($test['test_date']); ?></td></tr>
                            <tr><th>Test Time:</th><td><?php echo date('H:i', strtotime($test['test_time'])); ?></td></tr>
                            <tr><th>Batch Number:</th><td><strong><?php echo htmlspecialchars($test['batch_no']); ?></strong></td></tr>
                            <tr><th>Mill:</th><td><?php echo htmlspecialchars($test['mill_name']); ?></td></tr>
                            <tr><th>Tested By:</th><td><?php echo htmlspecialchars($test['tested_by']); ?></td></tr>
                            <tr><th>Lab Technician:</th><td><?php echo htmlspecialchars($test['lab_technician']); ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-4">
                        <h6 class="border-bottom pb-2">Chemical Properties</h6>
                        <table class="table table-sm">
                            <tr><th width="50%">FFA:</th><td><?php echo format_number($test['ffa_percentage'], 2); ?>%</td></tr>
                            <tr><th>Moisture:</th><td><?php echo format_number($test['moisture_content'], 2); ?>%</td></tr>
                            <tr><th>Dirt:</th><td><?php echo format_number($test['dirt_content'], 3); ?>%</td></tr>
                            <tr><th>Iodine Value:</th><td><?php echo format_number($test['iodine_value'], 2); ?></td></tr>
                            <tr><th>Peroxide Value:</th><td><?php echo format_number($test['peroxide_value'], 2); ?></td></tr>
                            <tr><th>DOBI:</th><td><?php echo format_number($test['dobi_value'], 2); ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-4">
                        <h6 class="border-bottom pb-2">Physical Properties</h6>
                        <table class="table table-sm">
                            <tr><th width="50%">Color (Red):</th><td><?php echo format_number($test['color_lovibond_red'], 1); ?></td></tr>
                            <tr><th>Color (Yellow):</th><td><?php echo format_number($test['color_lovibond_yellow'], 1); ?></td></tr>
                            <tr><th>Carotene:</th><td><?php echo format_number($test['carotene_content'], 0); ?> ppm</td></tr>
                            <tr><th>Vitamin E:</th><td><?php echo format_number($test['vitamin_e_content'], 0); ?> ppm</td></tr>
                            <tr><th>Melting Point:</th><td><?php echo format_number($test['melting_point'], 1); ?>°C</td></tr>
                            <tr><th>Cloud Point:</th><td><?php echo format_number($test['cloud_point'], 1); ?>°C</td></tr>
                        </table>
                        <h6 class="border-bottom pb-2 mt-3">Test Result</h6>
                        <table class="table table-sm">
                            <tr><th width="50%">Result:</th><td><span class="badge bg-<?php echo $test['test_result'] == 'passed' ? 'success' : 'danger'; ?>"><?php echo ucfirst($test['test_result']); ?></span></td></tr>
                            <tr><th>Compliance:</th><td><span class="badge bg-<?php echo $test['compliance_status'] == 'compliant' ? 'primary' : 'danger'; ?>"><?php echo ucfirst(str_replace('_', ' ', $test['compliance_status'])); ?></span></td></tr>
                        </table>
                    </div>
                </div>
                <?php if ($test['remarks']): ?>
                <div class="mt-3"><h6>Remarks:</h6><p><?php echo nl2br(htmlspecialchars($test['remarks'])); ?></p></div>
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
            <form method="POST" action="mill_quality.php">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <?php echo $edit_record ? 'Edit Quality Test' : 'Add Quality Test'; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_record ? 'edit' : 'add'; ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="quality_id" value="<?php echo $edit_record['quality_id']; ?>">
                    <?php endif; ?>
                    
                    <!-- Test Information -->
                    <h6 class="border-bottom pb-2 mb-3">Test Information</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Production Batch <span class="text-danger">*</span></label>
                            <select class="form-select" name="production_id" required>
                                <option value="">Select Production</option>
                                <?php foreach ($productions as $prod): ?>
                                    <option value="<?php echo $prod['production_id']; ?>"
                                        <?php echo ($edit_record && $edit_record['production_id'] == $prod['production_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($prod['batch_no'] . ' - ' . $prod['mill_name'] . ' (' . format_date($prod['production_date']) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Test Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="test_date" required
                                   value="<?php echo $edit_record ? $edit_record['test_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Test Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="test_time" required
                                   value="<?php echo $edit_record ? date('H:i', strtotime($edit_record['test_time'])) : date('H:i'); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tested By</label>
                            <input type="text" class="form-control" name="tested_by"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['tested_by']) : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Lab Technician</label>
                            <input type="text" class="form-control" name="lab_technician"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['lab_technician']) : ''; ?>">
                        </div>
                    </div>
                    
                    <!-- Chemical Properties -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4">Chemical Properties</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">FFA (%) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="ffa_percentage" required
                                   value="<?php echo $edit_record ? $edit_record['ffa_percentage'] : ''; ?>"
                                   placeholder="Standard: ≤5%">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Moisture (%) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="moisture_content" required
                                   value="<?php echo $edit_record ? $edit_record['moisture_content'] : ''; ?>"
                                   placeholder="Standard: ≤0.25%">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Dirt (%)</label>
                            <input type="number" step="0.001" class="form-control" name="dirt_content"
                                   value="<?php echo $edit_record ? $edit_record['dirt_content'] : ''; ?>"
                                   placeholder="Standard: ≤0.02%">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Iodine Value</label>
                            <input type="number" step="0.01" class="form-control" name="iodine_value"
                                   value="<?php echo $edit_record ? $edit_record['iodine_value'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Peroxide Value</label>
                            <input type="number" step="0.01" class="form-control" name="peroxide_value"
                                   value="<?php echo $edit_record ? $edit_record['peroxide_value'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">DOBI</label>
                            <input type="number" step="0.01" class="form-control" name="dobi_value"
                                   value="<?php echo $edit_record ? $edit_record['dobi_value'] : ''; ?>">
                        </div>
                    </div>
                    
                    <!-- Physical Properties -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4">Physical Properties</h6>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Color (Red)</label>
                            <input type="number" step="0.1" class="form-control" name="color_lovibond_red"
                                   value="<?php echo $edit_record ? $edit_record['color_lovibond_red'] : ''; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Color (Yellow)</label>
                            <input type="number" step="0.1" class="form-control" name="color_lovibond_yellow"
                                   value="<?php echo $edit_record ? $edit_record['color_lovibond_yellow'] : ''; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Carotene (ppm)</label>
                            <input type="number" step="1" class="form-control" name="carotene_content"
                                   value="<?php echo $edit_record ? $edit_record['carotene_content'] : ''; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Vitamin E (ppm)</label>
                            <input type="number" step="1" class="form-control" name="vitamin_e_content"
                                   value="<?php echo $edit_record ? $edit_record['vitamin_e_content'] : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Melting Point (°C)</label>
                            <input type="number" step="0.1" class="form-control" name="melting_point"
                                   value="<?php echo $edit_record ? $edit_record['melting_point'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Slip Melting Point (°C)</label>
                            <input type="number" step="0.1" class="form-control" name="slip_melting_point"
                                   value="<?php echo $edit_record ? $edit_record['slip_melting_point'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Cloud Point (°C)</label>
                            <input type="number" step="0.1" class="form-control" name="cloud_point"
                                   value="<?php echo $edit_record ? $edit_record['cloud_point'] : ''; ?>">
                        </div>
                    </div>
                    
                    <!-- Test Result -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4">Test Result</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Test Result <span class="text-danger">*</span></label>
                            <select class="form-select" name="test_result" required>
                                <?php foreach ($test_results as $result): ?>
                                    <option value="<?php echo $result; ?>" <?php echo ($edit_record && $edit_record['test_result'] == $result) ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($result); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Compliance Status <span class="text-danger">*</span></label>
                            <select class="form-select" name="compliance_status" required>
                                <?php foreach ($compliance_statuses as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo ($edit_record && $edit_record['compliance_status'] == $status) ? 'selected' : ''; ?>>
                                        <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="2"><?php echo $edit_record ? htmlspecialchars($edit_record['remarks']) : ''; ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_record ? 'Update' : 'Save'; ?>
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
<style>
/* Custom button styles for Mill Operations */
.btn-mill {
    background-color: #c98600;
    border-color: #c98600;
    color: white;
}

.btn-mill:hover {
    background-color: #b07600;
    border-color: #b07600;
    color: white;
}

.btn-mill:focus,
.btn-mill:active {
    background-color: #976500;
    border-color: #976500;
    color: white;
}

/* Custom stat-card border and number color for Mill Quality */
.stat-card {
    border-left: 4px solid #c98600;
}

.stat-card h3 {
    color: #c98600;
}
</style>


<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob