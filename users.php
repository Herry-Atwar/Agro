<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

require_role('admin');

$db  = getDB();
$action  = $_GET['action'] ?? 'list';
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── helper: hash password ────────────────────────────────────
function make_hash($plain) {
    return password_hash($plain, PASSWORD_BCRYPT);
}

// ── POST handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['post_action'] ?? '';

    // ADD
    if ($post_action === 'add') {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'staff';
        $lang     = $_POST['preferred_language'] ?? 'en';
        $company  = !empty($_POST['company_id'])       ? (int)$_POST['company_id']       : null;
        $bu       = !empty($_POST['business_unit_id']) ? (int)$_POST['business_unit_id'] : null;
        $div      = !empty($_POST['division_id'])      ? (int)$_POST['division_id']       : null;

        if (!$name || !$email || !$password) {
            set_message('error', 'Name, email and password are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_message('error', 'Invalid email address.');
        } elseif (strlen($password) < 6) {
            set_message('error', 'Password must be at least 6 characters.');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO users (name, email, password, role, company_id, business_unit_id, division_id, preferred_language, is_active) VALUES (?,?,?,?,?,?,?,?,1)");
                $stmt->execute([$name, $email, make_hash($password), $role, $company, $bu, $div, $lang]);
                set_message('success', "User <strong>" . htmlspecialchars($name) . "</strong> created successfully.");
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    set_message('error', 'Email address already exists.');
                } else {
                    set_message('error', 'Database error: ' . $e->getMessage());
                }
            }
        }
        redirect('users.php');
    }

    // EDIT
    if ($post_action === 'edit') {
        $uid      = (int)($_POST['user_id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = $_POST['role'] ?? 'staff';
        $lang     = $_POST['preferred_language'] ?? 'en';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $company  = !empty($_POST['company_id'])       ? (int)$_POST['company_id']       : null;
        $bu       = !empty($_POST['business_unit_id']) ? (int)$_POST['business_unit_id'] : null;
        $div      = !empty($_POST['division_id'])      ? (int)$_POST['division_id']       : null;
        $new_pw   = $_POST['new_password'] ?? '';

        if (!$name || !$email) {
            set_message('error', 'Name and email are required.');
            redirect('users.php?action=edit&id=' . $uid);
        }

        try {
            if ($new_pw !== '') {
                if (strlen($new_pw) < 6) {
                    set_message('error', 'New password must be at least 6 characters.');
                    redirect('users.php?action=edit&id=' . $uid);
                }
                $stmt = $db->prepare("UPDATE users SET name=?, email=?, password=?, role=?, company_id=?, business_unit_id=?, division_id=?, preferred_language=?, is_active=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$name, $email, make_hash($new_pw), $role, $company, $bu, $div, $lang, $is_active, $uid]);
            } else {
                $stmt = $db->prepare("UPDATE users SET name=?, email=?, role=?, company_id=?, business_unit_id=?, division_id=?, preferred_language=?, is_active=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$name, $email, $role, $company, $bu, $div, $lang, $is_active, $uid]);
            }
            // Refresh own session if editing self
            if ($uid == $_SESSION['user_id']) refresh_session();
            set_message('success', "User <strong>" . htmlspecialchars($name) . "</strong> updated successfully.");
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                set_message('error', 'Email address already exists.');
            } else {
                set_message('error', 'Database error: ' . $e->getMessage());
            }
        }
        redirect('users.php');
    }

    // TOGGLE ACTIVE
    if ($post_action === 'toggle') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid == $_SESSION['user_id']) {
            set_message('error', 'You cannot deactivate your own account.');
        } else {
            $db->prepare("UPDATE users SET is_active = 1 - is_active, updated_at=NOW() WHERE id=?")->execute([$uid]);
            set_message('success', 'User status updated.');
        }
        redirect('users.php');
    }
}

// ── Load data for forms ──────────────────────────────────────
$companies = $db->query("SELECT company_id, company_name FROM companies WHERE status='Active' ORDER BY company_name")->fetchAll();
$bus       = $db->query("SELECT business_unit_id, unit_name, company_id FROM business_units WHERE status='Active' ORDER BY unit_name")->fetchAll();
$divisions = $db->query("SELECT division_id, division_name, business_unit_id FROM divisions WHERE status='Active' ORDER BY division_name")->fetchAll();
// JSON maps for cascade JS
$bus_json = json_encode(array_map(fn($r) => ['id'=>$r['business_unit_id'],'name'=>$r['unit_name'],'company_id'=>(int)$r['company_id']], $bus));
$div_json = json_encode(array_map(fn($r) => ['id'=>$r['division_id'],'name'=>$r['division_name'],'bu_id'=>(int)$r['business_unit_id']], $divisions));

$roles = ['admin' => 'Admin', 'manager' => 'Manager', 'supervisor' => 'Supervisor', 'staff' => 'Staff'];
$langs = ['en' => 'English', 'id' => 'Indonesian'];

