<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$pageTitle = 'Account based Budget Management';
$db = getDB();

// ── Budget type config ────────────────────────────────────────────────────────
$budget_types = [
    'capital'      => ['label' => 'Capital',       'icon' => 'bi-building',        'color' => 'primary'],
    'overhead'     => ['label' => 'Overhead',      'icon' => 'bi-people',          'color' => 'info'],
    'finance'      => ['label' => 'Finance',       'icon' => 'bi-bank',            'color' => 'warning'],
    'tax'          => ['label' => 'Tax',           'icon' => 'bi-receipt',         'color' => 'danger'],
    'general_admin'=> ['label' => 'General & Admin','icon' => 'bi-briefcase',      'color' => 'secondary'],
    'project'      => ['label' => 'Project',       'icon' => 'bi-kanban',          'color' => 'success'],
    'other'        => ['label' => 'Other',         'icon' => 'bi-three-dots',      'color' => 'dark'],
];

// GL defaults by asset category (for JS auto-fill)
$gl_defaults_json = json_encode([
    'Equipment'      => ['asset' => '1530', 'depr' => '6320', 'pay' => '2110'],
    'Vehicle'        => ['asset' => '1540', 'depr' => '6330', 'pay' => '2110'],
    'Building'       => ['asset' => '1520', 'depr' => '6310', 'pay' => '2110'],
    'Infrastructure' => ['asset' => '1524', 'depr' => '6310', 'pay' => '2110'],
    'Technology'     => ['asset' => '1530', 'depr' => '6320', 'pay' => '2110'],
    'Replanting'     => ['asset' => '1552', 'depr' => '6340', 'pay' => '2110'],
    'Other'          => ['asset' => '1530', 'depr' => '6320', 'pay' => '2110'],
]);

