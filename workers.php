<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO workers (
                    employee_code, full_name, id_number, phone, email, address,
                    position, hire_date, date_of_birth, gender, 
                    emergency_contact_name, emergency_contact_phone, status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([
                post('employee_code'),
                post('full_name'),
                post('id_number'),
                post('phone'),
                post('email') ?: null,
                post('address') ?: null,
                post('position'),
                post('hire_date'),
                post('date_of_birth') ?: null,
                post('gender'),
                post('emergency_contact_name') ?: null,
                post('emergency_contact_phone') ?: null,
                post('status') ?: 'active'
            ]);
            
            set_message('Worker added successfully!', 'success');
            redirect('workers.php');
        } catch (Exception $e) {
            set_message('Error adding worker: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'update') {
        try {
            $stmt = $db->prepare("
                UPDATE workers SET
                    employee_code = ?, full_name = ?, id_number = ?, phone = ?, email = ?,
                    address = ?, position = ?, hire_date = ?, date_of_birth = ?, gender = ?,
                    emergency_contact_name = ?, emergency_contact_phone = ?, status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                post('employee_code'),
                post('full_name'),
                post('id_number'),
                post('phone'),
                post('email') ?: null,
                post('address') ?: null,
                post('position'),
                post('hire_date'),
                post('date_of_birth') ?: null,
                post('gender'),
                post('emergency_contact_name') ?: null,
                post('emergency_contact_phone') ?: null,
                post('status'),
                post('worker_id')
            ]);
            
            set_message('Worker updated successfully!', 'success');
            redirect('workers.php');
        } catch (Exception $e) {
            set_message('Error updating worker: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'delete') {
        try {
            $stmt = $db->prepare("UPDATE workers SET status = 'terminated', termination_date = CURDATE(), updated_at = NOW() WHERE id = ?");
            $stmt->execute([post('worker_id')]);
            
            set_message('Worker terminated successfully!', 'success');
            redirect('workers.php');
        } catch (Exception $e) {
            set_message('Error terminating worker: ' . $e->getMessage(), 'danger');
        }
    }
}

// Get edit record if editing
$edit_worker = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM workers WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_worker = $stmt->fetch();
}

