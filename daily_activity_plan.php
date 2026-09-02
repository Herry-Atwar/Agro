<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_daily_activity');

// ─── Auto-generate plan number (DAP-YYYYMMDD-NNNN) ───────────────────────────
function gen_plan_number(PDO $db, string $date): string {
    $ymd    = date('Ymd', strtotime($date));
    $prefix = 'DAP-' . $ymd . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(plan_number,'-',-1) AS UNSIGNED)),0)+1
        FROM daily_activity_plans WHERE plan_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── Auto-generate material requirement number (MR-YYYYMMDD-NNNN) ────────────
function gen_req_number(PDO $db, string $date): string {
    $ymd    = date('Ymd', strtotime($date));
    $prefix = 'MR-' . $ymd . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(req_number,'-',-1) AS UNSIGNED)),0)+1
        FROM material_requirements WHERE req_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── Find best matching norm for an activity + block ─────────────────────────
function find_norm(PDO $db, int $activity_id, int $block_id): ?array {
    // Get block topography and plant_age
    $bstmt = $db->prepare("SELECT topography, plant_age FROM blocks WHERE block_id = ? LIMIT 1");
    $bstmt->execute([$block_id]);
    $block = $bstmt->fetch(PDO::FETCH_ASSOC);

    // Map DB enum to activity_norms terrain_type enum
    $terrain_map = ['Flat'=>'flat','Undulating'=>'sloping','Hilly'=>'sloping','Steep'=>'steep'];
    $topo        = $terrain_map[$block['topography'] ?? 'Flat'] ?? 'flat';
    $age         = (int)($block['plant_age'] ?? 0);

    // Try exact terrain match first, then fall back to 'flat'
    $sql = "
        SELECT id, man_days_per_unit, unit_of_measure, daily_wage, productivity_factor
        FROM activity_norms
        WHERE activity_id = ?
          AND is_active = 1
          AND (terrain_type = ? OR terrain_type = 'flat')
          AND palm_age_min <= ?
          AND palm_age_max >= ?
        ORDER BY
            (terrain_type = ?) DESC,
            is_default DESC,
            id ASC
        LIMIT 1
    ";
    $nstmt = $db->prepare($sql);
    $nstmt->execute([$activity_id, $topo, $age, $age, $topo]);
    $row = $nstmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ─── Insert material requirements for a plan item ────────────────────────────
function insert_material_requirements(PDO $db, int $plan_id, int $item_id, int $norm_id,
                                      float $planned_area, float $planned_quantity,
                                      string $plan_date): int {
    $materials_stmt = $db->prepare("
        SELECT anm.material_id, anm.qty_per_unit, anm.unit_of_measure,
               COALESCE(vss.current_stock, 0) AS current_stock
        FROM activity_norm_materials anm
        LEFT JOIN vw_material_stock_summary vss ON vss.material_id = anm.material_id
        WHERE anm.norm_id = ?
    ");
    $materials_stmt->execute([$norm_id]);
    $norm_materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);

    $inserted = 0;
    foreach ($norm_materials as $nm) {
        // qty = qty_per_unit * (area if UOM is hectare/ha, else quantity)
        $uom     = strtolower($nm['unit_of_measure']);
        $base    = in_array($uom, ['hectare','ha']) ? $planned_area : $planned_quantity;
        $req_qty = round((float)$nm['qty_per_unit'] * $base, 2);
        if ($req_qty <= 0) continue;

        $req_num = gen_req_number($db, $plan_date);
        $db->prepare("
            INSERT INTO material_requirements
                (req_number, plan_item_id, plan_id, material_id,
                 required_qty, current_stock, issue_qty, purchase_qty,
                 status, created_by)
            VALUES (?,?,?,?, ?,?,0,0, 'pending','admin')
        ")->execute([
            $req_num,
            $item_id,
            $plan_id,
            (int)$nm['material_id'],
            $req_qty,
            (float)$nm['current_stock'],
        ]);
        $inserted++;
    }
    return $inserted;
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // ── Create Plan ──────────────────────────────────────────────────────────
    if ($action === 'create_plan') {
        try {
            $db->beginTransaction();

            $plan_date = post('plan_date') ?: date('Y-m-d');
            $plan_num  = gen_plan_number($db, $plan_date);

            $db->prepare("
                INSERT INTO daily_activity_plans
                    (plan_number, plan_date, division_id, supervisor, status, notes,
                     budget_year, budget_month, created_by)
                VALUES (?,?,?,?,'draft',?,?,?,'admin')
            ")->execute([
                $plan_num,
                $plan_date,
                (int)post('division_id'),
                post('supervisor'),
                post('notes'),
                date('Y', strtotime($plan_date)),
                (int)date('n', strtotime($plan_date)),
            ]);
            $plan_id = (int)$db->lastInsertId();

            // Items
            $block_ids      = $_POST['block_id']        ?? [];
            $activity_ids   = $_POST['activity_id']     ?? [];
            $planned_areas  = $_POST['planned_area']    ?? [];
            $planned_qtys   = $_POST['planned_quantity'] ?? [];
            $planned_workers= $_POST['planned_workers'] ?? [];

            $total_mr = 0;
            foreach ($block_ids as $i => $bid) {
                $bid         = (int)$bid;
                $act_id      = (int)($activity_ids[$i] ?? 0);
                $area        = (float)($planned_areas[$i] ?? 0);
                $qty         = (float)($planned_qtys[$i] ?? 0);
                $workers     = (int)($planned_workers[$i] ?? 0);
                if (!$bid || !$act_id) continue;

                $norm = find_norm($db, $act_id, $bid);

                // estimated_cost = man_days * daily_wage * workers (simplified)
                $man_days = 0;
                $est_cost = 0;
                if ($norm) {
                    $base_unit  = in_array(strtolower($norm['unit_of_measure']),['hectare','ha']) ? $area : $qty;
                    $man_days   = round((float)$norm['man_days_per_unit'] * $base_unit * (float)$norm['productivity_factor'], 4);
                    $est_cost   = round($man_days * (float)$norm['daily_wage'], 2);
                }

                // Snapshot budget remaining for this activity+block+month
                $budget_remaining = null;
                $budget_month_id  = null;
                $budget_plan_id   = null;
                $bstmt = $db->prepare("
                    SELECT abm.monthly_id, abm.plan_id,
                           (abm.planned_cost - abm.actual_cost) AS remaining
                    FROM activity_budget_monthly abm
                    WHERE abm.block_id = ?
                      AND abm.activity_id = ?
                      AND abm.budget_year = ?
                      AND abm.budget_month = ?
                    LIMIT 1
                ");
                $bstmt->execute([
                    $bid, $act_id,
                    date('Y', strtotime($plan_date)),
                    (int)date('n', strtotime($plan_date)),
                ]);
                $brow = $bstmt->fetch(PDO::FETCH_ASSOC);
                if ($brow) {
                    $budget_remaining = $brow['remaining'];
                    $budget_month_id  = $brow['monthly_id'];
                    $budget_plan_id   = $brow['plan_id'];
                }

                $db->prepare("
                    INSERT INTO daily_activity_plan_items
                        (plan_id, block_id, activity_id, norm_id,
                         planned_area, planned_quantity, planned_workers,
                         planned_man_days, estimated_cost,
                         budget_plan_id, budget_month_id, budget_remaining,
                         status, created_by)
                    VALUES (?,?,?,?, ?,?,?,?,?, ?,?,?, 'planned','admin')
                ")->execute([
                    $plan_id, $bid, $act_id, $norm ? $norm['id'] : null,
                    $area, $qty, $workers,
                    $man_days, $est_cost,
                    $budget_plan_id, $budget_month_id, $budget_remaining,
                ]);
                $item_id = (int)$db->lastInsertId();

                if ($norm) {
                    $total_mr += insert_material_requirements(
                        $db, $plan_id, $item_id, (int)$norm['id'],
                        $area, $qty, $plan_date
                    );
                }
            }

            $db->commit();
            $msg = "Plan <b>$plan_num</b> created.";
            if ($total_mr > 0) {
                $msg .= " <span class='text-success'>$total_mr material requirement(s) auto-generated.</span>";
            } else {
                $msg .= " <span class='text-warning'>No material requirements generated (no norm materials found).</span>";
            }
            set_message('success', $msg);
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error creating plan: ' . $e->getMessage());
        }
        redirect('daily_activity_plan.php?' . http_build_query([
            'year'  => date('Y', strtotime(post('plan_date') ?: date('Y-m-d'))),
            'month' => date('n', strtotime(post('plan_date') ?: date('Y-m-d'))),
        ]));
    }

    // ── Update Plan (draft only) ─────────────────────────────────────────────
    if ($action === 'update_plan') {
        $plan_id = (int)post('plan_id');
        try {
            $db->beginTransaction();

            // Verify draft
            $check = $db->prepare("SELECT status, plan_date FROM daily_activity_plans WHERE plan_id=?");
            $check->execute([$plan_id]);
            $plan_row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$plan_row || $plan_row['status'] !== 'draft') {
                throw new \Exception('Only draft plans can be edited.');
            }

            $plan_date = post('plan_date') ?: $plan_row['plan_date'];

            $db->prepare("
                UPDATE daily_activity_plans
                SET plan_date=?, division_id=?, supervisor=?, notes=?,
                    budget_year=?, budget_month=?, updated_by='admin'
                WHERE plan_id=?
            ")->execute([
                $plan_date,
                (int)post('division_id'),
                post('supervisor'),
                post('notes'),
                date('Y', strtotime($plan_date)),
                (int)date('n', strtotime($plan_date)),
                $plan_id,
            ]);

            // Delete old items and their material requirements
            $old_items = $db->prepare("SELECT item_id FROM daily_activity_plan_items WHERE plan_id=?");
            $old_items->execute([$plan_id]);
            foreach ($old_items->fetchAll(PDO::FETCH_COLUMN) as $old_item_id) {
                $db->prepare("DELETE FROM material_requirements WHERE plan_item_id=?")
                   ->execute([$old_item_id]);
            }
            $db->prepare("DELETE FROM daily_activity_plan_items WHERE plan_id=?")->execute([$plan_id]);

            // Reinsert items
            $block_ids      = $_POST['block_id']         ?? [];
            $activity_ids   = $_POST['activity_id']      ?? [];
            $planned_areas  = $_POST['planned_area']     ?? [];
            $planned_qtys   = $_POST['planned_quantity']  ?? [];
            $planned_workers= $_POST['planned_workers']  ?? [];

            $total_mr = 0;
            foreach ($block_ids as $i => $bid) {
                $bid     = (int)$bid;
                $act_id  = (int)($activity_ids[$i] ?? 0);
                $area    = (float)($planned_areas[$i] ?? 0);
                $qty     = (float)($planned_qtys[$i] ?? 0);
                $workers = (int)($planned_workers[$i] ?? 0);
                if (!$bid || !$act_id) continue;

                $norm = find_norm($db, $act_id, $bid);
                $man_days = 0;
                $est_cost = 0;
                if ($norm) {
                    $base_unit = in_array(strtolower($norm['unit_of_measure']),['hectare','ha']) ? $area : $qty;
                    $man_days  = round((float)$norm['man_days_per_unit'] * $base_unit * (float)$norm['productivity_factor'], 4);
                    $est_cost  = round($man_days * (float)$norm['daily_wage'], 2);
                }

                $budget_remaining = null;
                $budget_month_id  = null;
                $budget_plan_id   = null;
                $bstmt = $db->prepare("
                    SELECT abm.monthly_id, abm.plan_id,
                           (abm.planned_cost - abm.actual_cost) AS remaining
                    FROM activity_budget_monthly abm
                    WHERE abm.block_id=? AND abm.activity_id=?
                      AND abm.budget_year=? AND abm.budget_month=?
                    LIMIT 1
                ");
                $bstmt->execute([
                    $bid, $act_id,
                    date('Y', strtotime($plan_date)),
                    (int)date('n', strtotime($plan_date)),
                ]);
                $brow = $bstmt->fetch(PDO::FETCH_ASSOC);
                if ($brow) {
                    $budget_remaining = $brow['remaining'];
                    $budget_month_id  = $brow['monthly_id'];
                    $budget_plan_id   = $brow['plan_id'];
                }

                $db->prepare("
                    INSERT INTO daily_activity_plan_items
                        (plan_id, block_id, activity_id, norm_id,
                         planned_area, planned_quantity, planned_workers,
                         planned_man_days, estimated_cost,
                         budget_plan_id, budget_month_id, budget_remaining,
                         status, created_by)
                    VALUES (?,?,?,?, ?,?,?,?,?, ?,?,?, 'planned','admin')
                ")->execute([
                    $plan_id, $bid, $act_id, $norm ? $norm['id'] : null,
                    $area, $qty, $workers,
                    $man_days, $est_cost,
                    $budget_plan_id, $budget_month_id, $budget_remaining,
                ]);
                $item_id = (int)$db->lastInsertId();

                if ($norm) {
                    $total_mr += insert_material_requirements(
                        $db, $plan_id, $item_id, (int)$norm['id'],
                        $area, $qty, $plan_date
                    );
                }
            }

            $db->commit();
            set_message('success', "Plan updated. $total_mr material requirement(s) regenerated.");
        } catch (\Exception $e) {
            $db->rollBack();
            set_message('error', 'Error updating plan: ' . $e->getMessage());
        }
        redirect('daily_activity_plan.php?' . http_build_query([
            'year'  => date('Y', strtotime(post('plan_date') ?: date('Y-m-d'))),
            'month' => date('n', strtotime(post('plan_date') ?: date('Y-m-d'))),
        ]));
    }

    // ── Cancel Plan ──────────────────────────────────────────────────────────
    if ($action === 'cancel_plan') {
        $plan_id = (int)post('plan_id');
        try {
            $db->beginTransaction();
            $db->prepare("UPDATE daily_activity_plans SET status='cancelled', updated_by='admin' WHERE plan_id=?")
               ->execute([$plan_id]);
            $db->prepare("UPDATE daily_activity_plan_items SET status='cancelled', updated_by='admin' WHERE plan_id=?")
               ->execute([$plan_id]);
            $db->prepare("UPDATE material_requirements SET status='cancelled', updated_by='admin' WHERE plan_id=?")
               ->execute([$plan_id]);
            $db->commit();
            set_message('success', 'Plan cancelled.');
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('daily_activity_plan.php?' . http_build_query(['year'=>get('year',date('Y')),'month'=>get('month',date('n'))]));
    }

    // ── Complete Plan ────────────────────────────────────────────────────────
    if ($action === 'complete_plan') {
        $plan_id = (int)post('plan_id');
        try {
            $db->beginTransaction();
            $db->prepare("UPDATE daily_activity_plans SET status='completed', updated_by='admin' WHERE plan_id=?")
               ->execute([$plan_id]);
            $db->prepare("UPDATE daily_activity_plan_items SET status='completed', updated_by='admin' WHERE plan_id=? AND status != 'cancelled'")
               ->execute([$plan_id]);
            $db->commit();
            set_message('success', 'Plan marked as completed.');
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('daily_activity_plan.php?' . http_build_query(['year'=>get('year',date('Y')),'month'=>get('month',date('n'))]));
    }
}

// ─── Filters ─────────────────────────────────────────────────────────────────
$filter_year   = (int)get('year',  date('Y'));
$filter_month  = get('month', '');   // '' = all months
$filter_div    = (int)get('division_id', 0);
$filter_status = get('status', '');

// ─── Reference data ───────────────────────────────────────────────────────────
$divisions = $db->query("SELECT division_id, division_name FROM divisions ORDER BY division_name")->fetchAll(PDO::FETCH_ASSOC);

$blocks_raw = $db->query("
    SELECT block_id, block_name, division_id, topography, plant_age
    FROM blocks
    WHERE division_id IS NOT NULL
    ORDER BY block_name
")->fetchAll(PDO::FETCH_ASSOC);

// Group blocks by division_id for JS cascade
$blocks_by_div = [];
foreach ($blocks_raw as $b) {
    $blocks_by_div[(int)$b['division_id']][] = $b;
}

$activities = $db->query("
    SELECT a.id, a.activity_code, a.activity_name,
           COUNT(an.id) AS norm_count
    FROM activities a
    LEFT JOIN activity_norms an ON an.activity_id = a.id AND an.is_active = 1
    WHERE a.is_active = 1
    GROUP BY a.id, a.activity_code, a.activity_name
    ORDER BY a.display_order, a.activity_name
")->fetchAll(PDO::FETCH_ASSOC);

// Budget remaining per block+activity for current filter month (for JS warning)
$budget_data_raw = [];
try {
    $bsql = "
        SELECT abm.block_id, abm.activity_id,
               abm.planned_cost, abm.actual_cost,
               (abm.planned_cost - abm.actual_cost) AS remaining
        FROM activity_budget_monthly abm
        WHERE abm.budget_year = ?";
    $bparams = [$filter_year];
    if ($filter_month !== '') { $bsql .= " AND abm.budget_month = ?"; $bparams[] = (int)$filter_month; }
    $bstmt = $db->prepare($bsql);
    $bstmt->execute($bparams);
    foreach ($bstmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $budget_data_raw[$row['block_id'] . '_' . $row['activity_id']] = [
            'planned'   => (float)$row['planned_cost'],
            'actual'    => (float)$row['actual_cost'],
            'remaining' => (float)$row['remaining'],
        ];
    }
} catch (PDOException $e) { /* silently skip if budget not set */ }

// ─── Plans list ───────────────────────────────────────────────────────────────
try {
    $sql = "
        SELECT dap.*,
               d.division_name,
               COUNT(DISTINCT dapi.item_id)          AS item_count,
               COALESCE(SUM(dapi.estimated_cost),0)  AS total_estimated_cost,
               SUM(CASE WHEN dapi.estimated_cost > COALESCE(dapi.budget_remaining,9e15) AND dapi.budget_remaining IS NOT NULL THEN 1 ELSE 0 END) AS over_budget_items
        FROM daily_activity_plans dap
        LEFT JOIN divisions d        ON d.division_id = dap.division_id
        LEFT JOIN daily_activity_plan_items dapi ON dapi.plan_id = dap.plan_id
        WHERE dap.budget_year = ?
    ";
    $p = [$filter_year];
    if ($filter_month !== '') { $sql .= " AND dap.budget_month = ?"; $p[] = (int)$filter_month; }
    if ($filter_div)    { $sql .= " AND dap.division_id=?"; $p[] = $filter_div; }
    if ($filter_status) { $sql .= " AND dap.status=?";      $p[] = $filter_status; }
    $sql .= " GROUP BY dap.plan_id ORDER BY dap.plan_date DESC, dap.plan_id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($p);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $plans = [];
    set_message('error', 'Could not load plans: ' . $e->getMessage());
}

// Plan items for detail rows (keyed by plan_id)
$plan_items_map = [];
if ($plans) {
    $plan_ids       = implode(',', array_column($plans, 'plan_id'));
    $items_stmt     = $db->query("
        SELECT dapi.*,
               b.block_name, b.block_code,
               a.activity_name, a.activity_code,
               COUNT(mr.req_id) AS mr_count
        FROM daily_activity_plan_items dapi
        LEFT JOIN blocks b      ON b.block_id    = dapi.block_id
        LEFT JOIN activities a  ON a.id          = dapi.activity_id
        LEFT JOIN material_requirements mr ON mr.plan_item_id = dapi.item_id
        WHERE dapi.plan_id IN ($plan_ids)
        GROUP BY dapi.item_id
        ORDER BY dapi.item_id
    ");
    foreach ($items_stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $plan_items_map[$item['plan_id']][] = $item;
    }
}

$status_colours = [
    'draft'     => 'secondary',
    'submitted' => 'primary',
    'completed' => 'success',
    'cancelled' => 'danger',
];

require_once 'includes/header.php';
?>

<style>
    .dap-green   { color: #2e7d32 !important; }
    .bg-dap      { background-color: #2e7d32 !important; }
    .btn-dap     { background-color: #2e7d32; color:#fff; border:none; }
    .btn-dap:hover { background-color: #1b5e20; color:#fff; }
    .item-row-table thead th { background:#f0f4f0; font-size:.8rem; }
    .budget-warn { font-size:.75rem; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="dap-green"><i class="bi bi-calendar2-check"></i> Daily Activity Plan</h1>
            <p class="text-muted mb-0">Field Operations &rsaquo; <b>Daily Activity Plan</b></p>
        </div>
        <div class="col-auto">
            <button class="btn btn-dap" data-bs-toggle="modal" data-bs-target="#addPlanModal">
                <i class="bi bi-plus-circle"></i> New Plan
            </button>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-header bg-dap text-white py-2"><i class="bi bi-funnel"></i> Filter Plans</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = date('Y')-2; $y <= date('Y')+1; $y++): ?>
                        <option value="<?=$y?>" <?=$y==$filter_year?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <option value="" <?=$filter_month===''?'selected':''?>>All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?=$m?>" <?=$m==(int)$filter_month?'selected':''?>>
                            <?=date('F', mktime(0,0,0,$m,1))?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-1">Division</label>
                <select name="division_id" class="form-select form-select-sm">
                    <option value="">All Divisions</option>
                    <?php foreach ($divisions as $d): ?>
                        <option value="<?=$d['division_id']?>" <?=$d['division_id']==$filter_div?'selected':''?>>
                            <?=htmlspecialchars($d['division_name'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <?php foreach (array_keys($status_colours) as $s): ?>
                        <option value="<?=$s?>" <?=$s===$filter_status?'selected':''?>><?=ucfirst($s)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-dap btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="daily_activity_plan.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- KPI row -->
<?php
$total_plans   = count($plans);
$draft_cnt     = count(array_filter($plans, fn($p)=>$p['status']==='draft'));
$completed_cnt = count(array_filter($plans, fn($p)=>$p['status']==='completed'));
$total_cost    = array_sum(array_column($plans,'total_estimated_cost'));
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Total Plans</div><div class="fw-bold fs-4"><?=$total_plans?></div>
        <div class="small text-muted"><?=$draft_cnt?> draft · <?=$completed_cnt?> completed</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Est. Cost (Rp)</div>
        <div class="fw-bold fs-5"><?=number_format($total_cost/1000000,1)?>M</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Filter Period</div>
        <div class="fw-bold"><?=$filter_month !== '' ? date('F Y', mktime(0,0,0,(int)$filter_month,1,$filter_year)) : 'All ' . $filter_year?></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Divisions Active</div>
        <div class="fw-bold fs-4"><?=count(array_unique(array_column($plans,'division_id')))?></div>
    </div></div></div>
</div>

<!-- Plans Table -->
<div class="card">
    <div class="card-header bg-dap text-white py-2">
        <i class="bi bi-table"></i> <?=$total_plans?> Daily Activity Plan(s)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:28px"></th>
                        <th>Plan #</th>
                        <th>Date</th>
                        <th>Division</th>
                        <th>Supervisor</th>
                        <th class="text-center">Items</th>
                        <th class="text-end">Est. Cost (Rp)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($plans)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No plans found for this filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($plans as $plan): ?>
                    <?php $items = $plan_items_map[$plan['plan_id']] ?? []; ?>
                    <tr class="plan-row" data-bs-toggle="collapse"
                        data-bs-target="#items-<?=$plan['plan_id']?>"
                        style="cursor:pointer">
                        <td class="text-center">
                            <i class="bi bi-chevron-down small text-muted"></i>
                        </td>
                        <td class="fw-bold dap-green"><?=htmlspecialchars($plan['plan_number'])?></td>
                        <td><?=date('d/m/Y', strtotime($plan['plan_date']))?></td>
                        <td><?=htmlspecialchars($plan['division_name'] ?? '—')?></td>
                        <td><?=htmlspecialchars($plan['supervisor'])?></td>
                        <td class="text-center">
                            <span class="badge bg-light text-dark border"><?=$plan['item_count']?></span>
                            <?php if ((int)$plan['over_budget_items'] > 0): ?>
                                <span class="badge bg-warning text-dark" title="Over-budget items">
                                    <i class="bi bi-exclamation-triangle"></i> <?=$plan['over_budget_items']?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?=number_format($plan['total_estimated_cost'],0)?></td>
                        <td>
                            <span class="badge bg-<?=$status_colours[$plan['status']]??'secondary'?>">
                                <?=ucfirst($plan['status'])?>
                            </span>
                        </td>
                        <td onclick="event.stopPropagation()">
                            <?php if ($plan['status'] === 'draft'): ?>
                                <button class="btn btn-sm btn-outline-primary" title="Edit"
                                        onclick="openEditModal(<?=htmlspecialchars(json_encode($plan))?>, <?=htmlspecialchars(json_encode($items))?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Cancel this plan?')">
                                    <input type="hidden" name="action" value="cancel_plan">
                                    <input type="hidden" name="plan_id" value="<?=$plan['plan_id']?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </form>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Mark as completed?')">
                                    <input type="hidden" name="action" value="complete_plan">
                                    <input type="hidden" name="plan_id" value="<?=$plan['plan_id']?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Complete">
                                        <i class="bi bi-check2-all"></i>
                                    </button>
                                </form>
                            <?php elseif ($plan['status'] === 'completed'): ?>
                                <span class="text-muted small"><i class="bi bi-check-circle-fill text-success"></i> Done</span>
                            <?php elseif ($plan['status'] === 'cancelled'): ?>
                                <span class="text-muted small"><i class="bi bi-x-circle text-danger"></i> Cancelled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Expandable item detail row -->
                    <tr class="collapse" id="items-<?=$plan['plan_id']?>">
                        <td colspan="9" class="bg-light p-0">
                            <?php if (empty($items)): ?>
                                <p class="text-muted small px-3 py-2 mb-0">No items in this plan.</p>
                            <?php else: ?>
                            <div class="px-4 py-2">
                                <?php
                                // Check if any item has 0 material requirements
                                $zero_mr = array_filter($items, fn($it)=>(int)$it['mr_count']===0);
                                if ($zero_mr): ?>
                                    <div class="alert alert-warning py-1 px-2 mb-2 small">
                                        <i class="bi bi-info-circle"></i>
                                        <?=count($zero_mr)?> item(s) have 0 material requirements generated
                                        (no norm materials configured for those activities).
                                    </div>
                                <?php endif; ?>
                                <table class="table table-sm table-bordered item-row-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Block</th>
                                            <th>Activity</th>
                                            <th class="text-end">Area (ha)</th>
                                            <th class="text-end">Qty</th>
                                            <th class="text-center">Workers</th>
                                            <th class="text-end">Man-days</th>
                                            <th class="text-end">Est. Cost</th>
                                            <th class="text-end">Budget Left</th>
                                            <th class="text-center">Mat. Reqs</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($items as $it): ?>
                                        <?php
                                        $over = ($it['budget_remaining'] !== null)
                                                && ((float)$it['estimated_cost'] > (float)$it['budget_remaining']);
                                        ?>
                                        <tr class="<?=$over?'table-warning':''?>">
                                            <td><?=htmlspecialchars($it['block_code']??'')?> <?=htmlspecialchars($it['block_name']??'')?></td>
                                            <td><?=htmlspecialchars($it['activity_code']??'')?> <?=htmlspecialchars($it['activity_name']??'')?></td>
                                            <td class="text-end"><?=number_format((float)$it['planned_area'],2)?></td>
                                            <td class="text-end"><?=number_format((float)$it['planned_quantity'],2)?></td>
                                            <td class="text-center"><?=$it['planned_workers']?></td>
                                            <td class="text-end"><?=number_format((float)$it['planned_man_days'],2)?></td>
                                            <td class="text-end"><?=number_format((float)$it['estimated_cost'],0)?></td>
                                            <td class="text-end">
                                                <?php if ($it['budget_remaining'] !== null): ?>
                                                    <span class="<?=$over?'text-danger fw-bold':'text-success'?>">
                                                        <?=number_format((float)$it['budget_remaining'],0)?>
                                                    </span>
                                                    <?php if ($over): ?>
                                                        <br><span class="badge bg-warning text-dark budget-warn">Over budget</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-<?=(int)$it['mr_count']>0?'info':'light text-muted'?>">
                                                    <?=$it['mr_count']?>
                                                </span>
                                            </td>
                                            <td><span class="badge bg-secondary"><?=ucfirst($it['status'])?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ────────────────────────────── Add Plan Modal ──────────────────────────── -->
<div class="modal fade" id="addPlanModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form method="POST" id="addPlanForm">
            <input type="hidden" name="action" value="create_plan">
            <div class="modal-content">
                <div class="modal-header bg-dap text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> New Daily Activity Plan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php echo render_plan_form_fields($divisions, $activities, null); ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-dap"><i class="bi bi-save"></i> Save Plan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ────────────────────────────── Edit Plan Modal ─────────────────────────── -->
<div class="modal fade" id="editPlanModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form method="POST" id="editPlanForm">
            <input type="hidden" name="action" value="update_plan">
            <input type="hidden" name="plan_id" id="editPlanId">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Daily Activity Plan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editPlanBody">
                    <!-- Populated by JS -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Update Plan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
// ─── Helper: render plan form fields (used by add modal) ─────────────────────
function render_plan_form_fields(array $divisions, array $activities, ?array $plan): string {
    $pd = $plan ? htmlspecialchars($plan['plan_date']) : date('Y-m-d');
    $div= $plan ? (int)$plan['division_id'] : 0;
    $sup= $plan ? htmlspecialchars($plan['supervisor']) : '';
    $notes= $plan ? htmlspecialchars($plan['notes'] ?? '') : '';

    $div_opts = '';
    foreach ($divisions as $d) {
        $sel = $d['division_id'] == $div ? 'selected' : '';
        $div_opts .= "<option value=\"{$d['division_id']}\" $sel>".htmlspecialchars($d['division_name'])."</option>";
    }

    $act_opts = '<option value="">— Activity —</option>';
    foreach ($activities as $a) {
        $act_opts .= "<option value=\"{$a['id']}\">".htmlspecialchars($a['activity_code'].' '.$a['activity_name'])."</option>";
    }

    return <<<HTML
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <label class="form-label">Plan Date *</label>
        <input type="date" name="plan_date" class="form-control form-control-sm plan-date-input"
               value="$pd" required>
    </div>
    <div class="col-md-4">
        <label class="form-label">Division *</label>
        <select name="division_id" class="form-select form-select-sm div-select" required onchange="onDivisionChange(this)">
            <option value="">— Select Division —</option>
            $div_opts
        </select>
    </div>
    <div class="col-md-5">
        <label class="form-label">Supervisor *</label>
        <input type="text" name="supervisor" class="form-control form-control-sm"
               value="$sup" required placeholder="Supervisor name">
    </div>
    <div class="col-12">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control form-control-sm" rows="2">$notes</textarea>
    </div>
</div>
<hr>
<div class="d-flex justify-content-between align-items-center mb-2">
    <strong><i class="bi bi-list-ul"></i> Activity Items</strong>
    <button type="button" class="btn btn-sm btn-outline-success" onclick="addItemRow(this)">
        <i class="bi bi-plus"></i> Add Row
    </button>
</div>
<div class="table-responsive">
    <table class="table table-sm table-bordered items-table">
        <thead class="table-light">
            <tr>
                <th>Block <small class="text-muted">(select division first)</small></th>
                <th>Activity</th>
                <th>Area (ha)</th>
                <th>Quantity</th>
                <th>Workers</th>
                <th>Budget Remaining</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="items-tbody">
            <!-- rows added by JS or server-side for edit -->
        </tbody>
    </table>
</div>
HTML;
}
?>

<!-- JS data for budget warnings and blocks-by-division -->
<script>
const blocksData   = <?=json_encode($blocks_by_div, JSON_HEX_TAG)?>;
const budgetData   = <?=json_encode($budget_data_raw, JSON_HEX_TAG)?>;
const activitiesJS = <?=json_encode(array_values($activities), JSON_HEX_TAG)?>;

function getBlockOptions(divisionId, selectedBlockId) {
    const blocks = blocksData[divisionId] || [];
    let opts = '<option value="">— Block —</option>';
    blocks.forEach(b => {
        const sel = (b.block_id == selectedBlockId) ? 'selected' : '';
        opts += `<option value="${b.block_id}" ${sel}>${b.block_code||''} ${b.block_name}</option>`;
    });
    return opts;
}

function onDivisionChange(sel) {
    const divId = sel.value;
    const form  = sel.closest('form');
    form.querySelectorAll('.block-select').forEach(bs => {
        bs.innerHTML = getBlockOptions(divId, '');
    });
}

function addItemRow(btn, blockId, activityId, area, qty, workers) {
    const form   = btn.closest('form');
    const tbody  = form.querySelector('.items-tbody');
    const divId  = form.querySelector('.div-select')?.value || '';
    const blockOpts = getBlockOptions(divId, blockId || '');
    let actOpts  = '<option value="">— Activity —</option>';
    activitiesJS.forEach(a => {
        const sel = (a.id == activityId) ? 'selected' : '';
        actOpts += `<option value="${a.id}" ${sel}>${a.activity_code} ${a.activity_name}</option>`;
    });

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><select name="block_id[]" class="form-select form-select-sm block-select" required
                    onchange="updateBudgetBadge(this)">${blockOpts}</select></td>
        <td><select name="activity_id[]" class="form-select form-select-sm act-select" required
                    onchange="updateBudgetBadge(this)">${actOpts}</select></td>
        <td><input type="number" step="0.01" name="planned_area[]"
                   class="form-control form-control-sm" value="${area||''}" placeholder="0.00"></td>
        <td><input type="number" step="0.01" name="planned_quantity[]"
                   class="form-control form-control-sm" value="${qty||''}" placeholder="0.00"></td>
        <td><input type="number" name="planned_workers[]"
                   class="form-control form-control-sm" value="${workers||''}" placeholder="0"></td>
        <td class="budget-cell"><span class="text-muted small">—</span></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
    // Trigger badge update if pre-filled
    if (blockId && activityId) updateBudgetBadge(tr.querySelector('.block-select'));
}

function updateBudgetBadge(el) {
    const tr      = el.closest('tr');
    const blockId = tr.querySelector('.block-select')?.value || '';
    const actId   = tr.querySelector('.act-select')?.value   || '';
    const cell    = tr.querySelector('.budget-cell');
    if (!blockId || !actId) { cell.innerHTML = '<span class="text-muted small">—</span>'; return; }
    const key  = blockId + '_' + actId;
    const bud  = budgetData[key];
    if (!bud) { cell.innerHTML = '<span class="text-muted small">No budget</span>'; return; }
    const rem  = bud.remaining;
    const cls  = rem < 0 ? 'danger' : (rem < bud.planned * 0.1 ? 'warning' : 'success');
    const warn = rem < 0 ? ' <span class="badge bg-danger">Over budget</span>' : '';
    cell.innerHTML = `<span class="badge bg-${cls}">Rp ${rem.toLocaleString('id-ID',{maximumFractionDigits:0})}</span>${warn}`;
}

function openEditModal(plan, items) {
    document.getElementById('editPlanId').value = plan.plan_id;

    // Clone add-form HTML structure
    const divs = <?=json_encode($divisions, JSON_HEX_TAG)?>;
    let divOpts = '<option value="">— Select Division —</option>';
    divs.forEach(d => {
        const sel = (d.division_id == plan.division_id) ? 'selected' : '';
        divOpts += `<option value="${d.division_id}" ${sel}>${d.division_name}</option>`;
    });
    let actOpts = '<option value="">— Activity —</option>';
    activitiesJS.forEach(a => {
        actOpts += `<option value="${a.id}">${a.activity_code} ${a.activity_name}</option>`;
    });

    const html = `
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <label class="form-label">Plan Date *</label>
            <input type="date" name="plan_date" class="form-control form-control-sm plan-date-input"
                   value="${plan.plan_date}" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Division *</label>
            <select name="division_id" class="form-select form-select-sm div-select" required
                    onchange="onDivisionChange(this)">${divOpts}</select>
        </div>
        <div class="col-md-5">
            <label class="form-label">Supervisor *</label>
            <input type="text" name="supervisor" class="form-control form-control-sm"
                   value="${plan.supervisor||''}" required>
        </div>
        <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control form-control-sm" rows="2">${plan.notes||''}</textarea>
        </div>
    </div>
    <hr>
    <div class="d-flex justify-content-between align-items-center mb-2">
        <strong><i class="bi bi-list-ul"></i> Activity Items</strong>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="addItemRow(this)">
            <i class="bi bi-plus"></i> Add Row
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered items-table">
            <thead class="table-light">
                <tr>
                    <th>Block</th><th>Activity</th>
                    <th>Area (ha)</th><th>Quantity</th><th>Workers</th>
                    <th>Budget Remaining</th><th></th>
                </tr>
            </thead>
            <tbody class="items-tbody" id="editItemsTbody"></tbody>
        </table>
    </div>`;

    document.getElementById('editPlanBody').innerHTML = html;

    // Pre-populate existing items
    const fakeBtn = document.querySelector('#editPlanBody .btn-outline-success');
    items.forEach(it => {
        addItemRow(fakeBtn, it.block_id, it.activity_id,
                   it.planned_area, it.planned_quantity, it.planned_workers);
    });
    // If no items, add one empty row
    if (!items.length) addItemRow(fakeBtn);

    const modal = new bootstrap.Modal(document.getElementById('editPlanModal'));
    modal.show();
}

// Add one empty row automatically when Add Plan modal opens
document.getElementById('addPlanModal').addEventListener('show.bs.modal', function() {
    const tbody = this.querySelector('.items-tbody');
    if (!tbody.children.length) {
        addItemRow(this.querySelector('.btn-outline-success'));
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
