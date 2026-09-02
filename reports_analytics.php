<?php
/**
 * Reports & Analytics — forest/reports_analytics.php
 *
 * Operational cost and performance reports:
 *   Dashboard · Cost by Block · Cost by Activity · Cost by Category
 *   Block Profitability · Monthly Trends · Cost Variance
 *
 * Financial (statutory) reports stay in financial_reports.php.
 */
require_once 'includes/header.php';
require_once 'includes/lang.php';

$report_type  = $_GET['report']        ?? 'dashboard';
$company_id   = $_GET['company_id']    ?? ($_SESSION['company_id']       ?? '');
$business_unit_id = $_GET['estate_id'] ?? ($_SESSION['business_unit_id'] ?? '');
$division_id  = $_GET['division_id']   ?? ($_SESSION['division_id']      ?? '');
$block_id     = $_GET['block_id']      ?? '';
$activity_id  = $_GET['activity_id']   ?? '';
$date_from    = $_GET['date_from']     ?? date('Y-01-01');
$date_to      = $_GET['date_to']       ?? date('Y-m-t');
$cost_category  = $_GET['cost_category'] ?? '';
$status_filter  = $_GET['status']        ?? '';

// Legacy alias
$estate_id = $business_unit_id;

$sess_company_id = $_SESSION['company_id']       ?? null;
$sess_bu_id      = $_SESSION['business_unit_id'] ?? null;