// Pagination and filters
$page = get('page', 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

$status_filter = get('status', '');
$position_filter = get('position', '');
$search = get('search', '');

// Fetch workers with filters
$workers_sql = "SELECT * FROM workers WHERE 1=1";
$params = [];

if ($status_filter) {
    $workers_sql .= " AND status = ?";
    $params[] = $status_filter;
}
if ($position_filter) {
    $workers_sql .= " AND position = ?";
    $params[] = $position_filter;
}
if ($search) {
    $workers_sql .= " AND (full_name LIKE ? OR employee_code LIKE ? OR phone LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$workers_sql .= " ORDER BY employee_code LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($workers_sql);
$stmt->execute($params);
$workers = $stmt->fetchAll();

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM workers WHERE 1=1";
$count_params = [];
if ($status_filter) {
    $count_sql .= " AND status = ?";
    $count_params[] = $status_filter;
}
if ($position_filter) {
    $count_sql .= " AND position = ?";
    $count_params[] = $position_filter;
}
if ($search) {
    $count_sql .= " AND (full_name LIKE ? OR employee_code LIKE ? OR phone LIKE ?)";
    $search_term = "%$search%";
    $count_params[] = $search_term;
    $count_params[] = $search_term;
    $count_params[] = $search_term;
}

$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_records = $stmt->fetch()['total'];
$total_pages = ceil($total_records / $per_page);

// Get unique positions for filter
$positions_stmt = $db->query("SELECT DISTINCT position FROM workers WHERE position IS NOT NULL ORDER BY position");
$positions = $positions_stmt->fetchAll();

// Get statistics
$stats_stmt = $db->query("
    SELECT 
        COUNT(*) as total_workers,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_workers,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_workers,
        SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END) as terminated_workers
    FROM workers
");
$stats = $stats_stmt->fetch();

$page_title = "Workers Management";
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
.btn-attendance {
    background-color: #3aafc7;
    border-color: #3aafc7;
    color: #fff;
}
.btn-attendance:hover, .btn-attendance:focus {
    background-color: #5dc3d8;
    border-color: #5dc3d8;
    color: #fff;
}
.btn-assignments {
    background-color: #e8b61e;
    border-color: #e8b61e;
    color: #fff;
}
.btn-assignments:hover, .btn-assignments:focus {
    background-color: #edca52;
    border-color: #edca52;
    color: #fff;
}
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color: #3a618c;"><i class="bi bi-people"></i> Workers Management</h1>
            <p class="text-muted">Manage plantation workers and employees</p>
        </div>
        <div class="col-auto">
            <a href="worker_attendance.php" class="btn btn-attendance">
                <i class="bi bi-calendar-check"></i> Attendance
            </a>
            <a href="worker_assignments.php" class="btn btn-assignments">
                <i class="bi bi-clipboard-check"></i> Assignments
            </a>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card border-primary">
            <div class="card-body text-center">
                <h3 class="text-primary"><?php echo number_format($stats['total_workers']); ?></h3>
                <p class="mb-0">Total Workers</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-success">
            <div class="card-body text-center">
                <h3 class="text-success"><?php echo number_format($stats['active_workers']); ?></h3>
                <p class="mb-0">Active Workers</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-warning">
            <div class="card-body text-center">
                <h3 class="text-warning"><?php echo number_format($stats['inactive_workers']); ?></h3>
                <p class="mb-0">Inactive Workers</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-danger">
            <div class="card-body text-center">
                <h3 class="text-danger"><?php echo number_format($stats['terminated_workers']); ?></h3>
                <p class="mb-0">Terminated</p>
            </div>
        </div>
    </div>
</div>

<!-- Entry Form -->
<div class="card mb-4">
    <div class="card-header" style="background-color: #3a618c; color: white;">
        <i class="bi bi-person-plus"></i> <?php echo $edit_worker ? 'Edit' : 'Add New'; ?> Worker
    </div>
    <div class="card-body">
        <form method="POST" action="workers.php">
            <input type="hidden" name="action" value="<?php echo $edit_worker ? 'update' : 'add'; ?>">
            <?php if ($edit_worker): ?>
                <input type="hidden" name="worker_id" value="<?php echo $edit_worker['id']; ?>">
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Employee Code <span class="text-danger">*</span></label>
                    <input type="text" name="employee_code" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['employee_code']) : ''; ?>" 
                           placeholder="EMP001" required>
                </div>
                
                <div class="col-md-5 mb-3">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['full_name']) : ''; ?>" 
                           placeholder="Full Name" required>
                </div>
                
                <div class="col-md-4 mb-3">
                    <label class="form-label">ID Number</label>
                    <input type="text" name="id_number" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['id_number']) : ''; ?>" 
                           placeholder="ID/KTP Number">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Phone <span class="text-danger">*</span></label>
                    <input type="text" name="phone" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['phone']) : ''; ?>" 
                           placeholder="08123456789" required>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['email']) : ''; ?>" 
                           placeholder="email@example.com">
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Position <span class="text-danger">*</span></label>
                    <input type="text" name="position" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['position']) : ''; ?>" 
                           placeholder="e.g., Harvester" required list="position-list">
                    <datalist id="position-list">
                        <option value="Field Supervisor">
                        <option value="Harvester">
                        <option value="Maintenance Worker">
                        <option value="Fertilizer Applicator">
                        <option value="Pest Control Specialist">
                        <option value="Quality Inspector">
                        <option value="Equipment Operator">
                    </datalist>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="active" <?php echo ($edit_worker && $edit_worker['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($edit_worker && $edit_worker['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        <option value="suspended" <?php echo ($edit_worker && $edit_worker['status'] == 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                        <option value="terminated" <?php echo ($edit_worker && $edit_worker['status'] == 'terminated') ? 'selected' : ''; ?>>Terminated</option>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Hire Date <span class="text-danger">*</span></label>
                    <input type="date" name="hire_date" class="form-control" 
                           value="<?php echo $edit_worker ? $edit_worker['hire_date'] : date('Y-m-d'); ?>" required>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" 
                           value="<?php echo $edit_worker ? $edit_worker['date_of_birth'] : ''; ?>">
                </div>
                
                <div class="col-md-2 mb-3">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">Select</option>
                        <option value="male" <?php echo ($edit_worker && $edit_worker['gender'] == 'male') ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo ($edit_worker && $edit_worker['gender'] == 'female') ? 'selected' : ''; ?>>Female</option>
                        <option value="other" <?php echo ($edit_worker && $edit_worker['gender'] == 'other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div class="col-md-4 mb-3">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['address']) : ''; ?>" 
                           placeholder="Full Address">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Emergency Contact Name</label>
                    <input type="text" name="emergency_contact_name" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['emergency_contact_name']) : ''; ?>" 
                           placeholder="Emergency Contact Name">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">Emergency Contact Phone</label>
                    <input type="text" name="emergency_contact_phone" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['emergency_contact_phone']) : ''; ?>" 
                           placeholder="Emergency Contact Phone">
                </div>
            </div>
            
            <div class="row">
                <div class="col-12">
                    <button type="submit" class="btn btn-agro">
                        <i class="bi bi-save"></i> <?php echo $edit_worker ? 'Update' : 'Save'; ?> Worker
                    </button>
                    <?php if ($edit_worker): ?>
                        <a href="workers.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Filter Section -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Name, Code, or Phone">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="suspended" <?php echo $status_filter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    <option value="terminated" <?php echo $status_filter == 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Position</label>
                <select name="position" class="form-select">
                    <option value="">All Positions</option>
                    <?php foreach ($positions as $pos): ?>
                        <option value="<?php echo htmlspecialchars($pos['position']); ?>"
                                <?php echo $position_filter == $pos['position'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($pos['position']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-agro me-2">
                    <i class="bi bi-funnel"></i> Filter
                </button>
                <a href="workers.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Clear
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Workers List -->
<div class="card">
    <div class="card-header" style="background-color: #3a618c; color: white;">
        <i class="bi bi-list-ul"></i> Workers List (<?php echo number_format($total_records); ?> workers)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Employee Code</th>
                        <th>Name</th>
                        <th>Position</th>
                        <th>Phone</th>
                        <th>Hire Date</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($workers)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No workers found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($workers as $worker): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($worker['employee_code']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($worker['full_name']); ?><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($worker['email'] ?: 'No email'); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($worker['position']); ?></td>
                                <td><?php echo htmlspecialchars($worker['phone']); ?></td>
                                <td><?php echo date('d M Y', strtotime($worker['hire_date'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo match($worker['status']) {
                                            'active' => 'success',
                                            'inactive' => 'warning',
                                            'suspended' => 'danger',
                                            'terminated' => 'secondary',
                                            default => 'secondary'
                                        };
                                    ?>">
                                        <?php echo ucfirst($worker['status']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="workers.php?edit=<?php echo $worker['id']; ?>" 
                                       class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ($worker['status'] != 'terminated'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Terminate this worker?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="worker_id" value="<?php echo $worker['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Terminate">
                                                <i class="bi bi-person-x"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
            <?php echo generate_pagination($page, $total_pages, $_GET); ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
