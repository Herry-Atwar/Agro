<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

$db = getDB();

// Session info
$session_company_id = $_SESSION['company_id'] ?? null;
$session_bu_id      = $_SESSION['business_unit_id'] ?? null;
$session_role       = $_SESSION['role'] ?? null;
$session_user       = $_SESSION['name'] ?? $_SESSION['email'] ?? null;

// Companies list
$companies = $db->query("SELECT company_id, company_name FROM companies ORDER BY company_id")->fetchAll();

// Harvest plans count per company
$hp_counts = $db->query("
    SELECT c.company_id, c.company_name, COUNT(hp.id) as total
    FROM companies c
    LEFT JOIN business_units bu ON bu.company_id = c.company_id
    LEFT JOIN divisions d ON d.business_unit_id = bu.business_unit_id
    LEFT JOIN planting_years py ON py.division_id = d.division_id
    LEFT JOIN blocks b ON b.planting_year_id = py.planting_year_id
    LEFT JOIN harvest_plans hp ON hp.block_id = b.block_id
    GROUP BY c.company_id, c.company_name
    ORDER BY c.company_id
")->fetchAll();

// Users and their company assignment
$users = $db->query("SELECT user_id, name, email, role, company_id, business_unit_id FROM users ORDER BY user_id")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Debug Harvest Plan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h4 class="mb-3 text-danger">🔍 Debug Harvest Plan — Cek Company Filter</h4>

    <!-- Session -->
    <div class="card mb-3 border-primary">
        <div class="card-header bg-primary text-white">Session User Saat Ini</div>
        <div class="card-body">
            <table class="table table-sm table-bordered mb-0">
                <tr><th>User</th><td><?= htmlspecialchars($session_user ?? '-') ?></td></tr>
                <tr><th>Role</th><td><?= htmlspecialchars($session_role ?? '-') ?></td></tr>
                <tr><th>company_id (session)</th>
                    <td>
                        <?php if ($session_company_id): ?>
                            <span class="badge bg-success"><?= $session_company_id ?></span>
                        <?php else: ?>
                            <span class="badge bg-danger">NULL — tidak ada filter company!</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><th>business_unit_id (session)</th>
                    <td>
                        <?php if ($session_bu_id): ?>
                            <span class="badge bg-success"><?= $session_bu_id ?></span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">NULL</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Harvest plans per company -->
    <div class="card mb-3 border-success">
        <div class="card-header bg-success text-white">Jumlah Harvest Plans per Perusahaan (di database)</div>
        <div class="card-body p-0">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr><th>company_id</th><th>Nama Perusahaan</th><th>Jumlah Harvest Plans</th></tr>
                </thead>
                <tbody>
                <?php foreach ($hp_counts as $row): ?>
                    <tr class="<?= $row['total'] > 0 ? 'table-success' : '' ?>">
                        <td><?= $row['company_id'] ?></td>
                        <td><?= htmlspecialchars($row['company_name']) ?></td>
                        <td><strong><?= $row['total'] ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Users -->
    <div class="card mb-3 border-warning">
        <div class="card-header bg-warning text-dark">Daftar User & Company Assignment</div>
        <div class="card-body p-0">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr><th>user_id</th><th>Nama</th><th>Email</th><th>Role</th><th>company_id</th><th>business_unit_id</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr class="<?= ($u['email'] === ($_SESSION['email'] ?? '')) ? 'table-primary fw-bold' : '' ?>">
                        <td><?= $u['user_id'] ?></td>
                        <td><?= htmlspecialchars($u['name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><?= htmlspecialchars($u['role']) ?></td>
                        <td>
                            <?php if ($u['company_id']): ?>
                                <span class="badge bg-success"><?= $u['company_id'] ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger">NULL</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['business_unit_id']): ?>
                                <span class="badge bg-info text-dark"><?= $u['business_unit_id'] ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary">NULL</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="p-2 small text-muted">* Baris biru = user yang sedang login</div>
        </div>
    </div>

    <!-- Fix link -->
    <div class="card border-info">
        <div class="card-header bg-info text-white">Quick Fix</div>
        <div class="card-body">
            <?php if (!$session_company_id): ?>
                <div class="alert alert-danger mb-2">
                    <strong>Masalah ditemukan:</strong> User yang login tidak memiliki <code>company_id</code> — 
                    itulah sebabnya tidak ada filter company dan harvest plans tidak muncul (atau muncul semua perusahaan).
                </div>
                <p>Assign company_id ke user ini melalui menu <a href="users.php">Users</a>, 
                atau update langsung di bawah:</p>
                <form method="POST">
                    <input type="hidden" name="fix_user" value="1">
                    <div class="row g-2 align-items-end">
                        <div class="col-auto">
                            <label class="form-label small mb-1">Set company_id untuk user saat ini</label>
                            <select name="fix_company_id" class="form-select form-select-sm">
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= $c['company_id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-danger">Terapkan & Refresh Session</button>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-success mb-0">
                    ✅ User sudah ter-assign ke company_id = <strong><?= $session_company_id ?></strong>. 
                    Jika harvest plans masih tidak muncul, kemungkinan data harvest_plans di database 
                    memang belum ada untuk perusahaan ini.
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
<?php
// Handle quick fix
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_user'])) {
    $fix_cid = (int)$_POST['fix_company_id'];
    $uid = $_SESSION['user_id'] ?? null;
    if ($uid && $fix_cid) {
        $db->prepare("UPDATE users SET company_id = ? WHERE user_id = ?")->execute([$fix_cid, $uid]);
        // Refresh session
        $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$uid]);
        $u = $stmt->fetch();
        if ($u) {
            $_SESSION['company_id'] = $fix_cid;
        }
        echo '<script>alert("company_id berhasil diupdate! Halaman akan di-reload."); location.href="debug_harvest.php";</script>';
    }
}
?>
</body>
</html>
