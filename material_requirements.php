<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_material_req');

// ─── Helpers ──────────────────────────────────────────────────────────────────
function gen_pr_number(PDO $db): string {
    $ym     = date('Ym');
    $prefix = 'PR-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(pr_number,'-',-1) AS UNSIGNED)),0)+1
        FROM purchase_requisitions WHERE pr_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── POST handlers ────────────────────────────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = post('action');
    $plan_id = (int)post('plan_id');

    // ── split_and_process ────────────────────────────────────────────────────
    if ($action === 'split_and_process' && $plan_id) {
        try {
            $db->beginTransaction();

            // Fetch plan info for context
            $plan = $db->prepare("
                SELECT dap.*, d.division_name
                FROM daily_activity_plans dap
                LEFT JOIN divisions d ON dap.division_id = d.division_id
                WHERE dap.plan_id = ?
            ");
            $plan->execute([$plan_id]);
            $planRow = $plan->fetch();

            if (!$planRow) { throw new Exception('Plan not found'); }

            $req_ids      = array_map('intval', (array)post('req_id'));
            $issue_qtys   = (array)post('issue_qty');
            $purchase_qtys= (array)post('purchase_qty');

            $issued_count   = 0;
            $purchase_items = []; // [ req_id => [material_id, qty, unit, unit_price, warehouse_id] ]

            foreach ($req_ids as $i => $req_id) {
                $issue_qty    = max(0, (float)($issue_qtys[$i]   ?? 0));
                $purchase_qty = max(0, (float)($purchase_qtys[$i] ?? 0));

                // Fetch requirement detail
                $reqStmt = $db->prepare("
                    SELECT mr.*,
                           m.material_name, m.unit, m.unit_price AS mat_unit_price,
                           a.activity_name
                    FROM material_requirements mr
                    JOIN materials m ON m.material_id = mr.material_id
                    JOIN daily_activity_plan_items dapi ON dapi.item_id = mr.plan_item_id
                    JOIN activities a ON a.id = dapi.activity_id
                    WHERE mr.req_id = ? AND mr.plan_id = ? AND mr.status = 'pending'
                ");
                $reqStmt->execute([$req_id, $plan_id]);
                $req = $reqStmt->fetch();
                if (!$req) continue;

                // Clamp issue_qty to current_stock
                $max_issue = min((float)$req['current_stock'], (float)$req['required_qty']);
                if ($issue_qty > $max_issue) $issue_qty = $max_issue;

                // UPDATE material_requirements
                $upd = $db->prepare("
                    UPDATE material_requirements
                    SET issue_qty = ?, purchase_qty = ?, updated_by = 'admin'
                    WHERE req_id = ?
                ");
                $upd->execute([$issue_qty, $purchase_qty, $req_id]);

                // If issuing: INSERT material_transaction OUT
                if ($issue_qty > 0) {
                    $warehouse_id = (int)$req['warehouse_id'];
                    $unit_price   = (float)$req['mat_unit_price'];
                    $remarks      = $req['activity_name'] . ' — ' . $planRow['plan_number'];
                    $total_value  = $issue_qty * $unit_price;
                    $ins = $db->prepare("
                        INSERT INTO material_transactions
                            (transaction_date, transaction_type, warehouse_id,
                             material_id, quantity, unit_price, total_value,
                             reference_no, remarks, created_by)
                        VALUES (CURDATE(), 'out', ?, ?, ?, ?, ?, ?, ?, 'admin')
                    ");
                    $ins->execute([
                        $warehouse_id,
                        (int)$req['material_id'],
                        $issue_qty,
                        $unit_price,
                        $total_value,
                        $planRow['plan_number'],
                        $remarks,
                    ]);
                    $issued_count++;
                }

                if ($purchase_qty > 0) {
                    $purchase_items[$req_id] = [
                        'material_id'  => (int)$req['material_id'],
                        'qty'          => $purchase_qty,
                        'unit'         => $req['unit'],
                        'unit_price'   => (float)$req['mat_unit_price'],
                        'warehouse_id' => (int)$req['warehouse_id'],
                    ];
                }
            }

            // Create PR if any purchase items
            $pr_number = null;
            if (!empty($purchase_items)) {
                $pr_number = gen_pr_number($db);
                $insPR = $db->prepare("
                    INSERT INTO purchase_requisitions
                        (pr_number, pr_date, division_id, requested_by, status, created_by)
                    VALUES (?, CURDATE(), ?, 'admin', 'draft', 'admin')
                ");
                $insPR->execute([$pr_number, (int)$planRow['division_id']]);
                $pr_id = (int)$db->lastInsertId();

                foreach ($purchase_items as $req_id => $pi) {
                    $insPRItem = $db->prepare("
                        INSERT INTO pr_items
                            (pr_id, material_req_id, material_id, required_qty,
                             approved_qty, unit, estimated_unit_price)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insPRItem->execute([
                        $pr_id, $req_id, $pi['material_id'],
                        $pi['qty'], $pi['qty'], $pi['unit'], $pi['unit_price'],
                    ]);
                }

                // Link pr_id back to each requirement that has purchase qty
                foreach (array_keys($purchase_items) as $req_id) {
                    $db->prepare("UPDATE material_requirements SET pr_id = ? WHERE req_id = ?")
                       ->execute([$pr_id, $req_id]);
                }
            }

            // Update status on processed requirements
            foreach ($req_ids as $i => $req_id) {
                $issue_qty    = max(0, (float)($issue_qtys[$i]   ?? 0));
                $purchase_qty = max(0, (float)($purchase_qtys[$i] ?? 0));
                if ($issue_qty > 0 || $purchase_qty > 0) {
                    $new_status = ($purchase_qty > 0) ? 'partially_fulfilled' : 'fulfilled';
                    $db->prepare("
                        UPDATE material_requirements SET status = ? WHERE req_id = ? AND status = 'pending'
                    ")->execute([$new_status, $req_id]);
                }
            }

            // Check if all requirements for this plan are now non-pending
            $pendingCount = $db->prepare("
                SELECT COUNT(*) FROM material_requirements WHERE plan_id = ? AND status = 'pending'
            ");
            $pendingCount->execute([$plan_id]);
            if ((int)$pendingCount->fetchColumn() === 0) {
                $db->prepare("UPDATE daily_activity_plans SET status='submitted' WHERE plan_id=? AND status='draft'")
                   ->execute([$plan_id]);
            }

            $db->commit();

            $pr_msg = $pr_number ? " PR <strong>{$pr_number}</strong> raised for " . count($purchase_items) . " item(s)." : '';
            $flash  = "<div class='alert alert-success alert-dismissible fade show' role='alert'>"
                    . "Issued <strong>{$issued_count}</strong> material(s) from stock.{$pr_msg}"
                    . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $flash = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>"
                   . "Error: " . htmlspecialchars($e->getMessage())
                   . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }

    // ── refresh_stock ────────────────────────────────────────────────────────
    if ($action === 'refresh_stock' && $plan_id) {
        try {
            $upd = $db->prepare("
                UPDATE material_requirements mr
                JOIN vw_material_stock_summary vss ON vss.material_id = mr.material_id
                SET mr.current_stock = vss.current_stock, mr.updated_by = 'admin'
                WHERE mr.plan_id = ? AND mr.status = 'pending'
            ");
            $upd->execute([$plan_id]);
            $rows = $upd->rowCount();
            $flash = "<div class='alert alert-info alert-dismissible fade show' role='alert'>"
                   . "Stock refreshed for <strong>{$rows}</strong> pending requirement(s)."
                   . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } catch (Exception $e) {
            $flash = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>"
                   . "Error: " . htmlspecialchars($e->getMessage())
                   . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$filter_date_from  = get('date_from', date('Y-01-01'));
$filter_date_to    = get('date_to',   date('Y-m-t'));
$filter_division   = get('division_id', '');
$filter_status     = get('status', '');

// ─── Reference data ───────────────────────────────────────────────────────────
$divisions = $db->query("SELECT division_id, division_name FROM divisions ORDER BY division_name")->fetchAll();

// ─── KPI data ─────────────────────────────────────────────────────────────────
$kpi = ['pending_reqs' => 0, 'total_purchase_value' => 0, 'plans_with_open' => 0];
try {
    $kRow = $db->query("
        SELECT
            SUM(CASE WHEN mr.status='pending' THEN 1 ELSE 0 END) AS pending_reqs,
            SUM(mr.purchase_qty * m.unit_price)                   AS total_purchase_value,
            COUNT(DISTINCT CASE WHEN mr.status='pending' THEN mr.plan_id END) AS plans_with_open
        FROM material_requirements mr
        JOIN materials m ON m.material_id = mr.material_id
    ")->fetch();
    if ($kRow) $kpi = $kRow;
} catch (Exception $e) {}

// ─── Fetch plans with their requirement counts ─────────────────────────────────
$plans_sql  = "
    SELECT dap.plan_id, dap.plan_number, dap.plan_date, dap.supervisor, dap.status AS plan_status,
           d.division_name,
           COUNT(mr.req_id)                                             AS total_reqs,
           SUM(CASE WHEN mr.status='pending' THEN 1 ELSE 0 END)        AS pending_count
    FROM daily_activity_plans dap
    JOIN divisions d ON d.division_id = dap.division_id
    LEFT JOIN material_requirements mr ON mr.plan_id = dap.plan_id
    WHERE dap.plan_date BETWEEN ? AND ?
";
$params = [$filter_date_from, $filter_date_to];
if ($filter_division) { $plans_sql .= " AND dap.division_id = ?"; $params[] = $filter_division; }
if ($filter_status)   { $plans_sql .= " AND mr.status = ?";       $params[] = $filter_status; }
$plans_sql .= "
    GROUP BY dap.plan_id
    HAVING total_reqs > 0
    ORDER BY dap.plan_date DESC, dap.plan_number
";
$plansStmt = $db->prepare($plans_sql);
$plansStmt->execute($params);
$plans = $plansStmt->fetchAll();

// Fetch requirements per plan (keyed by plan_id)
$all_requirements = [];
if (!empty($plans)) {
    $plan_ids = array_column($plans, 'plan_id');
    $in_placeholders = implode(',', array_fill(0, count($plan_ids), '?'));
    $reqStmt = $db->prepare("
        SELECT mr.*,
               m.material_name, m.category, m.unit, m.unit_price AS mat_unit_price, m.reorder_level,
               w.warehouse_name,
               a.activity_name,
               COALESCE(vss.current_stock, 0) AS live_stock
        FROM material_requirements mr
        JOIN materials m ON m.material_id = mr.material_id
        LEFT JOIN material_warehouses w ON w.warehouse_id = mr.warehouse_id
        JOIN daily_activity_plan_items dapi ON dapi.item_id = mr.plan_item_id
        JOIN activities a ON a.id = dapi.activity_id
        LEFT JOIN vw_material_stock_summary vss ON vss.material_id = mr.material_id
        WHERE mr.plan_id IN ($in_placeholders)
        ORDER BY mr.plan_id, mr.req_id
    ");
    $reqStmt->execute($plan_ids);
    foreach ($reqStmt->fetchAll() as $r) {
        $all_requirements[$r['plan_id']][] = $r;
    }
}

require_once 'includes/header.php';
?>

<?php if ($flash): echo $flash; endif; ?>

<div class="container-fluid">

    <!-- Page header -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h4 class="mb-0"><i class="bi bi-boxes me-2 text-primary"></i>Material Requirements</h4>
    </div>

    <!-- ── Filter bar ─────────────────────────────────────────────────────── -->
    <div class="card mb-3 shadow-sm">
        <div class="card-body py-2">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label form-label-sm mb-1">From Date</label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm mb-1">To Date</label>
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-1">Division</label>
                    <select name="division_id" class="form-select form-select-sm">
                        <option value="">All Divisions</option>
                        <?php foreach ($divisions as $div): ?>
                        <option value="<?php echo $div['division_id']; ?>"
                            <?php echo ($filter_division == $div['division_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($div['division_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm mb-1">Req Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option value="pending"              <?php echo $filter_status === 'pending'              ? 'selected' : ''; ?>>Pending</option>
                        <option value="partially_fulfilled"  <?php echo $filter_status === 'partially_fulfilled'  ? 'selected' : ''; ?>>Partially Fulfilled</option>
                        <option value="fulfilled"            <?php echo $filter_status === 'fulfilled'            ? 'selected' : ''; ?>>Fulfilled</option>
                        <option value="cancelled"            <?php echo $filter_status === 'cancelled'            ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                </div>
                <div class="col-md-1">
                    <a href="material_requirements.php" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── KPI Cards ──────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 bg-warning bg-opacity-10 h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">Pending Requirements</div>
                    <div class="fs-3 fw-bold text-warning"><?php echo number_format((int)$kpi['pending_reqs']); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 bg-danger bg-opacity-10 h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">Est. Purchase Value</div>
                    <div class="fs-3 fw-bold text-danger"><?php echo number_format((float)$kpi['total_purchase_value']); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 bg-primary bg-opacity-10 h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">Plans with Open Requirements</div>
                    <div class="fs-3 fw-bold text-primary"><?php echo number_format((int)$kpi['plans_with_open']); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Plan cards ─────────────────────────────────────────────────────── -->
    <?php if (empty($plans)): ?>
    <div class="alert alert-info">No material requirements found for the selected period.</div>
    <?php else: ?>

    <?php foreach ($plans as $plan):
        $reqs         = $all_requirements[$plan['plan_id']] ?? [];
        $hasPending   = ((int)$plan['pending_count']) > 0;
        $collapseId   = 'plan_' . $plan['plan_id'];
        $statusColors = ['draft'=>'secondary','submitted'=>'primary','completed'=>'success','cancelled'=>'secondary'];
        $planStatusColor = $statusColors[$plan['plan_status']] ?? 'secondary';
    ?>

    <div class="card mb-3 shadow-sm">
        <!-- Card header -->
        <div class="card-header d-flex align-items-center justify-content-between"
             style="cursor:pointer" onclick="toggleCard('<?php echo $collapseId; ?>')">
            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold"><?php echo htmlspecialchars($plan['plan_number']); ?></span>
                <span class="text-muted small"><?php echo htmlspecialchars($plan['plan_date']); ?></span>
                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($plan['division_name']); ?></span>
                <span class="text-muted small"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($plan['supervisor']); ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($hasPending): ?>
                <span class="badge bg-warning text-dark"><?php echo $plan['pending_count']; ?> pending</span>
                <?php endif; ?>
                <span class="badge bg-<?php echo $planStatusColor; ?>"><?php echo ucfirst($plan['plan_status']); ?></span>
                <i class="bi bi-chevron-down text-muted" id="icon_<?php echo $collapseId; ?>"></i>
            </div>
        </div>

        <!-- Card body (collapsible) -->
        <div id="<?php echo $collapseId; ?>" class="card-body p-0" style="display:none;">

            <!-- Refresh stock button -->
            <?php if ($hasPending): ?>
            <div class="px-3 pt-3 pb-1 d-flex justify-content-end">
                <form method="post" class="d-inline">
                    <input type="hidden" name="action"  value="refresh_stock">
                    <input type="hidden" name="plan_id" value="<?php echo $plan['plan_id']; ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise me-1"></i>Refresh Stock
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action"  value="split_and_process">
                <input type="hidden" name="plan_id" value="<?php echo $plan['plan_id']; ?>">

                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Material</th>
                                <th class="text-end">Required</th>
                                <th class="text-end">Current Stock</th>
                                <th class="text-center">Stock Status</th>
                                <th style="width:120px" class="text-end">Issue Qty</th>
                                <th style="width:120px" class="text-end">Purchase Qty</th>
                                <th>Warehouse</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reqs as $idx => $req):
                            $req_id       = $req['req_id'];
                            $required_qty = (float)$req['required_qty'];
                            $live_stock   = (float)$req['live_stock'];
                            $curr_stock   = (float)$req['current_stock'];   // snapshot
                            $display_stock = $curr_stock;                   // show snapshot

                            // Stock colour
                            if ($display_stock <= 0) {
                                $stock_color = 'danger';  $stock_badge = 'OUT';      $badge_class = 'bg-danger';
                            } elseif ($display_stock < $required_qty) {
                                $stock_color = 'danger';  $stock_badge = 'CRITICAL'; $badge_class = 'bg-danger';
                            } elseif ($display_stock < $required_qty * 1.5) {
                                $stock_color = 'warning'; $stock_badge = 'LOW';      $badge_class = 'bg-warning text-dark';
                            } else {
                                $stock_color = 'success'; $stock_badge = 'OK';       $badge_class = 'bg-success';
                            }

                            $max_issue    = min($display_stock, $required_qty);
                            $default_issue= max(0, $max_issue);
                            $default_purch= max(0, $required_qty - $default_issue);
                            $isPending    = $req['status'] === 'pending';
                            $statusBadges = ['pending'=>'warning','partially_fulfilled'=>'info','fulfilled'=>'success','cancelled'=>'secondary'];
                            $reqBadgeClass = $statusBadges[$req['status']] ?? 'secondary';
                        ?>
                        <tr>
                            <td>
                                <input type="hidden" name="req_id[]" value="<?php echo $req_id; ?>">
                                <div class="fw-medium"><?php echo htmlspecialchars($req['material_name']); ?></div>
                                <span class="badge bg-light text-dark border" style="font-size:0.7rem;">
                                    <?php echo htmlspecialchars($req['category']); ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <?php echo number_format($required_qty, 2); ?>
                                <small class="text-muted"><?php echo htmlspecialchars($req['unit']); ?></small>
                            </td>
                            <td class="text-end text-<?php echo $stock_color; ?> fw-medium">
                                <?php echo number_format($display_stock, 2); ?>
                                <small class="text-muted"><?php echo htmlspecialchars($req['unit']); ?></small>
                            </td>
                            <td class="text-center">
                                <span class="badge <?php echo $badge_class; ?>"><?php echo $stock_badge; ?></span>
                            </td>
                            <?php if ($isPending): ?>
                            <td class="text-end">
                                <input type="number" name="issue_qty[]"
                                       class="form-control form-control-sm text-end issue-input"
                                       data-max="<?php echo $max_issue; ?>"
                                       data-required="<?php echo $required_qty; ?>"
                                       data-idx="<?php echo $idx; ?>"
                                       min="0" max="<?php echo $max_issue; ?>" step="0.01"
                                       value="<?php echo number_format($default_issue, 2, '.', ''); ?>"
                                       <?php echo $max_issue <= 0 ? 'disabled' : ''; ?>>
                                <?php if ($max_issue <= 0): ?>
                                    <input type="hidden" name="issue_qty[]" value="0">
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <input type="number" name="purchase_qty[]"
                                       class="form-control form-control-sm text-end purchase-input"
                                       data-idx="<?php echo $idx; ?>"
                                       min="0" step="0.01"
                                       value="<?php echo number_format($default_purch, 2, '.', ''); ?>">
                            </td>
                            <?php else: ?>
                            <td class="text-end text-muted">
                                <?php echo number_format((float)$req['issue_qty'], 2); ?>
                                <input type="hidden" name="issue_qty[]" value="<?php echo (float)$req['issue_qty']; ?>">
                            </td>
                            <td class="text-end text-muted">
                                <?php echo number_format((float)$req['purchase_qty'], 2); ?>
                                <input type="hidden" name="purchase_qty[]" value="<?php echo (float)$req['purchase_qty']; ?>">
                            </td>
                            <?php endif; ?>
                            <td>
                                <small><?php echo htmlspecialchars($req['warehouse_name'] ?? '—'); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $reqBadgeClass; ?> text-<?php echo $reqBadgeClass === 'bg-warning text-dark' ? 'dark' : 'white'; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $req['status'])); ?>
                                </span>
                                <?php if ($req['pr_id']): ?>
                                <a href="purchase_requisitions.php?pr_id=<?php echo $req['pr_id']; ?>"
                                   class="badge bg-light text-primary border text-decoration-none" style="font-size:0.7rem;">
                                    PR #<?php echo $req['pr_id']; ?>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($hasPending): ?>
                <div class="px-3 py-2 text-end border-top">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2-circle me-1"></i>Issue from Stock &amp; Raise PR
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php endforeach; ?>
    <?php endif; ?>

</div><!-- /container-fluid -->

<script>
function toggleCard(id) {
    const el   = document.getElementById(id);
    const icon = document.getElementById('icon_' + id);
    if (!el) return;
    if (el.style.display === 'none') {
        el.style.display = '';
        if (icon) { icon.classList.remove('bi-chevron-down'); icon.classList.add('bi-chevron-up'); }
    } else {
        el.style.display = 'none';
        if (icon) { icon.classList.remove('bi-chevron-up'); icon.classList.add('bi-chevron-down'); }
    }
}

// Auto-expand a plan card if it has pending items after a flash (page reload)
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.issue-input').forEach(function(input) {
        input.addEventListener('input', function() {
            const idx      = this.dataset.idx;
            const required = parseFloat(this.dataset.required) || 0;
            const issued   = Math.min(parseFloat(this.value) || 0, parseFloat(this.dataset.max) || 0);
            const purchInput = document.querySelector('.purchase-input[data-idx="' + idx + '"]');
            if (purchInput) {
                const purch = Math.max(0, required - issued);
                purchInput.value = purch.toFixed(2);
            }
        });
    });

    // Auto-open first pending plan on load
    <?php foreach ($plans as $plan): if ((int)$plan['pending_count'] > 0): ?>
    toggleCard('plan_<?php echo $plan['plan_id']; ?>');
    <?php break; endif; endforeach; ?>
});
</script>

<?php require_once 'includes/footer.php'; ?>