// GL defaults by budget type (for JS auto-fill)
$gl_type_defaults_json = json_encode([
    'capital'       => ['asset' => '1530', 'depr' => '6320', 'pay' => '2110'],
    'overhead'      => ['asset' => '6100', 'depr' => '',     'pay' => '2121'],
    'finance'       => ['asset' => '7210', 'depr' => '',     'pay' => '2122'],
    'tax'           => ['asset' => '2141', 'depr' => '',     'pay' => '2141'],
    'general_admin' => ['asset' => '6200', 'depr' => '',     'pay' => '2110'],
    'project'       => ['asset' => '1552', 'depr' => '',     'pay' => '2110'],
    'other'         => ['asset' => '6000', 'depr' => '',     'pay' => '2110'],
]);

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'add') {
        $stmt = $db->prepare("
            INSERT INTO account_budget_items (
                budget_year, budget_type, company_id, division_id, block_id,
                item_code, item_name, description, asset_category, asset_subcategory,
                asset_gl_id, depreciation_gl_id, payment_gl_id,
                quantity, unit_price, installation_cost, other_costs,
                useful_life_years, depreciation_method, salvage_value,
                planned_purchase_date, priority, status, business_case, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            post('budget_year'),
            post('budget_type'),
            post('company_id') ?: null,
            post('division_id') ?: null,
            post('block_id') ?: null,
            post('item_code'),
            post('item_name'),
            post('description'),
            post('asset_category') ?: null,
            post('asset_subcategory') ?: null,
            post('asset_gl_id') ?: null,
            post('depreciation_gl_id') ?: null,
            post('payment_gl_id') ?: null,
            post('quantity') ?: 1,
            post('unit_price'),
            post('installation_cost') ?: 0,
            post('other_costs') ?: 0,
            post('useful_life_years') ?: null,
            post('depreciation_method') ?: 'straight_line',
            post('salvage_value') ?: 0,
            post('planned_purchase_date') ?: null,
            post('priority'),
            post('status'),
            post('business_case'),
            'admin'
        ]);
        $success = "Budget item added successfully!";
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM account_budget_items WHERE item_id = ?")
           ->execute([post('item_id')]);
        $success = "Budget item deleted.";
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_year     = get('year', date('Y'));
$filter_type     = get('budget_type', 'all');
$filter_status   = get('status', 'all');
$filter_category = get('category', 'all');

// ── Reference data ────────────────────────────────────────────────────────────
$companies   = $db->query("SELECT company_id, company_name FROM companies ORDER BY company_name")->fetchAll();
$divisions   = $db->query("SELECT division_id, division_name FROM divisions ORDER BY division_name")->fetchAll();
$blocks      = $db->query("SELECT block_id, block_code FROM blocks ORDER BY block_code")->fetchAll();
$gl_accounts = $db->query("
    SELECT id, account_code, account_name, account_type
    FROM general_ledger_accounts WHERE is_active = 1 ORDER BY account_code
")->fetchAll();

// ── Build main query ──────────────────────────────────────────────────────────
$where  = ["abi.budget_year = ?"];
$params = [$filter_year];

if ($filter_type !== 'all') {
    $where[] = "abi.budget_type = ?";
    $params[] = $filter_type;
}
if ($filter_status !== 'all') {
    $where[] = "abi.status = ?";
    $params[] = $filter_status;
}
if ($filter_category !== 'all') {
    $where[] = "abi.asset_category = ?";
    $params[] = $filter_category;
}
$whereClause = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT
        abi.*,
        c.company_name,
        d.division_name,
        b.block_code,
        ga_a.account_code AS asset_gl_code,
        ga_a.account_name AS asset_gl_name,
        ga_d.account_code AS depr_gl_code,
        ga_d.account_name AS depr_gl_name,
        ga_p.account_code AS pay_gl_code,
        ga_p.account_name AS pay_gl_name
    FROM account_budget_items abi
    LEFT JOIN companies c                    ON abi.company_id        = c.company_id
    LEFT JOIN divisions d                    ON abi.division_id       = d.division_id
    LEFT JOIN blocks b                       ON abi.block_id          = b.block_id
    LEFT JOIN general_ledger_accounts ga_a   ON abi.asset_gl_id       = ga_a.id
    LEFT JOIN general_ledger_accounts ga_d   ON abi.depreciation_gl_id= ga_d.id
    LEFT JOIN general_ledger_accounts ga_p   ON abi.payment_gl_id     = ga_p.id
    WHERE $whereClause
    ORDER BY abi.budget_type, abi.planned_purchase_date DESC, abi.item_code
");
$stmt->execute($params);
$items = $stmt->fetchAll();

// ── Summary by type ───────────────────────────────────────────────────────────
$summary_stmt = $db->prepare("
    SELECT budget_type,
           COUNT(*)                                                     AS total_items,
           SUM(total_cost)                                              AS total_amount,
           SUM(COALESCE(total_cost,0) + COALESCE(installation_cost,0) + COALESCE(other_costs,0)) AS total_investment,
           SUM(CASE WHEN status IN ('approved','active','purchased','in_use') THEN total_cost ELSE 0 END) AS approved_amount
    FROM account_budget_items
    WHERE budget_year = ?
    GROUP BY budget_type
");
$summary_stmt->execute([$filter_year]);
$summary_by_type = [];
foreach ($summary_stmt->fetchAll() as $row) {
    $summary_by_type[$row['budget_type']] = $row;
}

$grand_total = array_sum(array_column($summary_by_type, 'total_amount'));

include 'includes/header.php';
?>

<div class="container-fluid mt-4">

    <!-- ── Header ── -->
    <div class="row mb-3 align-items-center">
        <div class="col-md-8">
            <h2><i class="bi bi-journal-bookmark-fill"></i> Account based Budget</h2>
            <p class="text-muted mb-0">Non-activity budget items — Capital, Overhead, Finance, Tax, Admin, Projects</p>
        </div>
        <div class="col-md-4 text-end">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="bi bi-plus-circle"></i> Add Budget Item
            </button>
        </div>
    </div>

    <?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= $success ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ── Summary cards by type ── -->
    <div class="row mb-4 g-2">
        <?php foreach ($budget_types as $type => $cfg): ?>
        <?php $s = $summary_by_type[$type] ?? null; ?>
        <div class="col-md-3 col-6">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small text-uppercase fw-bold"><?= $cfg['label'] ?></div>
                            <div class="fs-6 fw-bold mt-1">
                                Rp <?= $s ? number_format($s['total_amount'] / 1000000, 1) . 'M' : '0' ?>
                            </div>
                            <div class="text-muted" style="font-size:0.75rem"><?= $s ? $s['total_items'] . ' items' : '—' ?></div>
                        </div>
                        <i class="bi <?= $cfg['icon'] ?> text-<?= $cfg['color'] ?> fs-4 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="col-md-3 col-6">
            <div class="card h-100 border-0 bg-dark text-white shadow-sm">
                <div class="card-body p-3">
                    <div class="text-white-50 small text-uppercase fw-bold">Grand Total</div>
                    <div class="fs-5 fw-bold mt-1">Rp <?= number_format($grand_total / 1000000, 1) ?>M</div>
                    <div class="text-white-50" style="font-size:0.75rem"><?= count($items) ?> items shown</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Filters ── -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Year</label>
                    <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php for ($y = date('Y') + 1; $y >= date('Y') - 4; $y--): ?>
                        <option value="<?= $y ?>" <?= $y == $filter_year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Budget Type</label>
                    <select name="budget_type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all">All Types</option>
                        <?php foreach ($budget_types as $type => $cfg): ?>
                        <option value="<?= $type ?>" <?= $filter_type === $type ? 'selected' : '' ?>><?= $cfg['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all">All Status</option>
                        <option value="proposed"  <?= $filter_status == 'proposed'  ? 'selected' : '' ?>>Proposed</option>
                        <option value="approved"  <?= $filter_status == 'approved'  ? 'selected' : '' ?>>Approved</option>
                        <option value="active"    <?= $filter_status == 'active'    ? 'selected' : '' ?>>Active</option>
                        <option value="rejected"  <?= $filter_status == 'rejected'  ? 'selected' : '' ?>>Rejected</option>
                        <option value="purchased" <?= $filter_status == 'purchased' ? 'selected' : '' ?>>Purchased</option>
                        <option value="in_use"    <?= $filter_status == 'in_use'    ? 'selected' : '' ?>>In Use</option>
                        <option value="closed"    <?= $filter_status == 'closed'    ? 'selected' : '' ?>>Closed</option>
                        <option value="disposed"  <?= $filter_status == 'disposed'  ? 'selected' : '' ?>>Disposed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <a href="account_based_budget.php" class="btn btn-sm btn-outline-secondary w-100">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Items Table ── -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Item Name</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>GL Accounts</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Annual Depr.</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No budget items found for the selected filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $item):
                            $cfg = $budget_types[$item['budget_type']] ?? $budget_types['other'];
                            $statusColors = [
                                'proposed' => 'secondary', 'approved' => 'info',
                                'active'   => 'success',   'rejected' => 'danger',
                                'purchased'=> 'primary',   'in_use'   => 'success',
                                'closed'   => 'dark',      'disposed' => 'dark'
                            ];
                        ?>
                        <tr>
                            <td><code style="font-size:0.78rem"><?= htmlspecialchars($item['item_code']) ?></code></td>
                            <td>
                                <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                                <?php if ($item['asset_subcategory']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($item['asset_subcategory']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $cfg['color'] ?>" style="font-size:0.7rem">
                                    <i class="bi <?= $cfg['icon'] ?>"></i> <?= $cfg['label'] ?>
                                </span>
                            </td>
                            <td><small><?= htmlspecialchars($item['asset_category'] ?? '—') ?></small></td>
                            <td style="min-width:160px">
                                <?php if ($item['asset_gl_code']): ?>
                                    <small class="d-block text-primary"><i class="bi bi-arrow-up-right-circle"></i> <?= $item['asset_gl_code'] ?> <?= htmlspecialchars($item['asset_gl_name']) ?></small>
                                <?php endif; ?>
                                <?php if ($item['depr_gl_code']): ?>
                                    <small class="d-block text-danger"><i class="bi bi-arrow-down-circle"></i> <?= $item['depr_gl_code'] ?> <?= htmlspecialchars($item['depr_gl_name']) ?></small>
                                <?php endif; ?>
                                <?php if ($item['pay_gl_code']): ?>
                                    <small class="d-block text-success"><i class="bi bi-credit-card"></i> <?= $item['pay_gl_code'] ?> <?= htmlspecialchars($item['pay_gl_name']) ?></small>
                                <?php endif; ?>
                                <?php if (!$item['asset_gl_code'] && !$item['pay_gl_code']): ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <strong>Rp <?= number_format($item['total_cost']) ?></strong>
                                <?php if ($item['installation_cost'] + $item['other_costs'] > 0): ?>
                                    <br><small class="text-muted">+install/other</small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (!empty($item['annual_depreciation']) && $item['annual_depreciation'] > 0): ?>
                                    <small>Rp <?= number_format($item['annual_depreciation']) ?></small>
                                <?php else: ?>
                                    <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td><small><?= $item['planned_purchase_date'] ? date('d M Y', strtotime($item['planned_purchase_date'])) : '—' ?></small></td>
                            <td>
                                <span class="badge bg-<?= $statusColors[$item['status']] ?? 'secondary' ?>" style="font-size:0.7rem">
                                    <?= ucfirst(str_replace('_',' ',$item['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-warning py-0" onclick="editItem(<?= $item['item_id'] ?>)" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-danger py-0" onclick="deleteItem(<?= $item['item_id'] ?>)" title="Delete">
                                    <i class="bi bi-trash"></i>
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

<!-- ═══ Add Item Modal ═══ -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" id="addItemForm">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add Account Budget Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">

                        <!-- Row 1: Year + Type + Code + Name -->
                        <div class="col-md-2">
                            <label class="form-label">Year *</label>
                            <input type="number" name="budget_year" class="form-control" value="<?= date('Y') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Budget Type *</label>
                            <select name="budget_type" id="budgetTypeSelect" class="form-select" required onchange="onTypeChange(this)">
                                <?php foreach ($budget_types as $type => $cfg): ?>
                                <option value="<?= $type ?>"><?= $cfg['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Item Code *</label>
                            <input type="text" name="item_code" class="form-control" placeholder="e.g. CAP-2025-001" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Item Name *</label>
                            <input type="text" name="item_name" class="form-control" required>
                        </div>

                        <!-- Row 2: Description -->
                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>

                        <!-- Row 3: Org -->
                        <div class="col-md-4">
                            <label class="form-label">Company</label>
                            <select name="company_id" class="form-select">
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $c): ?>
                                <option value="<?= $c['company_id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Division</label>
                            <select name="division_id" class="form-select">
                                <option value="">Select Division</option>
                                <?php foreach ($divisions as $d): ?>
                                <option value="<?= $d['division_id'] ?>"><?= htmlspecialchars($d['division_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Block (optional)</label>
                            <select name="block_id" class="form-select">
                                <option value="">Select Block</option>
                                <?php foreach ($blocks as $b): ?>
                                <option value="<?= $b['block_id'] ?>"><?= htmlspecialchars($b['block_code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- ── Capital-only fields ── -->
                        <div id="capitalFields">
                            <div class="row g-3">
                                <div class="col-md-12 pt-2">
                                    <small class="text-muted fw-bold text-uppercase"><i class="bi bi-building"></i> Asset Details (Capital only)</small>
                                    <hr class="mt-1 mb-2">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Asset Category</label>
                                    <select name="asset_category" class="form-select" onchange="onCategoryChange(this)">
                                        <option value="">— select —</option>
                                        <option value="Equipment">Equipment</option>
                                        <option value="Vehicle">Vehicle</option>
                                        <option value="Infrastructure">Infrastructure</option>
                                        <option value="Building">Building</option>
                                        <option value="Technology">Technology</option>
                                        <option value="Replanting">Replanting</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Asset Subcategory</label>
                                    <input type="text" name="asset_subcategory" class="form-control" placeholder="e.g. Harvesting tools">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Planned Date</label>
                                    <input type="date" name="planned_purchase_date" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Installation Cost</label>
                                    <input type="number" name="installation_cost" class="form-control" step="0.01" value="0">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Other Costs</label>
                                    <input type="number" name="other_costs" class="form-control" step="0.01" value="0">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Useful Life (yrs)</label>
                                    <input type="number" name="useful_life_years" class="form-control" value="5">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Depreciation</label>
                                    <select name="depreciation_method" class="form-select">
                                        <option value="straight_line">Straight Line</option>
                                        <option value="declining_balance">Declining Balance</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Salvage Value</label>
                                    <input type="number" name="salvage_value" class="form-control" step="0.01" value="0">
                                </div>
                            </div>
                        </div>

                        <!-- ── Common financial fields ── -->
                        <div class="col-md-12 pt-1">
                            <small class="text-muted fw-bold text-uppercase"><i class="bi bi-cash-stack"></i> Amount</small>
                            <hr class="mt-1 mb-2">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" class="form-control" value="1" required min="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit Price *</label>
                            <input type="number" name="unit_price" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Priority *</label>
                            <select name="priority" class="form-select" required>
                                <option value="critical">Critical</option>
                                <option value="high">High</option>
                                <option value="medium" selected>Medium</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status *</label>
                            <select name="status" id="statusSelect" class="form-select" required>
                                <option value="proposed" selected>Proposed</option>
                                <option value="approved">Approved</option>
                                <option value="active">Active</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>

                        <!-- ── GL Account mapping ── -->
                        <div class="col-md-12 pt-1">
                            <small class="text-muted fw-bold text-uppercase"><i class="bi bi-journal-text"></i> GL Account Mapping</small>
                            <hr class="mt-1 mb-2">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" id="assetGlLabel">Debit GL (Asset / Expense)</label>
                            <select name="asset_gl_id" id="assetGlSelect" class="form-select form-select-sm">
                                <option value="">— select —</option>
                                <?php foreach ($gl_accounts as $gl): ?>
                                <option value="<?= $gl['id'] ?>"><?= htmlspecialchars($gl['account_code'] . ' — ' . $gl['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4" id="deprGlRow">
                            <label class="form-label">Depreciation GL (Expense)</label>
                            <select name="depreciation_gl_id" class="form-select form-select-sm">
                                <option value="">— select —</option>
                                <?php foreach ($gl_accounts as $gl): ?>
                                    <?php if (in_array($gl['account_code'], ['6310','6320','6330','6340'])): ?>
                                    <option value="<?= $gl['id'] ?>"><?= htmlspecialchars($gl['account_code'] . ' — ' . $gl['account_name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Credit GL (AP / Bank / Accrual)</label>
                            <select name="payment_gl_id" class="form-select form-select-sm">
                                <option value="">— select —</option>
                                <?php foreach ($gl_accounts as $gl): ?>
                                    <?php if (in_array($gl['account_code'], ['2110','2111','2112','2113','2120','2121','2122','1112','1113','2210'])): ?>
                                    <option value="<?= $gl['id'] ?>"><?= htmlspecialchars($gl['account_code'] . ' — ' . $gl['account_name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Business Case -->
                        <div class="col-md-12">
                            <label class="form-label">Business Case / Notes</label>
                            <textarea name="business_case" class="form-control" rows="2"></textarea>
                        </div>

                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const GL_DEFAULTS_BY_CATEGORY = <?= $gl_defaults_json ?>;
const GL_DEFAULTS_BY_TYPE     = <?= $gl_type_defaults_json ?>;

// All GL options indexed by account_code for fast lookup
const glOptions = {};
document.querySelectorAll('#addItemModal select[name]').forEach(function(){});
document.addEventListener('DOMContentLoaded', function () {
    // Index all GL selects' options by account_code
    ['asset_gl_id','depreciation_gl_id','payment_gl_id'].forEach(function(name) {
        const sel = document.querySelector('#addItemModal [name="' + name + '"]');
        if (!sel) return;
        glOptions[name] = {};
        Array.from(sel.options).forEach(function(opt) {
            const code = opt.text.split(' — ')[0].trim();
            glOptions[name][code] = opt.value;
        });
    });
});

function setGlSelect(name, code) {
    const sel = document.querySelector('#addItemModal [name="' + name + '"]');
    if (!sel || !code) return;
    const val = glOptions[name] ? glOptions[name][code] : null;
    if (val) sel.value = val;
}

function onTypeChange(sel) {
    const type = sel.value;
    const isCapital = (type === 'capital');

    // Show/hide capital-only fields
    document.getElementById('capitalFields').style.display = isCapital ? '' : 'none';
    document.getElementById('deprGlRow').style.display     = isCapital ? '' : 'none';

    // Update debit GL label
    document.getElementById('assetGlLabel').textContent = isCapital
        ? 'Debit GL (Fixed Asset)'
        : 'Debit GL (Expense Account)';

    // Update status options
    const statusSel = document.getElementById('statusSelect');
    const capitalStatuses  = ['proposed','approved','rejected','purchased','in_use','disposed'];
    const generalStatuses  = ['proposed','approved','active','rejected','closed'];
    const statuses = isCapital ? capitalStatuses : generalStatuses;
    const curVal = statusSel.value;
    statusSel.innerHTML = statuses.map(function(s) {
        const label = s.charAt(0).toUpperCase() + s.slice(1).replace('_',' ');
        return `<option value="${s}" ${s === curVal ? 'selected' : ''}>${label}</option>`;
    }).join('');

    // Auto-fill GL from type defaults (only if blank)
    const def = GL_DEFAULTS_BY_TYPE[type];
    if (def) {
        setTimeout(function() {
            const assetSel = document.querySelector('#addItemModal [name="asset_gl_id"]');
            if (assetSel && !assetSel.value) setGlSelect('asset_gl_id', def.asset);
            const paySel = document.querySelector('#addItemModal [name="payment_gl_id"]');
            if (paySel && !paySel.value) setGlSelect('payment_gl_id', def.pay);
            if (isCapital) {
                const deprSel = document.querySelector('#addItemModal [name="depreciation_gl_id"]');
                if (deprSel && !deprSel.value) setGlSelect('depreciation_gl_id', def.depr);
            }
        }, 50);
    }
}

function onCategoryChange(sel) {
    const def = GL_DEFAULTS_BY_CATEGORY[sel.value];
    if (!def) return;
    setGlSelect('asset_gl_id', def.asset);
    setGlSelect('depreciation_gl_id', def.depr);
    setGlSelect('payment_gl_id', def.pay);
}

function editItem(itemId) {
    window.location.href = 'account_based_budget_update.php?id=' + itemId;
}

function deleteItem(itemId) {
    if (confirm('Delete this budget item?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="delete">
                          <input type="hidden" name="item_id" value="${itemId}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

// Init on load
document.addEventListener('DOMContentLoaded', function () {
    const typeSel = document.getElementById('budgetTypeSelect');
    if (typeSel) onTypeChange(typeSel);
});
</script>

<?php include 'includes/footer.php'; ?>

// Powered by IBM Bob
