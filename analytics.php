<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Analytics Dashboard";
require_once 'includes/header.php';

// Get filters
$year = get('year', date('Y'));
$company_filter = get('company_id', '');
$view_type = get('view', 'overview');

// Fetch companies
$companies = $db->query("SELECT * FROM companies ORDER BY company_code")->fetchAll();

// Build WHERE clause for filters
$where_company = $company_filter ? "AND c.company_id = $company_filter" : "";

// 1. PRODUCTION ANALYTICS
$production_sql = "
    SELECT
        MONTH(hr.harvest_date) as month,
        SUM(hr.actual_quantity_kg) as total_ffb_kg,
        COUNT(DISTINCT hr.block_id) as active_blocks,
        COUNT(*) as harvest_count,
        AVG(hr.average_bunch_weight) as avg_bunch_weight
    FROM harvest_realizations hr
    INNER JOIN blocks b ON hr.block_id = b.block_id
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE YEAR(hr.harvest_date) = ? $where_company
    GROUP BY MONTH(hr.harvest_date)
    ORDER BY month
";
$prod_stmt = $db->prepare($production_sql);
$prod_stmt->execute([$year]);
$production_data = $prod_stmt->fetchAll();

// 2. COST ANALYTICS
$cost_sql = "
    SELECT 
        cost_category,
        SUM(cost_amount) as total_cost,
        COUNT(*) as cost_entries
    FROM block_costs bc
    INNER JOIN blocks b ON bc.block_id = b.block_id
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE YEAR(bc.cost_date) = ? $where_company
    GROUP BY cost_category
    ORDER BY total_cost DESC
";
$cost_stmt = $db->prepare($cost_sql);
$cost_stmt->execute([$year]);
$cost_data = $cost_stmt->fetchAll();

// 3. SALES ANALYTICS
$sales_sql = "
    SELECT 
        MONTH(sale_date) as month,
        product_type,
        SUM(quantity_kg) as total_quantity,
        SUM(total_amount) as total_revenue,
        COUNT(*) as transaction_count
    FROM sales
    WHERE YEAR(sale_date) = ? " . ($company_filter ? "AND company_id = ?" : "") . "
    GROUP BY MONTH(sale_date), product_type
    ORDER BY month, product_type
";
$sales_params = $company_filter ? [$year, $company_filter] : [$year];
$sales_stmt = $db->prepare($sales_sql);
$sales_stmt->execute($sales_params);
$sales_data = $sales_stmt->fetchAll();

// 4. BUDGET ANALYTICS
$budget_sql = "
    SELECT 
        budget_type,
        SUM(planned_amount) as total_planned,
        SUM(actual_amount) as total_actual,
        SUM(variance) as total_variance
    FROM budgets
    WHERE budget_year = ? " . ($company_filter ? "AND company_id = ?" : "") . "
    GROUP BY budget_type
";
$budget_params = $company_filter ? [$year, $company_filter] : [$year];
$budget_stmt = $db->prepare($budget_sql);
$budget_stmt->execute($budget_params);
$budget_data = $budget_stmt->fetchAll();

// 5. BLOCK STATUS SUMMARY
$block_status_sql = "
    SELECT 
        b.status,
        COUNT(*) as block_count,
        SUM(b.area) as total_area,
        AVG(b.plant_age) as avg_age
    FROM blocks b
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE 1=1 $where_company
    GROUP BY b.status
";
$block_stmt = $db->query($block_status_sql);
$block_status = $block_stmt->fetchAll();

// Calculate KPIs
$total_production = array_sum(array_column($production_data, 'total_ffb_kg'));
$total_costs = array_sum(array_column($cost_data, 'total_cost'));
$total_revenue = array_sum(array_column($sales_data, 'total_revenue'));
$total_profit = $total_revenue - $total_costs;
$profit_margin = $total_revenue > 0 ? ($total_profit / $total_revenue * 100) : 0;

// Prepare chart data
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$production_by_month = array_fill(1, 12, 0);
foreach ($production_data as $row) {
    $production_by_month[$row['month']] = $row['total_ffb_kg'];
}

