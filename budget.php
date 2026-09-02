<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

$page_title = "Budget Management";
require_once 'includes/header.php';

// Get filters
$year = get('year', date('Y'));
$company_filter = get('company_id', '');
$division_filter = get('division_id', '');
$budget_type = get('budget_type', 'all');
$search = get('search', '');
$action = get('action', '');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'create_budget') {
        try {
            $stmt = $db->prepare("
                INSERT INTO budgets (
                    budget_year, company_id, business_unit_id, division_id, block_id,
                    budget_type, category, description,
                    planned_amount, currency, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                post('budget_year'),
                post('company_id') ?: null,
                post('business_unit_id') ?: null,
                post('division_id') ?: null,
                post('block_id') ?: null,
                post('budget_type'),
                post('category'),
                post('description'),
                post('planned_amount'),
                post('currency', 'IDR'),
                post('notes'),
                'admin'
            ]);
            $success_message = "Budget created successfully!";
        } catch (PDOException $e) {
            $error_message = "Error creating budget: " . $e->getMessage();
        }
    } elseif ($action === 'update_budget') {
        try {
            $actual  = (float) post('actual_amount');
            $planned = (float) post('planned_amount');
            $stmt = $db->prepare("
                UPDATE budgets SET
                    company_id = ?,
                    business_unit_id = ?,
                    division_id = ?,
                    budget_type = ?,
                    category = ?,
                    description = ?,
                    planned_amount = ?,
                    actual_amount = ?,
                    variance = ? - ?,
                    variance_percentage = CASE WHEN ? > 0
                        THEN ((? - ?) / ? * 100)
                        ELSE 0 END,
                    notes = ?,
                    updated_by = ?
                WHERE budget_id = ?
            ");
            $stmt->execute([
                post('company_id') ?: null,
                post('business_unit_id') ?: null,
                post('division_id') ?: null,
                post('budget_type'),
                post('category'),
                post('description'),
                $planned,
                $actual,
                $planned, $actual,
                $planned, $planned, $actual, $planned,
                post('notes'),
                'admin',
                post('budget_id'),
            ]);
            $success_message = "Budget updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating budget: " . $e->getMessage();
        }
    }
}