try {
    $companies = $pdo->query("SELECT company_id, company_name FROM companies ORDER BY company_name")->fetchAll();

    if ($sess_company_id) {
        $stmt = $pdo->prepare("SELECT business_unit_id as estate_id, unit_name as estate_name, company_id FROM business_units WHERE company_id = ? ORDER BY unit_name");
        $stmt->execute([$sess_company_id]);
    } else {
        $stmt = $pdo->query("SELECT business_unit_id as estate_id, unit_name as estate_name, company_id FROM business_units ORDER BY unit_name");
    }
    $estates = $stmt->fetchAll();

    if ($sess_bu_id) {
        $stmt = $pdo->prepare("SELECT division_id, division_name, business_unit_id as estate_id FROM divisions WHERE business_unit_id = ? ORDER BY division_name");
        $stmt->execute([$sess_bu_id]);
    } elseif ($sess_company_id) {
        $stmt = $pdo->prepare("SELECT d.division_id, d.division_name, d.business_unit_id as estate_id FROM divisions d INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id WHERE bu.company_id = ? ORDER BY d.division_name");
        $stmt->execute([$sess_company_id]);
    } else {
        $stmt = $pdo->query("SELECT division_id, division_name, business_unit_id as estate_id FROM divisions ORDER BY division_name");
    }
    $divisions = $stmt->fetchAll();

    if ($sess_bu_id) {
        $stmt = $pdo->prepare("SELECT b.block_id, b.block_code, b.block_name FROM blocks b INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id INNER JOIN divisions d ON py.division_id = d.division_id WHERE d.business_unit_id = ? ORDER BY b.block_code");
        $stmt->execute([$sess_bu_id]);
    } elseif ($sess_company_id) {
        $stmt = $pdo->prepare("SELECT b.block_id, b.block_code, b.block_name FROM blocks b INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id INNER JOIN divisions d ON py.division_id = d.division_id INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id WHERE bu.company_id = ? ORDER BY b.block_code");
        $stmt->execute([$sess_company_id]);
    } else {
        $stmt = $pdo->query("SELECT block_id, block_code, block_name FROM blocks ORDER BY block_code");
    }
    $blocks = $stmt->fetchAll();

    $activities = $pdo->query("SELECT id as activity_id, activity_code, activity_name FROM activities ORDER BY activity_code")->fetchAll();
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

$cost_categories = ['labor', 'material', 'vehicle_equipment', 'overhead', 'other'];
$block_statuses  = ['LC', 'TBM', 'TM', 'Nursery', 'Replanting', 'HL', 'HP', 'HPT'];

// Valid report types for this page
$_opsrep_valid = ['dashboard','cost_by_block','cost_by_activity',
                  'cost_by_category','block_profitability','monthly_trends','cost_variance'];
if (!in_array($report_type, $_opsrep_valid)) {
    $report_type = 'dashboard';
}
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col-md-12">
            <h2 style="color:#166c82;">
                <i class="bi bi-graph-up" style="color:#166c82;"></i>
                <?php echo __('opsrep_title'); ?>
            </h2>
            <p class="text-muted"><?php echo __('opsrep_desc'); ?></p>
        </div>
    </div>

    <!-- Report Type Tabs -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="btn-group flex-wrap" role="group">
                <?php
                $tabs = [
                    'dashboard'         => ['bi-speedometer2',    'fr_dashboard'],
                    'cost_by_block'     => ['bi-grid-3x3',        'fr_cost_by_block'],
                    'cost_by_activity'  => ['bi-list-task',       'fr_cost_by_activity'],
                    'cost_by_category'  => ['bi-pie-chart',       'fr_cost_by_category'],
                    'block_profitability'=> ['bi-currency-dollar','fr_block_profitability'],
                    'monthly_trends'    => ['bi-graph-up',        'fr_monthly_trends'],
                    'cost_variance'     => ['bi-bar-chart',       'fr_cost_variance'],
                ];
                foreach ($tabs as $key => [$icon, $lkey]):
                    $active = $report_type === $key;
                ?>
                <a href="?report=<?= $key ?>"
                   class="btn btn-<?= $active ? 'primary' : 'outline-primary' ?>"
                   <?= $active ? 'style="background-color:#166c82;border-color:#166c82;"' : '' ?>>
                    <i class="bi <?= $icon ?>"></i> <?php echo __($lkey); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header text-white" style="background-color:#166c82;">
            <h5 class="mb-0"><i class="bi bi-funnel"></i> <?php echo __('fr_filters'); ?></h5>
        </div>
        <div class="card-body">
            <form method="GET" action="" id="filterForm">
                <input type="hidden" name="report" value="<?= htmlspecialchars($report_type) ?>">
                <div class="row g-3">
                    <?php
                    $lock_company  = !empty($_SESSION['company_id']);
                    $lock_bu       = !empty($_SESSION['business_unit_id']);
                    $lock_division = !empty($_SESSION['division_id']);
                    ?>
                    <div class="col-md-2">
                        <label class="form-label">
                            <?php echo __('fr_company'); ?>
                            <?php if ($lock_company): ?><i class="bi bi-lock-fill text-muted ms-1" style="font-size:0.75rem;"></i><?php endif; ?>
                        </label>
                        <select name="company_id" class="form-select" id="companyFilter" <?= $lock_company ? 'disabled' : '' ?>>
                            <option value=""><?php echo __('fr_all_companies'); ?></option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= $company['company_id'] ?>" <?= $company_id == $company['company_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($company['company_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($lock_company): ?>
                            <input type="hidden" name="company_id" value="<?= htmlspecialchars($company_id) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">
                            <?php echo __('fr_business_unit'); ?>
                            <?php if ($lock_bu): ?><i class="bi bi-lock-fill text-muted ms-1" style="font-size:0.75rem;"></i><?php endif; ?>
                        </label>
                        <select name="estate_id" class="form-select" id="estateFilter" <?= $lock_bu ? 'disabled' : '' ?>>
                            <option value=""><?php echo __('fr_all_business_units'); ?></option>
                            <?php foreach ($estates as $estate): ?>
                                <option value="<?= $estate['estate_id'] ?>" data-company="<?= $estate['company_id'] ?>" <?= $business_unit_id == $estate['estate_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($estate['estate_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($lock_bu): ?>
                            <input type="hidden" name="estate_id" value="<?= htmlspecialchars($business_unit_id) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">
                            <?php echo __('fr_division'); ?>
                            <?php if ($lock_division): ?><i class="bi bi-lock-fill text-muted ms-1" style="font-size:0.75rem;"></i><?php endif; ?>
                        </label>
                        <select name="division_id" class="form-select" id="divisionFilter" <?= $lock_division ? 'disabled' : '' ?>>
                            <option value=""><?php echo __('fr_all_divisions'); ?></option>
                            <?php foreach ($divisions as $division): ?>
                                <option value="<?= $division['division_id'] ?>" data-estate="<?= $division['estate_id'] ?>" <?= $division_id == $division['division_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($division['division_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($lock_division): ?>
                            <input type="hidden" name="division_id" value="<?= htmlspecialchars($division_id) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_block'); ?></label>
                        <select name="block_id" class="form-select" id="blockFilter">
                            <option value=""><?php echo __('fr_all_blocks'); ?></option>
                            <?php foreach ($blocks as $block): ?>
                                <option value="<?= $block['block_id'] ?>" <?= $block_id == $block['block_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($block['block_code'] . ' - ' . $block['block_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_activity'); ?></label>
                        <select name="activity_id" class="form-select">
                            <option value=""><?php echo __('fr_all_activities'); ?></option>
                            <?php foreach ($activities as $activity): ?>
                                <option value="<?= $activity['activity_id'] ?>" <?= $activity_id == $activity['activity_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($activity['activity_code'] . ' - ' . $activity['activity_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_cost_category'); ?></label>
                        <select name="cost_category" class="form-select">
                            <option value=""><?php echo __('fr_all_categories'); ?></option>
                            <?php foreach ($cost_categories as $cat): ?>
                                <option value="<?= $cat ?>" <?= $cost_category === $cat ? 'selected' : '' ?>>
                                    <?= ucfirst(str_replace('_', ' ', $cat)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_block_status'); ?></label>
                        <select name="status" class="form-select">
                            <option value=""><?php echo __('fr_all_statuses'); ?></option>
                            <?php foreach ($block_statuses as $status): ?>
                                <option value="<?= $status ?>" <?= $status_filter === $status ? 'selected' : '' ?>><?= $status ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_date_from'); ?></label>
                        <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_date_to'); ?></label>
                        <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-custom-fr me-2">
                            <i class="bi bi-search"></i> <?php echo __('fr_apply'); ?>
                        </button>
                        <a href="?report=<?= $report_type ?>" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> <?php echo __('fr_reset'); ?>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php
    // Build WHERE clause
    $where_conditions = ["je.status = 'posted'"];
    $params = [];
    if ($company_id)     { $where_conditions[] = "je.company_id = :company_id";         $params[':company_id']  = $company_id; }
    if ($estate_id)      { $where_conditions[] = "je.business_unit_id = :estate_id";    $params[':estate_id']   = $estate_id; }
    if ($division_id)    { $where_conditions[] = "je.division_id = :division_id";       $params[':division_id'] = $division_id; }
    if ($block_id)       { $where_conditions[] = "je.block_id = :block_id";             $params[':block_id']    = $block_id; }
    if ($activity_id)    { $where_conditions[] = "jel.activity_id = :activity_id";      $params[':activity_id'] = $activity_id; }
    if ($cost_category)  { $where_conditions[] = "jel.cost_category = :cost_category";  $params[':cost_category'] = $cost_category; }
    if ($status_filter)  { $where_conditions[] = "b.status = :status";                  $params[':status']      = $status_filter; }
    if ($date_from)      { $where_conditions[] = "je.entry_date >= :date_from";         $params[':date_from']   = $date_from; }
    if ($date_to)        { $where_conditions[] = "je.entry_date <= :date_to";           $params[':date_to']     = $date_to; }
    $where_clause = implode(' AND ', $where_conditions);

    switch ($report_type) {
        case 'dashboard':         include 'reports/dashboard_overview.php'; break;
        case 'cost_by_block':     include 'reports/cost_by_block.php';      break;
        case 'cost_by_activity':  include 'reports/cost_by_activity.php';   break;
        case 'cost_by_category':  include 'reports/cost_by_category.php';   break;
        case 'block_profitability':include 'reports/block_profitability.php';break;
        case 'monthly_trends':    include 'reports/monthly_trends.php';     break;
        case 'cost_variance':     include 'reports/cost_variance.php';      break;
        default:
            echo '<div class="alert alert-warning">' . __('fr_report_not_found') . '</div>';
    }
    ?>
</div>

<script>
document.getElementById('companyFilter')?.addEventListener('change', function() {
    const cid = this.value;
    document.querySelectorAll('#estateFilter option').forEach(o => {
        o.style.display = (!cid || !o.value || o.dataset.company === cid) ? '' : 'none';
    });
    if (cid) document.getElementById('estateFilter').value = '';
});
document.getElementById('estateFilter')?.addEventListener('change', function() {
    const eid = this.value;
    document.querySelectorAll('#divisionFilter option').forEach(o => {
        o.style.display = (!eid || !o.value || o.dataset.estate === eid) ? '' : 'none';
    });
    if (eid) document.getElementById('divisionFilter').value = '';
});
document.getElementById('divisionFilter')?.addEventListener('change', function() {
    const did = this.value;
    document.querySelectorAll('#blockFilter option').forEach(o => {
        o.style.display = (!did || !o.value || o.dataset.division === did) ? '' : 'none';
    });
    if (did) document.getElementById('blockFilter').value = '';
});
</script>
