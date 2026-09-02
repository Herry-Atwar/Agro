<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "KPI Dashboard";
require_once 'includes/header.php';

// Get current period
$current_year = date('Y');
$current_month = date('m');
$previous_month = date('m', strtotime('-1 month'));
$previous_year = date('Y', strtotime('-1 month'));

// Company filter
$company_filter = get('company_id', '');
$where_company = $company_filter ? "AND c.company_id = $company_filter" : "";

// 1. PRODUCTION KPIs
$production_kpi = $db->query("
    SELECT 
        SUM(CASE WHEN YEAR(hr.harvest_date) = $current_year AND MONTH(hr.harvest_date) = $current_month 
            THEN hr.actual_quantity_kg ELSE 0 END) as current_month_production,
        SUM(CASE WHEN YEAR(hr.harvest_date) = $previous_year AND MONTH(hr.harvest_date) = $previous_month 
            THEN hr.actual_quantity_kg ELSE 0 END) as previous_month_production,
        SUM(CASE WHEN YEAR(hr.harvest_date) = $current_year 
            THEN hr.actual_quantity_kg ELSE 0 END) as ytd_production,
        COUNT(DISTINCT CASE WHEN YEAR(hr.harvest_date) = $current_year AND MONTH(hr.harvest_date) = $current_month 
            THEN hr.block_id END) as active_blocks_current,
        AVG(CASE WHEN YEAR(hr.harvest_date) = $current_year AND MONTH(hr.harvest_date) = $current_month 
            THEN hr.average_bunch_weight END) as avg_bunch_weight
    FROM harvest_realizations hr
    INNER JOIN blocks b ON hr.block_id = b.block_id
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE 1=1 $where_company
")->fetch();

// 2. FINANCIAL KPIs
$financial_kpi = $db->query("
    SELECT 
        SUM(CASE WHEN YEAR(s.sale_date) = $current_year AND MONTH(s.sale_date) = $current_month 
            THEN s.total_amount ELSE 0 END) as current_month_revenue,
        SUM(CASE WHEN YEAR(s.sale_date) = $previous_year AND MONTH(s.sale_date) = $previous_month 
            THEN s.total_amount ELSE 0 END) as previous_month_revenue,
        SUM(CASE WHEN YEAR(s.sale_date) = $current_year 
            THEN s.total_amount ELSE 0 END) as ytd_revenue,
        SUM(CASE WHEN YEAR(bc.cost_date) = $current_year AND MONTH(bc.cost_date) = $current_month 
            THEN bc.cost_amount ELSE 0 END) as current_month_cost,
        SUM(CASE WHEN YEAR(bc.cost_date) = $current_year 
            THEN bc.cost_amount ELSE 0 END) as ytd_cost
    FROM sales s
    LEFT JOIN companies c ON s.company_id = c.company_id
    LEFT JOIN block_costs bc ON YEAR(s.sale_date) = YEAR(bc.cost_date) AND MONTH(s.sale_date) = MONTH(bc.cost_date)
    WHERE 1=1 " . ($company_filter ? "AND s.company_id = $company_filter" : "") . "
")->fetch();

// 3. OPERATIONAL KPIs
$operational_kpi = $db->query("
    SELECT 
        COUNT(DISTINCT CASE WHEN b.status = 'TM' THEN b.block_id END) as mature_blocks,
        COUNT(DISTINCT CASE WHEN b.status = 'TBM' THEN b.block_id END) as immature_blocks,
        SUM(CASE WHEN b.status = 'TM' THEN b.area ELSE 0 END) as mature_area,
        SUM(CASE WHEN b.status = 'TBM' THEN b.area ELSE 0 END) as immature_area,
        SUM(b.area) as total_area,
        AVG(CASE WHEN b.status = 'TM' THEN b.plant_age END) as avg_mature_age
    FROM blocks b
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE 1=1 $where_company
")->fetch();

// 4. BUDGET KPIs
$budget_kpi = $db->query("
    SELECT 
        SUM(planned_amount) as total_budget,
        SUM(actual_amount) as total_spent,
        SUM(variance) as total_variance,
        AVG(variance_percentage) as avg_variance_pct
    FROM budgets
    WHERE budget_year = $current_year " . ($company_filter ? "AND company_id = $company_filter" : "") . "
")->fetch();

// 5. SALES KPIs
$sales_kpi = $db->query("
    SELECT 
        COUNT(CASE WHEN YEAR(sale_date) = $current_year AND MONTH(sale_date) = $current_month 
            THEN sale_id END) as current_month_transactions,
        COUNT(DISTINCT CASE WHEN YEAR(sale_date) = $current_year AND MONTH(sale_date) = $current_month 
            THEN customer_id END) as active_customers,
        SUM(CASE WHEN payment_status = 'pending' THEN total_amount ELSE 0 END) as outstanding_receivables
    FROM sales
    WHERE 1=1 " . ($company_filter ? "AND company_id = $company_filter" : "") . "
")->fetch();

// Calculate derived KPIs
$production_growth = $production_kpi['previous_month_production'] > 0 
    ? (($production_kpi['current_month_production'] - $production_kpi['previous_month_production']) / $production_kpi['previous_month_production'] * 100) 
    : 0;

$revenue_growth = $financial_kpi['previous_month_revenue'] > 0 
    ? (($financial_kpi['current_month_revenue'] - $financial_kpi['previous_month_revenue']) / $financial_kpi['previous_month_revenue'] * 100) 
    : 0;

$current_profit = $financial_kpi['current_month_revenue'] - $financial_kpi['current_month_cost'];
$profit_margin = $financial_kpi['current_month_revenue'] > 0 
    ? ($current_profit / $financial_kpi['current_month_revenue'] * 100) 
    : 0;

$ytd_profit = $financial_kpi['ytd_revenue'] - $financial_kpi['ytd_cost'];
$ytd_margin = $financial_kpi['ytd_revenue'] > 0 
    ? ($ytd_profit / $financial_kpi['ytd_revenue'] * 100) 
    : 0;

$productivity_per_ha = $operational_kpi['mature_area'] > 0 
    ? ($production_kpi['current_month_production'] / $operational_kpi['mature_area']) 
    : 0;

$budget_utilization = $budget_kpi['total_budget'] > 0 
    ? ($budget_kpi['total_spent'] / $budget_kpi['total_budget'] * 100) 
    : 0;

// Fetch companies for filter
$companies = $db->query("SELECT * FROM companies ORDER BY company_code")->fetchAll();

// Get alerts
$alerts = [];
if ($production_growth < -10) {
    $alerts[] = ['type' => 'danger', 'message' => 'Production declined by ' . number_format(abs($production_growth), 1) . '% vs last month'];
}
if ($profit_margin < 10) {
    $alerts[] = ['type' => 'warning', 'message' => 'Profit margin below 10% (' . number_format($profit_margin, 1) . '%)'];
}
if ($budget_utilization > 90) {
    $alerts[] = ['type' => 'warning', 'message' => 'Budget utilization at ' . number_format($budget_utilization, 1) . '%'];
}
if ($sales_kpi['outstanding_receivables'] > 1000000000) {
    $alerts[] = ['type' => 'info', 'message' => 'Outstanding receivables: Rp ' . number_format($sales_kpi['outstanding_receivables']/1000000, 1) . 'M'];
}
?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="row mb-3">
        <div class="col-md-8">
            <h3><i class="bi bi-speedometer2"></i> KPI Dashboard</h3>
            <p class="text-muted">Real-time Key Performance Indicators - <?= date('F Y') ?></p>
        </div>
        <div class="col-md-4">
            <form method="GET">
                <select name="company_id" class="form-select" onchange="this.form.submit()">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= $c['company_id'] ?>" <?= $c['company_id'] == $company_filter ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['company_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($alerts)): ?>
    <div class="row mb-3">
        <div class="col-md-12">
            <?php foreach ($alerts as $alert): ?>
            <div class="alert alert-<?= $alert['type'] ?> alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= $alert['message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Production KPIs -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h5 class="mb-3"><i class="bi bi-graph-up"></i> Production Performance</h5>
        </div>
        <div class="col-md-3">
            <div class="card border-primary">
                <div class="card-body">
                    <h6 class="text-muted">Current Month Production</h6>
                    <h3 class="text-primary"><?= number_format($production_kpi['current_month_production']/1000, 2) ?> MT</h3>
                    <small class="<?= $production_growth >= 0 ? 'text-success' : 'text-danger' ?>">
                        <i class="bi bi-arrow-<?= $production_growth >= 0 ? 'up' : 'down' ?>"></i>
                        <?= number_format(abs($production_growth), 1) ?>% vs last month
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-success">
                <div class="card-body">
                    <h6 class="text-muted">YTD Production</h6>
                    <h3 class="text-success"><?= number_format($production_kpi['ytd_production']/1000, 2) ?> MT</h3>
                    <small class="text-muted"><?= number_format($production_kpi['ytd_production'], 0) ?> kg</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-info">
                <div class="card-body">
                    <h6 class="text-muted">Productivity per Ha</h6>
                    <h3 class="text-info"><?= number_format($productivity_per_ha, 2) ?> kg/ha</h3>
                    <small class="text-muted">Mature blocks only</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-warning">
                <div class="card-body">
                    <h6 class="text-muted">Avg Bunch Weight</h6>
                    <h3 class="text-warning"><?= number_format($production_kpi['avg_bunch_weight'], 2) ?> kg</h3>
                    <small class="text-muted">Current month average</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Financial KPIs -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h5 class="mb-3"><i class="bi bi-currency-dollar"></i> Financial Performance</h5>
        </div>
        <div class="col-md-3">
            <div class="card border-success">
                <div class="card-body">
                    <h6 class="text-muted">Current Month Revenue</h6>
                    <h3 class="text-success">Rp <?= number_format($financial_kpi['current_month_revenue']/1000000, 1) ?>M</h3>
                    <small class="<?= $revenue_growth >= 0 ? 'text-success' : 'text-danger' ?>">
                        <i class="bi bi-arrow-<?= $revenue_growth >= 0 ? 'up' : 'down' ?>"></i>
                        <?= number_format(abs($revenue_growth), 1) ?>% vs last month
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-warning">
                <div class="card-body">
                    <h6 class="text-muted">Current Month Cost</h6>
                    <h3 class="text-warning">Rp <?= number_format($financial_kpi['current_month_cost']/1000000, 1) ?>M</h3>
                    <small class="text-muted">Operational costs</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-<?= $current_profit >= 0 ? 'success' : 'danger' ?>">
                <div class="card-body">
                    <h6 class="text-muted">Current Month Profit</h6>
                    <h3 class="text-<?= $current_profit >= 0 ? 'success' : 'danger' ?>">
                        Rp <?= number_format($current_profit/1000000, 1) ?>M
                    </h3>
                    <small class="text-muted">Margin: <?= number_format($profit_margin, 1) ?>%</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-primary">
                <div class="card-body">
                    <h6 class="text-muted">YTD Profit</h6>
                    <h3 class="text-primary">Rp <?= number_format($ytd_profit/1000000, 1) ?>M</h3>
                    <small class="text-muted">Margin: <?= number_format($ytd_margin, 1) ?>%</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Operational KPIs -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h5 class="mb-3"><i class="bi bi-gear"></i> Operational Metrics</h5>
        </div>
        <div class="col-md-3">
            <div class="card border-success">
                <div class="card-body">
                    <h6 class="text-muted">Mature Blocks (TM)</h6>
                    <h3 class="text-success"><?= $operational_kpi['mature_blocks'] ?></h3>
                    <small class="text-muted"><?= number_format($operational_kpi['mature_area'], 2) ?> ha</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-warning">
                <div class="card-body">
                    <h6 class="text-muted">Immature Blocks (TBM)</h6>
                    <h3 class="text-warning"><?= $operational_kpi['immature_blocks'] ?></h3>
                    <small class="text-muted"><?= number_format($operational_kpi['immature_area'], 2) ?> ha</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-info">
                <div class="card-body">
                    <h6 class="text-muted">Total Plantation Area</h6>
                    <h3 class="text-info"><?= number_format($operational_kpi['total_area'], 2) ?> ha</h3>
                    <small class="text-muted">All blocks</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-primary">
                <div class="card-body">
                    <h6 class="text-muted">Avg Mature Age</h6>
                    <h3 class="text-primary"><?= number_format($operational_kpi['avg_mature_age'], 1) ?> years</h3>
                    <small class="text-muted">TM blocks average</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Budget & Sales KPIs -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-cash-stack"></i> Budget Performance</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-muted">Total Budget</h6>
                            <h4>Rp <?= number_format($budget_kpi['total_budget']/1000000, 1) ?>M</h4>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Total Spent</h6>
                            <h4>Rp <?= number_format($budget_kpi['total_spent']/1000000, 1) ?>M</h4>
                        </div>
                    </div>
                    <div class="progress mt-3" style="height: 25px;">
                        <div class="progress-bar bg-<?= $budget_utilization > 90 ? 'danger' : ($budget_utilization > 75 ? 'warning' : 'success') ?>" 
                             style="width: <?= min($budget_utilization, 100) ?>%">
                            <?= number_format($budget_utilization, 1) ?>%
                        </div>
                    </div>
                    <small class="text-muted mt-2 d-block">
                        Variance: Rp <?= number_format($budget_kpi['total_variance']/1000000, 1) ?>M 
                        (<?= number_format($budget_kpi['avg_variance_pct'], 1) ?>%)
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="bi bi-cart-check"></i> Sales Performance</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <h6 class="text-muted">Transactions</h6>
                            <h4><?= $sales_kpi['current_month_transactions'] ?></h4>
                            <small class="text-muted">This month</small>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Active Customers</h6>
                            <h4><?= $sales_kpi['active_customers'] ?></h4>
                            <small class="text-muted">This month</small>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Receivables</h6>
                            <h4>Rp <?= number_format($sales_kpi['outstanding_receivables']/1000000, 1) ?>M</h4>
                            <small class="text-muted">Outstanding</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-link-45deg"></i> Quick Access</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <a href="analytics.php" class="btn btn-outline-primary w-100">
                                <i class="bi bi-graph-up"></i> Analytics Dashboard
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="block_costing.php" class="btn btn-outline-warning w-100">
                                <i class="bi bi-calculator"></i> Block Costing
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="budget.php" class="btn btn-outline-info w-100">
                                <i class="bi bi-cash-stack"></i> Budget Management
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="sales.php" class="btn btn-outline-success w-100">
                                <i class="bi bi-cart-check"></i> Sales Management
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-refresh every 5 minutes
setTimeout(function() {
    location.reload();
}, 300000);
</script>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
