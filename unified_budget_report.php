<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Unified Budget Report";
require_once 'includes/header.php';

// ── Filters ──────────────────────────────────────────────────────────────────
$year         = (int) get('year', date('Y'));
$division_id  = get('division_id', '');
$budget_type  = get('budget_type', 'all');   // all | operational | tbm_capital | capex
$group_by     = get('group_by', 'division'); // division | gl_account | block

// ── Reference data ───────────────────────────────────────────────────────────
$divisions = $db->query("SELECT division_id, division_name FROM divisions ORDER BY division_name")->fetchAll();
$years_raw = $db->query("
    SELECT budget_year FROM activity_budget_plans
    UNION
    SELECT budget_year FROM account_budget_items
    ORDER BY budget_year DESC
")->fetchAll(PDO::FETCH_COLUMN);
$years = array_unique($years_raw);

// ── Helper: build WHERE clauses ───────────────────────────────────────────────
$div_join_abp = $division_id
    ? "AND d.division_id = " . (int)$division_id
    : "";
$div_join_cap = $division_id
    ? "AND d.division_id = " . (int)$division_id
    : "";

// ── Query 1: Operational Budget (TM blocks) ───────────────────────────────────
// activity_budget_plans where block status = TM → Opex
$sql_opex = "
    SELECT
        'operational'                   AS budget_type,
        'Opex'                          AS budget_type_label,
        d.division_id,
        d.division_name,
        b.block_id,
        b.block_code,
        b.block_name,
        b.status                        AS block_status,
        ag.group_name                   AS activity_group,
        a.activity_name,
        COALESCE(gla.account_code, '—') AS gl_account_code,
        COALESCE(gla.account_name, 'Not Mapped') AS gl_account_name,
        COALESCE(gla.account_type, '')  AS gl_account_type,
        abp.total_annual_cost           AS budget_amount,
        abp.plan_id                     AS source_id,
        abp.status                      AS plan_status
    FROM activity_budget_plans abp
    INNER JOIN blocks b              ON abp.block_id      = b.block_id
    INNER JOIN planting_years py     ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d           ON py.division_id    = d.division_id
    INNER JOIN activities a          ON abp.activity_id   = a.id
    INNER JOIN activity_groups ag    ON a.activity_group_id = ag.id
    -- GL account via activity_group + block_status mapping
    LEFT JOIN activity_gl_mapping agm
        ON agm.activity_id = a.id
        AND agm.block_status = b.status
        AND agm.is_active = 1
    LEFT JOIN general_ledger_accounts gla ON agm.gl_account_id = gla.id
    WHERE abp.budget_year = ?
      AND b.status IN ('TM', 'TR')
      AND (abp.budget_classification = 'operational' OR abp.budget_classification IS NULL)
      $div_join_abp
    ORDER BY d.division_name, b.block_code, ag.group_name, a.activity_name
";

// ── Query 2: TBM Capitalized Budget (TBM / LC blocks) ─────────────────────────
// Same activity_budget_plans but for immature blocks → treated as Capex
$sql_tbm = "
    SELECT
        'tbm_capital'                   AS budget_type,
        'TBM Capital'                   AS budget_type_label,
        d.division_id,
        d.division_name,
        b.block_id,
        b.block_code,
        b.block_name,
        b.status                        AS block_status,
        ag.group_name                   AS activity_group,
        a.activity_name,
        COALESCE(gla.account_code, '—') AS gl_account_code,
        COALESCE(gla.account_name, 'Not Mapped') AS gl_account_name,
        COALESCE(gla.account_type, '')  AS gl_account_type,
        abp.total_annual_cost           AS budget_amount,
        abp.plan_id                     AS source_id,
        abp.status                      AS plan_status
    FROM activity_budget_plans abp
    INNER JOIN blocks b              ON abp.block_id      = b.block_id
    INNER JOIN planting_years py     ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d           ON py.division_id    = d.division_id
    INNER JOIN activities a          ON abp.activity_id   = a.id
    INNER JOIN activity_groups ag    ON a.activity_group_id = ag.id
    LEFT JOIN activity_gl_mapping agm
        ON agm.activity_id = a.id
        AND agm.block_status = b.status
        AND agm.is_active = 1
    LEFT JOIN general_ledger_accounts gla ON agm.gl_account_id = gla.id
    WHERE abp.budget_year = ?
      AND b.status IN ('TBM', 'LC')
      $div_join_abp
    ORDER BY d.division_name, b.block_code, ag.group_name, a.activity_name
";

// ── Query 3: Account Budget Items ─────────────────────────────────────────────
$sql_capex = "
    SELECT
        'capex'                         AS budget_type,
        'Capex'                         AS budget_type_label,
        d.division_id,
        d.division_name,
        NULL                            AS block_id,
        COALESCE(b.block_code, '—')     AS block_code,
        cbi.item_name                   AS block_name,
        '—'                             AS block_status,
        cbi.asset_category              AS activity_group,
        cbi.item_name                   AS activity_name,
        COALESCE(gla.account_code, '—') AS gl_account_code,
        COALESCE(gla.account_name, 'Not Mapped') AS gl_account_name,
        COALESCE(gla.account_type, '')  AS gl_account_type,
        cbi.total_cost                  AS budget_amount,
        cbi.item_id                     AS source_id,
        cbi.status                      AS plan_status
    FROM account_budget_items cbi
    LEFT JOIN divisions d            ON cbi.division_id = d.division_id
    LEFT JOIN blocks b               ON cbi.block_id    = b.block_id
    LEFT JOIN general_ledger_accounts gla ON cbi.gl_account_id = gla.id
    WHERE cbi.budget_year = ?
      AND cbi.status NOT IN ('rejected', 'disposed')
      $div_join_cap
    ORDER BY d.division_name, cbi.asset_category, cbi.item_name
";

// ── Execute based on filter ────────────────────────────────────────────────────
$rows = [];

if ($budget_type === 'all' || $budget_type === 'operational') {
    $stmt = $db->prepare($sql_opex);
    $stmt->execute([$year]);
    $rows = array_merge($rows, $stmt->fetchAll());
}

if ($budget_type === 'all' || $budget_type === 'tbm_capital') {
    $stmt = $db->prepare($sql_tbm);
    $stmt->execute([$year]);
    $rows = array_merge($rows, $stmt->fetchAll());
}

if ($budget_type === 'all' || $budget_type === 'capex') {
    $stmt = $db->prepare($sql_capex);
    $stmt->execute([$year]);
    $rows = array_merge($rows, $stmt->fetchAll());
}

// ── Summary totals ────────────────────────────────────────────────────────────
$total_opex      = 0;
$total_tbm       = 0;
$total_capex     = 0;

foreach ($rows as $row) {
    match ($row['budget_type']) {
        'operational' => $total_opex  += $row['budget_amount'],
        'tbm_capital' => $total_tbm   += $row['budget_amount'],
        'capex'       => $total_capex += $row['budget_amount'],
        default       => null,
    };
}

$grand_total = $total_opex + $total_tbm + $total_capex;

// ── Group rows for display ────────────────────────────────────────────────────
$grouped = [];
foreach ($rows as $row) {
    $key = match ($group_by) {
        'gl_account' => ($row['gl_account_code'] . ' ' . $row['gl_account_name']),
        'block'      => ($row['division_name'] . ' › ' . $row['block_code']),
        default      => $row['division_name'], // division
    };
    $grouped[$key][] = $row;
}
ksort($grouped);

// ── Badge helpers ─────────────────────────────────────────────────────────────
function budget_type_badge(string $type): string {
    return match ($type) {
        'operational' => '<span class="badge bg-primary">Opex</span>',
        'tbm_capital' => '<span class="badge bg-warning text-dark">TBM Capital</span>',
        'capex'       => '<span class="badge bg-purple" style="background:#7c5cd8!important;color:#fff">Capex</span>',
        default       => '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>',
    };
}

function account_type_badge(string $type): string {
    return match ($type) {
        'asset'   => '<span class="badge bg-success">Asset</span>',
        'expense' => '<span class="badge bg-secondary">Expense</span>',
        'revenue' => '<span class="badge bg-info">Revenue</span>',
        default   => '',
    };
}
?>

<div class="container-fluid">

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0"><i class="bi bi-layers"></i> Unified Budget Report</h4>
        <small class="text-muted">Operational (Opex) + TBM Capitalisation + Account Budget (Capex)</small>
    </div>
    <div>
        <button onclick="window.print()" class="btn btn-secondary btn-sm">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Year</label>
                <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                    <?php if (empty($years)): ?>
                        <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
                            <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-1">Division</label>
                <select name="division_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Divisions</option>
                    <?php foreach ($divisions as $div): ?>
                        <option value="<?= $div['division_id'] ?>" <?= $div['division_id'] == $division_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($div['division_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Budget Type</label>
                <select name="budget_type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="all"          <?= $budget_type === 'all'          ? 'selected' : '' ?>>All Types</option>
                    <option value="operational"  <?= $budget_type === 'operational'  ? 'selected' : '' ?>>Opex (TM)</option>
                    <option value="tbm_capital"  <?= $budget_type === 'tbm_capital'  ? 'selected' : '' ?>>TBM Capital</option>
                    <option value="capex"        <?= $budget_type === 'capex'        ? 'selected' : '' ?>>Capex (Capital Items)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Group By</label>
                <select name="group_by" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="division"   <?= $group_by === 'division'   ? 'selected' : '' ?>>Division</option>
                    <option value="block"      <?= $group_by === 'block'      ? 'selected' : '' ?>>Division › Block</option>
                    <option value="gl_account" <?= $group_by === 'gl_account' ? 'selected' : '' ?>>GL Account</option>
                </select>
            </div>
            <div class="col-md-1">
                <a href="unified_budget_report.php" class="btn btn-secondary btn-sm w-100">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-2 mb-3">
    <div class="col-md-3">
        <div class="card border-0 bg-light">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Grand Total</div>
                        <div class="fw-bold">Rp <?= number_format($grand_total, 0, ',', '.') ?></div>
                    </div>
                    <i class="bi bi-layers text-secondary fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0" style="background:#dbeafe">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small" style="color:#1d4ed8">Operational (Opex)</div>
                        <div class="fw-bold">Rp <?= number_format($total_opex, 0, ',', '.') ?></div>
                        <div class="small text-muted"><?= $grand_total > 0 ? number_format($total_opex / $grand_total * 100, 1) : 0 ?>%</div>
                    </div>
                    <i class="bi bi-cash-coin fs-3" style="color:#1d4ed8"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0" style="background:#fef9c3">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small" style="color:#a16207">TBM Capital</div>
                        <div class="fw-bold">Rp <?= number_format($total_tbm, 0, ',', '.') ?></div>
                        <div class="small text-muted"><?= $grand_total > 0 ? number_format($total_tbm / $grand_total * 100, 1) : 0 ?>%</div>
                    </div>
                    <i class="bi bi-tree fs-3" style="color:#a16207"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0" style="background:#ede9fe">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small" style="color:#6d28d9">Account Budget (Capex)</div>
                        <div class="fw-bold">Rp <?= number_format($total_capex, 0, ',', '.') ?></div>
                        <div class="small text-muted"><?= $grand_total > 0 ? number_format($total_capex / $grand_total * 100, 1) : 0 ?>%</div>
                    </div>
                    <i class="bi bi-building fs-3" style="color:#6d28d9"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Report Table -->
<?php if (empty($rows)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> No budget data found for the selected filters.
    </div>
<?php else: ?>

<div class="card">
    <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-table"></i> Budget Detail — <?= $year ?></span>
        <small class="text-muted"><?= count($rows) ?> rows &nbsp;|&nbsp; grouped by <?= ucfirst(str_replace('_', ' ', $group_by)) ?></small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" style="font-size:0.83rem;">
                <thead class="table-light sticky-top">
                    <tr>
                        <th class="px-3">Division</th>
                        <th>Block / Item</th>
                        <th>Status</th>
                        <th>Budget Type</th>
                        <th>Activity / Category</th>
                        <th>GL Account</th>
                        <th>Acct Type</th>
                        <th class="text-end pe-3">Budget Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped as $group_label => $group_rows):
                        $group_total = array_sum(array_column($group_rows, 'budget_amount'));
                    ?>
                    <!-- Group Header -->
                    <tr class="table-secondary">
                        <td colspan="7" class="px-3 py-1 fw-semibold">
                            <i class="bi bi-folder2-open"></i> <?= htmlspecialchars($group_label) ?>
                            <small class="text-muted ms-1">(<?= count($group_rows) ?> items)</small>
                        </td>
                        <td class="text-end pe-3 fw-semibold">
                            Rp <?= number_format($group_total, 0, ',', '.') ?>
                        </td>
                    </tr>
                    <!-- Group Rows -->
                    <?php foreach ($group_rows as $row): ?>
                    <tr>
                        <td class="px-3 text-muted"><?= htmlspecialchars($row['division_name'] ?? '—') ?></td>
                        <td>
                            <strong><?= htmlspecialchars($row['block_code']) ?></strong>
                            <?php if ($row['block_name'] && $row['block_name'] !== $row['block_code']): ?>
                                <div class="text-muted" style="font-size:0.78rem;"><?= htmlspecialchars($row['block_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $sc = match($row['block_status']) {
                                'TM'  => 'success',
                                'TBM' => 'warning',
                                'LC'  => 'info',
                                'TR'  => 'secondary',
                                default => 'light',
                            };
                            if ($row['block_status'] !== '—'):
                            ?>
                            <span class="badge bg-<?= $sc ?> text-<?= $sc === 'warning' ? 'dark' : 'white' ?>">
                                <?= htmlspecialchars($row['block_status']) ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td><?= budget_type_badge($row['budget_type']) ?></td>
                        <td>
                            <div><?= htmlspecialchars($row['activity_name']) ?></div>
                            <div class="text-muted" style="font-size:0.78rem;"><?= htmlspecialchars($row['activity_group']) ?></div>
                        </td>
                        <td>
                            <?php if ($row['gl_account_code'] !== '—'): ?>
                                <code><?= htmlspecialchars($row['gl_account_code']) ?></code>
                                <div style="font-size:0.78rem;"><?= htmlspecialchars($row['gl_account_name']) ?></div>
                            <?php else: ?>
                                <span class="text-muted fst-italic" style="font-size:0.78rem;">Not mapped</span>
                            <?php endif; ?>
                        </td>
                        <td><?= account_type_badge($row['gl_account_type']) ?></td>
                        <td class="text-end pe-3">
                            <strong>Rp <?= number_format($row['budget_amount'], 0, ',', '.') ?></strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="7" class="px-3 fw-bold">GRAND TOTAL — <?= $year ?></td>
                        <td class="text-end pe-3 fw-bold">Rp <?= number_format($grand_total, 0, ',', '.') ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

</div><!-- /container-fluid -->

<style>
@media print {
    .btn, form, .card-header .d-flex .small { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .sticky-top { position: static !important; }
}
</style>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