// ── Session-based filter (same pattern as companies/business_units/divisions) ──
// agro has no auth.php — check role directly from session
$is_admin = (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin');

// Resolve session scope (null for admins = no restriction)
$sess_company_id = (!$is_admin && !empty($_SESSION['company_id']))       ? (int)$_SESSION['company_id']       : null;
$sess_bu_id      = (!$is_admin && !empty($_SESSION['business_unit_id'])) ? (int)$_SESSION['business_unit_id'] : null;
$sess_div_id     = (!$is_admin && !empty($_SESSION['division_id']))      ? (int)$_SESSION['division_id']      : null;

// Force URL filters to session scope for non-admins
if ($sess_company_id) $company_filter  = $sess_company_id;
if ($sess_div_id)     $division_filter = $sess_div_id;

// Fetch companies (scoped to session company)
$companies_sql    = "SELECT company_id, company_code, company_name FROM companies WHERE 1=1";
$companies_params = [];
if ($sess_company_id) {
    $companies_sql    .= " AND company_id = ?";
    $companies_params[] = $sess_company_id;
}
$companies_sql .= " ORDER BY company_code";
$stmt = $db->prepare($companies_sql);
$stmt->execute($companies_params);
$companies = $stmt->fetchAll();

// Fetch divisions (scoped to session, further filtered by company dropdown)
$divisions_sql    = "SELECT d.division_id, d.division_code, d.division_name
                     FROM divisions d
                     INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
                     WHERE 1=1";
$div_filter_params = [];
if ($sess_company_id) {
    $divisions_sql     .= " AND bu.company_id = ?";
    $div_filter_params[] = $sess_company_id;
} elseif ($company_filter) {
    $divisions_sql     .= " AND bu.company_id = ?";
    $div_filter_params[] = $company_filter;
}
if ($sess_bu_id) {
    $divisions_sql     .= " AND bu.business_unit_id = ?";
    $div_filter_params[] = $sess_bu_id;
}
if ($sess_div_id) {
    $divisions_sql     .= " AND d.division_id = ?";
    $div_filter_params[] = $sess_div_id;
}
$divisions_sql .= " ORDER BY d.division_code";
$stmt = $db->prepare($divisions_sql);
$stmt->execute($div_filter_params);
$divisions = $stmt->fetchAll();

// Build shared budget WHERE + params
// budgets table now has company_id, business_unit_id, division_id directly
$budget_where       = "";
$budget_base_params = [$year];

if ($sess_company_id) {
    $budget_where        .= " AND b.company_id = ?";
    $budget_base_params[] = $sess_company_id;
} elseif ($company_filter) {
    $budget_where        .= " AND b.company_id = ?";
    $budget_base_params[] = $company_filter;
}
if ($sess_bu_id) {
    $budget_where        .= " AND b.business_unit_id = ?";
    $budget_base_params[] = $sess_bu_id;
}
if ($sess_div_id) {
    $budget_where        .= " AND b.division_id = ?";
    $budget_base_params[] = $sess_div_id;
} elseif ($division_filter) {
    $budget_where        .= " AND b.division_id = ?";
    $budget_base_params[] = $division_filter;
}

// Fetch budget summary (scoped)
$summary_sql = "
    SELECT
        b.budget_type,
        COUNT(*) as budget_count,
        SUM(b.planned_amount) as total_planned,
        SUM(b.actual_amount) as total_actual,
        SUM(b.variance) as total_variance,
        AVG(b.variance_percentage) as avg_variance_pct
    FROM budgets b
    WHERE b.budget_year = ? $budget_where
    GROUP BY b.budget_type ORDER BY b.budget_type
";
$stmt = $db->prepare($summary_sql);
$stmt->execute($budget_base_params);
$budget_summary = $stmt->fetchAll();

// Fetch detailed budgets (scoped)
$budgets_sql = "
    SELECT
        b.*,
        c.company_name,
        d.division_name,
        bl.block_code
    FROM budgets b
    LEFT JOIN companies c ON b.company_id  = c.company_id
    LEFT JOIN divisions d ON b.division_id = d.division_id
    LEFT JOIN blocks bl   ON b.block_id    = bl.block_id
    WHERE b.budget_year = ? $budget_where
";
$budget_params = $budget_base_params;

if ($budget_type !== 'all') {
    $budgets_sql    .= " AND b.budget_type = ?";
    $budget_params[] = $budget_type;
}
if ($search) {
    $budgets_sql .= " AND (b.category LIKE ? OR b.description LIKE ? OR c.company_name LIKE ? OR d.division_name LIKE ?)";
    $search_term  = "%$search%";
    $budget_params[] = $search_term;
    $budget_params[] = $search_term;
    $budget_params[] = $search_term;
    $budget_params[] = $search_term;
}
$budgets_sql .= " ORDER BY b.budget_type, b.category, b.created_at DESC";

$stmt = $db->prepare($budgets_sql);
$stmt->execute($budget_params);
$budgets = $stmt->fetchAll();

// Calculate totals
$total_planned = array_sum(array_column($budgets, 'planned_amount'));
$total_actual = array_sum(array_column($budgets, 'actual_amount'));
$total_variance = $total_planned - $total_actual;
$variance_pct = $total_planned > 0 ? (($total_variance / $total_planned) * 100) : 0;
?>

<div class="container-fluid mt-4">
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 style="color: #166c82;"><i class="bi bi-cash-stack" style="color: #166c82;"></i> Budget Management</h1>
                <p class="text-muted">Plan and track operational and activity budgets across divisions</p>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-custom-budget" data-bs-toggle="modal" data-bs-target="#createBudgetModal">
                    <i class="bi bi-plus-circle"></i> Create New Budget
                </button>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header text-white" style="background-color: #166c82;">
                    <h5 class="mb-0"><i class="bi bi-funnel"></i> Filters - Budget Year <?= $year ?></h5>
                </div>
                <div class="card-body">
                    <!-- Filters -->
                    <form method="GET" class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" placeholder="Search category, description, company..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Year</label>
                            <select name="year" class="form-select">
                                <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Company</label>
                            <select name="company_id" class="form-select">
                                <option value="">All Companies</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?= $company['company_id'] ?>"
                                        <?= $company['company_id'] == $company_filter ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($company['company_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Division</label>
                            <select name="division_id" class="form-select">
                                <option value="">All Divisions</option>
                                <?php foreach ($divisions as $division): ?>
                                    <option value="<?= $division['division_id'] ?>"
                                        <?= $division['division_id'] == $division_filter ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($division['division_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Budget Type</label>
                            <select name="budget_type" class="form-select">
                                <option value="all">All Types</option>
                                <option value="operational" <?= $budget_type == 'operational' ? 'selected' : '' ?>>Operational</option>
                                <option value="capital" <?= $budget_type == 'capital' ? 'selected' : '' ?>>Capital</option>
                                <option value="maintenance" <?= $budget_type == 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                <option value="development" <?= $budget_type == 'development' ? 'selected' : '' ?>>Development</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-custom-budget w-100"><i class="bi bi-search"></i> Search</button>
                        </div>
                    </form>

                    <!-- Budget Summary Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted">Total Planned</h6>
                                    <h4 style="color: #166c82;">Rp <?= number_format($total_planned, 0, ',', '.') ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted">Total Actual</h6>
                                    <h4 style="color: #166c82;">Rp <?= number_format($total_actual, 0, ',', '.') ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted">Variance</h6>
                                    <h4 class="<?= $total_variance >= 0 ? '' : 'text-danger' ?>" <?= $total_variance >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                        Rp <?= number_format($total_variance, 0, ',', '.') ?>
                                    </h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted">Variance %</h6>
                                    <h4 class="<?= $variance_pct >= 0 ? '' : 'text-danger' ?>" <?= $variance_pct >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                        <?= number_format($variance_pct, 2) ?>%
                                    </h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Budget Summary by Type -->
                    <?php if (!empty($budget_summary)): ?>
                    <div class="table-responsive mb-4">
                        <h6>Budget Summary by Type</h6>
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Budget Type</th>
                                    <th class="text-end">Count</th>
                                    <th class="text-end">Planned</th>
                                    <th class="text-end">Actual</th>
                                    <th class="text-end">Variance</th>
                                    <th class="text-end">Variance %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($budget_summary as $summary): ?>
                                <tr>
                                    <td><strong><?= ucfirst($summary['budget_type']) ?></strong></td>
                                    <td class="text-end"><?= $summary['budget_count'] ?></td>
                                    <td class="text-end">Rp <?= number_format($summary['total_planned'], 0, ',', '.') ?></td>
                                    <td class="text-end">Rp <?= number_format($summary['total_actual'], 0, ',', '.') ?></td>
                                    <td class="text-end <?= $summary['total_variance'] >= 0 ? '' : 'text-danger' ?>" <?= $summary['total_variance'] >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                        Rp <?= number_format($summary['total_variance'], 0, ',', '.') ?>
                                    </td>
                                    <td class="text-end <?= $summary['avg_variance_pct'] >= 0 ? '' : 'text-danger' ?>" <?= $summary['avg_variance_pct'] >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                        <?= number_format($summary['avg_variance_pct'], 2) ?>%
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <!-- Detailed Budget List -->
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header text-white" style="background-color: #166c82;">
            <i class="bi bi-list"></i> Budget Details (<?= count($budgets) ?> records)
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Company</th>
                            <th>Division</th>
                            <th>Block</th>
                            <th class="text-end">Planned</th>
                            <th class="text-end">Actual</th>
                            <th class="text-end">Variance</th>
                            <th class="text-end">%</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                            <tbody>
                                <?php if (empty($budgets)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">No budget records found</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($budgets as $budget): ?>
                                    <tr>
                                        <td><span class="badge bg-info"><?= ucfirst($budget['budget_type']) ?></span></td>
                                        <td><?= htmlspecialchars($budget['category']) ?></td>
                                        <td><?= htmlspecialchars($budget['description']) ?></td>
                                        <td><?= htmlspecialchars($budget['company_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($budget['division_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($budget['block_code'] ?? '-') ?></td>
                                        <td class="text-end">Rp <?= number_format($budget['planned_amount'], 0, ',', '.') ?></td>
                                        <td class="text-end">Rp <?= number_format($budget['actual_amount'], 0, ',', '.') ?></td>
                                        <td class="text-end <?= $budget['variance'] >= 0 ? '' : 'text-danger' ?>" <?= $budget['variance'] >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                            Rp <?= number_format($budget['variance'], 0, ',', '.') ?>
                                        </td>
                                        <td class="text-end <?= $budget['variance_percentage'] >= 0 ? '' : 'text-danger' ?>" <?= $budget['variance_percentage'] >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                            <?= number_format($budget['variance_percentage'], 1) ?>%
                                        </td>
                                        <td>
                                            <button class="btn btn-sm" style="background-color: #166c82; color: white;" onclick="editBudget(<?= htmlspecialchars(json_encode($budget)) ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Budget Modal -->
<div class="modal fade" id="createBudgetModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Budget</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_budget">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Budget Year *</label>
                            <input type="number" name="budget_year" class="form-control" value="<?= $year ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Budget Type *</label>
                            <select name="budget_type" class="form-select" required>
                                <option value="operational">Operational</option>
                                <option value="capital">Capital</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="development">Development</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category *</label>
                            <select name="category" class="form-select" required>
                                <option value="">-- Select Category --</option>
                                <optgroup label="Operational">
                                    <option value="New Planting">New Planting</option>
                                    <option value="Replanting">Replanting</option>
                                    <option value="Fertilizer">Fertilizer</option>
                                    <option value="Pesticide">Pesticide</option>
                                    <option value="Labor">Labor</option>
                                    <option value="Fuel">Fuel</option>
                                    <option value="General Operations">General Operations</option>
                                </optgroup>
                                <optgroup label="Capital / Maintenance">
                                    <option value="Equipment">Equipment</option>
                                    <option value="Infrastructure">Infrastructure</option>
                                    <option value="Maintenance">Maintenance</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Planned Amount *</label>
                            <input type="number" name="planned_amount" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description *</label>
                            <input type="text" name="description" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Company</label>
                            <select name="company_id" class="form-select">
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?= $company['company_id'] ?>">
                                        <?= htmlspecialchars($company['company_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Division</label>
                            <select name="division_id" class="form-select">
                                <option value="">Select Division</option>
                                <?php foreach ($divisions as $division): ?>
                                    <option value="<?= $division['division_id'] ?>">
                                        <?= htmlspecialchars($division['division_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Block (Optional)</label>
                            <input type="number" name="block_id" class="form-control" placeholder="Block ID">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background-color: #166c82; color: white;">Create Budget</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Budget Modal -->
<div class="modal fade" id="editBudgetModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header text-white" style="background-color:#166c82">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Update Budget</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_budget">
                    <input type="hidden" name="budget_id" id="edit_budget_id">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Budget Type <span class="text-danger">*</span></label>
                            <select name="budget_type" id="edit_budget_type" class="form-select" required>
                                <option value="operational">Operational</option>
                                <option value="capital">Capital</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="development">Development</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category" id="edit_category" class="form-select" required>
                                <option value="">-- Select Category --</option>
                                <optgroup label="Operational">
                                    <option value="New Planting">New Planting</option>
                                    <option value="Replanting">Replanting</option>
                                    <option value="Fertilizer">Fertilizer</option>
                                    <option value="Pesticide">Pesticide</option>
                                    <option value="Labor">Labor</option>
                                    <option value="Fuel">Fuel</option>
                                    <option value="General Operations">General Operations</option>
                                </optgroup>
                                <optgroup label="Capital / Maintenance">
                                    <option value="Equipment">Equipment</option>
                                    <option value="Infrastructure">Infrastructure</option>
                                    <option value="Maintenance">Maintenance</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" name="description" id="edit_description" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Planned Amount <span class="text-danger">*</span></label>
                            <input type="number" name="planned_amount" id="edit_planned_amount" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Actual Amount</label>
                            <input type="number" name="actual_amount" id="edit_actual_amount" class="form-control" step="0.01">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background-color: #166c82; color: white;">
                        <i class="bi bi-save"></i> Update Budget
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editBudget(budget) {
    document.getElementById('edit_budget_id').value    = budget.budget_id;
    document.getElementById('edit_budget_type').value  = budget.budget_type;
    document.getElementById('edit_description').value  = budget.description;
    document.getElementById('edit_planned_amount').value = budget.planned_amount;
    document.getElementById('edit_actual_amount').value  = budget.actual_amount || 0;
    document.getElementById('edit_notes').value        = budget.notes || '';

    // Set category — works for both existing values and new dropdown options
    const catSel = document.getElementById('edit_category');
    catSel.value = budget.category;
    // If the saved value isn't in the list, add it as a temporary option
    if (catSel.value !== budget.category) {
        const opt = new Option(budget.category, budget.category, true, true);
        catSel.add(opt);
    }

    new bootstrap.Modal(document.getElementById('editBudgetModal')).show();
}
</script>
<style>
/* Custom button styles for Budget Management */
.btn-custom-budget {
    background-color: #166c82;
    border-color: #166c82;
    color: white;
}

.btn-custom-budget:hover {
    background-color: #1a7d9a;
    border-color: #1a7d9a;
    color: white;
}

.btn-custom-budget:focus,
.btn-custom-budget:active {
    background-color: #145a6d;
    border-color: #145a6d;
    color: white;
}
</style>


<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