// ── EDIT: load user ──────────────────────────────────────────
$edit_user = null;
if ($action === 'edit' && $user_id) {
    $edit_user = $db->prepare("SELECT * FROM users WHERE id=?");
    $edit_user->execute([$user_id]);
    $edit_user = $edit_user->fetch();
    if (!$edit_user) { set_message('error', 'User not found.'); redirect('users.php'); }
}

// ── LIST ─────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$filter_role = $_GET['role'] ?? '';
$where = 'WHERE 1=1';
$params = [];
if ($search) { $where .= " AND (u.name LIKE ? OR u.email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($filter_role) { $where .= " AND u.role = ?"; $params[] = $filter_role; }

$users_list = $db->prepare("
    SELECT u.*, c.company_name, b.unit_name
    FROM users u
    LEFT JOIN companies c ON u.company_id = c.company_id
    LEFT JOIN business_units b ON u.business_unit_id = b.business_unit_id
    $where
    ORDER BY u.is_active DESC, u.name ASC
");
$users_list->execute($params);
$users_list = $users_list->fetchAll();

$page_title = "User Management";
require_once 'includes/header.php';

$role_badges = [
    'admin'      => 'danger',
    'manager'    => 'warning',
    'supervisor' => 'info',
    'staff'      => 'secondary',
    'user'       => 'secondary',
];
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1><i class="bi bi-people"></i> User Management</h1>
        <p class="text-muted mb-0">Manage system users, roles and access scope</p>
    </div>
    <?php if ($action === 'list'): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus"></i> Add User
    </button>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>

<!-- ── Filter bar ─────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="users.php" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search name or email…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="role" class="form-select form-select-sm">
                    <option value="">All Roles</option>
                    <?php foreach ($roles as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php echo $filter_role === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filter</button>
                <a href="users.php" class="btn btn-sm btn-outline-secondary ms-1"><i class="bi bi-x"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- ── Users table ────────────────────────────────────────── -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people-fill"></i> Users (<?php echo count($users_list); ?>)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Company / BU</th>
                        <th>Language</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users_list)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No users found.</td></tr>
                <?php endif; ?>
                <?php foreach ($users_list as $i => $u): ?>
                    <tr class="<?php echo $u['is_active'] ? '' : 'table-secondary text-muted'; ?>">
                        <td class="text-muted small"><?php echo $i + 1; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($u['name']); ?></strong>
                            <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                <span class="badge bg-primary ms-1" style="font-size:.65rem;">You</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?php echo htmlspecialchars($u['email']); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $role_badges[$u['role']] ?? 'secondary'; ?>">
                                <?php echo ucfirst($u['role']); ?>
                            </span>
                        </td>
                        <td class="small">
                            <?php echo htmlspecialchars($u['company_name'] ?? '—'); ?>
                            <?php if ($u['unit_name']): ?>
                                <br><span class="text-muted"><?php echo htmlspecialchars($u['unit_name']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?php echo $u['preferred_language'] === 'id' ? '🇮🇩 ID' : '🇬🇧 EN'; ?></td>
                        <td class="text-center">
                            <?php if ($u['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a href="users.php?action=edit&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="post_action" value="toggle">
                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                <button type="submit" class="btn btn-sm py-0 px-2 <?php echo $u['is_active'] ? 'btn-outline-warning' : 'btn-outline-success'; ?>"
                                        title="<?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                        onclick="return confirm('<?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?> this user?')">
                                    <i class="bi bi-<?php echo $u['is_active'] ? 'person-x' : 'person-check'; ?>"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Add User Modal ─────────────────────────────────────── -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="users.php">
                <input type="hidden" name="post_action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. John Doe">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required placeholder="user@company.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="password" id="addPwd" class="form-control" required minlength="6" placeholder="Min. 6 characters">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePwd('addPwd','addPwdIcon')"><i id="addPwdIcon" class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required>
                                <?php foreach ($roles as $k => $v): ?>
                                    <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Company</label>
                            <select name="company_id" id="add_company" class="form-select">
                                <option value="">— All Companies —</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?php echo $c['company_id']; ?>"><?php echo htmlspecialchars($c['company_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Business Unit</label>
                            <select name="business_unit_id" id="add_bu" class="form-select">
                                <option value="">— All BUs —</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Division</label>
                            <select name="division_id" id="add_div" class="form-select">
                                <option value="">— All Divisions —</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Language</label>
                            <select name="preferred_language" class="form-select">
                                <?php foreach ($langs as $k => $v): ?>
                                    <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php elseif ($action === 'edit' && $edit_user): ?>

<!-- ── Edit User Form ─────────────────────────────────────── -->
<div class="card" style="max-width:720px;">
    <div class="card-header"><i class="bi bi-pencil-square"></i> Edit User — <?php echo htmlspecialchars($edit_user['name']); ?></div>
    <div class="card-body">
        <form method="POST" action="users.php">
            <input type="hidden" name="post_action" value="edit">
            <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($edit_user['name']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($edit_user['email']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">New Password</label>
                    <div class="input-group">
                        <input type="password" name="new_password" id="editPwd" class="form-control" minlength="6" placeholder="Leave blank to keep current">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePwd('editPwd','editPwdIcon')"><i id="editPwdIcon" class="bi bi-eye"></i></button>
                    </div>
                    <div class="form-text">Leave blank to keep the existing password.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                    <select name="role" class="form-select" required <?php echo $edit_user['id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                        <?php foreach ($roles as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo $edit_user['role'] === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($edit_user['id'] == $_SESSION['user_id']): ?>
                        <input type="hidden" name="role" value="<?php echo $edit_user['role']; ?>">
                        <div class="form-text text-muted">You cannot change your own role.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Company</label>
                    <select name="company_id" id="edit_company" class="form-select">
                        <option value="">— All Companies —</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?php echo $c['company_id']; ?>" <?php echo $edit_user['company_id'] == $c['company_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Business Unit</label>
                    <select name="business_unit_id" id="edit_bu" class="form-select">
                        <option value="">— All BUs —</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Division</label>
                    <select name="division_id" id="edit_div" class="form-select">
                        <option value="">— All Divisions —</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Language</label>
                    <select name="preferred_language" class="form-select">
                        <?php foreach ($langs as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo $edit_user['preferred_language'] === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" role="switch"
                               <?php echo $edit_user['is_active'] ? 'checked' : ''; ?>
                               <?php echo $edit_user['id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                        <label class="form-check-label fw-semibold" for="isActive">Account Active</label>
                        <?php if ($edit_user['id'] == $_SESSION['user_id']): ?>
                            <input type="hidden" name="is_active" value="1">
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Changes</button>
                <a href="users.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<script>
// ── Password show/hide ───────────────────────────────────────
function togglePwd(inputId, iconId) {
    var i  = document.getElementById(inputId);
    var ic = document.getElementById(iconId);
    var show = i.type === 'password';
    i.type     = show ? 'text' : 'password';
    ic.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
}

// ── Cascade data from PHP ────────────────────────────────────
var ALL_BUS  = <?php echo $bus_json; ?>;
var ALL_DIVS = <?php echo $div_json; ?>;

// ── Core cascade function ────────────────────────────────────
// prefix: 'add' or 'edit'
// preselect: { bu: id, div: id } — values to re-select after rebuild
function cascadeScope(prefix, preselect) {
    preselect = preselect || {};
    var cSel  = document.getElementById(prefix + '_company');
    var buSel = document.getElementById(prefix + '_bu');
    var dSel  = document.getElementById(prefix + '_div');
    if (!cSel || !buSel || !dSel) return;

    var cId  = parseInt(cSel.value)  || 0;
    var buId = parseInt(buSel.value) || 0;

    // ── Rebuild BU list ──────────────────────────────────────
    var filteredBUs = cId ? ALL_BUS.filter(function(b){ return b.company_id === cId; }) : ALL_BUS;
    buSel.innerHTML = '<option value="">— All BUs —</option>';
    filteredBUs.forEach(function(b) {
        var opt = document.createElement('option');
        opt.value = b.id;
        opt.textContent = b.name;
        if (b.id === (preselect.bu || buId)) opt.selected = true;
        buSel.appendChild(opt);
    });

    // ── Rebuild Division list ────────────────────────────────
    cascadeDivisions(prefix, preselect.div || 0);
}

function cascadeDivisions(prefix, preselectDiv) {
    var buSel = document.getElementById(prefix + '_bu');
    var dSel  = document.getElementById(prefix + '_div');
    if (!buSel || !dSel) return;

    var buId = parseInt(buSel.value) || 0;
    var filteredDivs = buId ? ALL_DIVS.filter(function(d){ return d.bu_id === buId; }) : ALL_DIVS;

    dSel.innerHTML = '<option value="">— All Divisions —</option>';
    filteredDivs.forEach(function(d) {
        var opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.name;
        if (d.id === preselectDiv) opt.selected = true;
        dSel.appendChild(opt);
    });
}

// ── Wire ADD form ────────────────────────────────────────────
(function() {
    var cSel = document.getElementById('add_company');
    var bSel = document.getElementById('add_bu');
    if (!cSel) return;
    cascadeScope('add', {});   // initial populate with all
    cSel.addEventListener('change', function() { cascadeScope('add', {}); });
    bSel.addEventListener('change', function() { cascadeDivisions('add', 0); });
})();

// ── Wire EDIT form — pre-select saved values ─────────────────
(function() {
    var cSel = document.getElementById('edit_company');
    if (!cSel) return;
    var savedBU  = <?php echo $edit_user ? (int)$edit_user['business_unit_id'] : 0; ?>;
    var savedDiv = <?php echo $edit_user ? (int)$edit_user['division_id']      : 0; ?>;
    cascadeScope('edit', { bu: savedBU, div: savedDiv });
    cSel.addEventListener('change', function() { cascadeScope('edit', {}); });
    document.getElementById('edit_bu').addEventListener('change', function() { cascadeDivisions('edit', 0); });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