$revenue_by_month = array_fill(1, 12, 0);
foreach ($sales_data as $row) {
    if (!isset($revenue_by_month[$row['month']])) {
        $revenue_by_month[$row['month']] = 0;
    }
    $revenue_by_month[$row['month']] += $row['total_revenue'];
}
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-graph-up"></i> Analytics Dashboard - <?= $year ?></h5>
                </div>
                <div class="card-body">
                    <!-- Filters -->
                    <form method="GET" class="row g-3 mb-4">
                        <div class="col-md-2">
                            <label class="form-label">Year</label>
                            <select name="year" class="form-select" onchange="this.form.submit()">
                                <?php for ($y = date('Y') - 3; $y <= date('Y'); $y++): ?>
                                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Company</label>
                            <select name="company_id" class="form-select" onchange="this.form.submit()">
                                <option value="">All Companies</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= $c['company_id'] ?>" <?= $c['company_id'] == $company_filter ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['company_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">View</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="view" id="view_overview" value="overview" 
                                    <?= $view_type == 'overview' ? 'checked' : '' ?> onchange="this.form.submit()">
                                <label class="btn btn-outline-primary" for="view_overview">Overview</label>
                                
                                <input type="radio" class="btn-check" name="view" id="view_production" value="production" 
                                    <?= $view_type == 'production' ? 'checked' : '' ?> onchange="this.form.submit()">
                                <label class="btn btn-outline-primary" for="view_production">Production</label>
                                
                                <input type="radio" class="btn-check" name="view" id="view_financial" value="financial" 
                                    <?= $view_type == 'financial' ? 'checked' : '' ?> onchange="this.form.submit()">
                                <label class="btn btn-outline-primary" for="view_financial">Financial</label>
                            </div>
                        </div>
                    </form>

                    <!-- KPI Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card bg-primary text-white">
                                <div class="card-body">
                                    <h6>Total Production</h6>
                                    <h3><?= number_format($total_production/1000, 2) ?> MT</h3>
                                    <small><?= number_format($total_production, 0) ?> kg FFB</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-success text-white">
                                <div class="card-body">
                                    <h6>Total Revenue</h6>
                                    <h3>Rp <?= number_format($total_revenue/1000000, 1) ?>M</h3>
                                    <small>Rp <?= number_format($total_revenue, 0, ',', '.') ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-warning text-white">
                                <div class="card-body">
                                    <h6>Total Costs</h6>
                                    <h3>Rp <?= number_format($total_costs/1000000, 1) ?>M</h3>
                                    <small>Rp <?= number_format($total_costs, 0, ',', '.') ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-<?= $total_profit >= 0 ? 'success' : 'danger' ?> text-white">
                                <div class="card-body">
                                    <h6>Net Profit</h6>
                                    <h3>Rp <?= number_format($total_profit/1000000, 1) ?>M</h3>
                                    <small>Margin: <?= number_format($profit_margin, 1) ?>%</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($view_type == 'overview' || $view_type == 'production'): ?>
                    <!-- Production Analytics -->
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Monthly Production Trend</h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="productionChart" height="80"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Block Status Distribution</h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="blockStatusChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Production Details -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">Production Details by Month</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Month</th>
                                            <th class="text-end">FFB (kg)</th>
                                            <th class="text-end">FFB (MT)</th>
                                            <th class="text-end">Harvests</th>
                                            <th class="text-end">Active Blocks</th>
                                            <th class="text-end">Avg Bunch Weight</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($production_data as $row): ?>
                                        <tr>
                                            <td><?= $months[$row['month']-1] ?></td>
                                            <td class="text-end"><?= number_format($row['total_ffb_kg'], 0) ?></td>
                                            <td class="text-end"><?= number_format($row['total_ffb_kg']/1000, 2) ?></td>
                                            <td class="text-end"><?= $row['harvest_count'] ?></td>
                                            <td class="text-end"><?= $row['active_blocks'] ?></td>
                                            <td class="text-end"><?= number_format($row['avg_bunch_weight'], 2) ?> kg</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($view_type == 'overview' || $view_type == 'financial'): ?>
                    <!-- Financial Analytics -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Cost Breakdown by Category</h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="costChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Budget vs Actual</h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="budgetChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Revenue Trend -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">Monthly Revenue Trend</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="revenueChart" height="80"></canvas>
                        </div>
                    </div>

                    <!-- Cost Details -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">Cost Analysis</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Category</th>
                                            <th class="text-end">Total Cost</th>
                                            <th class="text-end">Entries</th>
                                            <th class="text-end">% of Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cost_data as $row): ?>
                                        <tr>
                                            <td><?= ucfirst($row['cost_category']) ?></td>
                                            <td class="text-end">Rp <?= number_format($row['total_cost'], 0, ',', '.') ?></td>
                                            <td class="text-end"><?= $row['cost_entries'] ?></td>
                                            <td class="text-end"><?= number_format(($row['total_cost']/$total_costs)*100, 1) ?>%</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Production Chart
const productionCtx = document.getElementById('productionChart');
if (productionCtx) {
    new Chart(productionCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($months) ?>,
            datasets: [{
                label: 'FFB Production (MT)',
                data: <?= json_encode(array_map(function($v) { return $v/1000; }, array_values($production_by_month))) ?>,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: true }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}

// Block Status Chart
const blockStatusCtx = document.getElementById('blockStatusChart');
if (blockStatusCtx) {
    new Chart(blockStatusCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($block_status, 'status')) ?>,
            datasets: [{
                data: <?= json_encode(array_column($block_status, 'block_count')) ?>,
                backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#17a2b8']
            }]
        }
    });
}

// Cost Chart
const costCtx = document.getElementById('costChart');
if (costCtx) {
    new Chart(costCtx, {
        type: 'pie',
        data: {
            labels: <?= json_encode(array_column($cost_data, 'cost_category')) ?>,
            datasets: [{
                data: <?= json_encode(array_column($cost_data, 'total_cost')) ?>,
                backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF']
            }]
        }
    });
}

// Budget Chart
const budgetCtx = document.getElementById('budgetChart');
if (budgetCtx) {
    new Chart(budgetCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($budget_data, 'budget_type')) ?>,
            datasets: [{
                label: 'Planned',
                data: <?= json_encode(array_column($budget_data, 'total_planned')) ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.5)'
            }, {
                label: 'Actual',
                data: <?= json_encode(array_column($budget_data, 'total_actual')) ?>,
                backgroundColor: 'rgba(255, 99, 132, 0.5)'
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}

// Revenue Chart
const revenueCtx = document.getElementById('revenueChart');
if (revenueCtx) {
    new Chart(revenueCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($months) ?>,
            datasets: [{
                label: 'Revenue (Million IDR)',
                data: <?= json_encode(array_map(function($v) { return $v/1000000; }, array_values($revenue_by_month))) ?>,
                backgroundColor: 'rgba(75, 192, 192, 0.5)',
                borderColor: 'rgb(75, 192, 192)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
