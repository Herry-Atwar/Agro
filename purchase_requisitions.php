<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db         = getDB();
$page_title = __('pt_purchase_req');

// ─── Number generators ────────────────────────────────────────────────────────
function gen_pr_number_pr(PDO $db): string {
    $ym     = date('Ym');
    $prefix = 'PR-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(pr_number,'-',-1) AS UNSIGNED)),0)+1
        FROM purchase_requisitions WHERE pr_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

function gen_po_number(PDO $db): string {
    $ym     = date('Ym');
    $prefix = 'PO-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(po_number,'-',-1) AS UNSIGNED)),0)+1
        FROM purchase_orders WHERE po_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    $pr_id  = (int)post('pr_id');

    // ── cancel_pr ─────────────────────────────────────────────────────────────
    if ($action === 'cancel_pr' && $pr_id) {
        try {
            $db->beginTransaction();

            // Cancel the PR and its items
            $db->prepare("UPDATE purchase_requisitions SET status='cancelled', updated_by='admin' WHERE pr_id=?")
               ->execute([$pr_id]);
            $db->prepare("UPDATE pr_items SET status='cancelled' WHERE pr_id=?")
               ->execute([$pr_id]);

            // Reset any material requirements that were linked to this PR
            $db->prepare("
                UPDATE material_requirements
                SET purchase_qty=0, pr_id=NULL, status='pending', updated_by='admin'
                WHERE pr_id=?
            ")->execute([$pr_id]);

            $db->commit();
            set_message('success', 'Purchase Requisition cancelled and linked requirements reset to pending.');
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('purchase_requisitions.php');
    }

    // ── create_po ─────────────────────────────────────────────────────────────
    if ($action === 'create_po' && $pr_id) {
        $supplier_name          = trim(post('supplier_name'));
        $supplier_contact       = trim(post('supplier_contact'));
        $expected_delivery_date = post('expected_delivery_date') ?: null;

        if (!$supplier_name) {
            set_message('error', 'Supplier name is required.');
            redirect('purchase_requisitions.php');
        }

        try {
            $db->beginTransaction();

            // Fetch non-cancelled pr_items with warehouse fallback logic
            $items = $db->prepare("
                SELECT
                    pri.pr_item_id,
                    pri.material_id,
                    pri.approved_qty,
                    pri.estimated_unit_price,
                    COALESCE(
                        mr.warehouse_id,
                        m.default_warehouse_id,
                        (SELECT MIN(warehouse_id) FROM material_warehouses LIMIT 1)
                    ) AS warehouse_id
                FROM pr_items pri
                LEFT JOIN material_requirements mr ON mr.req_id = pri.material_req_id
                LEFT JOIN materials m ON m.material_id = pri.material_id
                WHERE pri.pr_id = ? AND pri.status != 'cancelled'
            ");
            $items->execute([$pr_id]);
            $pr_items = $items->fetchAll(PDO::FETCH_ASSOC);

            if (empty($pr_items)) {
                $db->rollBack();
                set_message('error', 'No active items found on this PR.');
                redirect('purchase_requisitions.php');
            }

            $po_number = gen_po_number($db);

            // Insert purchase_orders header
            $db->prepare("
                INSERT INTO purchase_orders
                    (po_number, po_date, pr_id, supplier_name, supplier_contact,
                     expected_delivery_date, status, created_by)
                VALUES (?, CURDATE(), ?, ?, ?, ?, 'draft', 'admin')
            ")->execute([
                $po_number,
                $pr_id,
                $supplier_name,
                $supplier_contact ?: null,
                $expected_delivery_date,
            ]);
            $po_id = (int)$db->lastInsertId();

            // Insert po_items
            $insItem = $db->prepare("
                INSERT INTO po_items
                    (po_id, pr_item_id, material_id, ordered_qty, unit_price, warehouse_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($pr_items as $pi) {
                $insItem->execute([
                    $po_id,
                    $pi['pr_item_id'],
                    $pi['material_id'],
                    $pi['approved_qty'],
                    $pi['estimated_unit_price'] ?? 0,
                    $pi['warehouse_id'],
                ]);
            }

            // Update PR and item statuses
            $db->prepare("UPDATE purchase_requisitions SET status='ordered', updated_by='admin' WHERE pr_id=?")
               ->execute([$pr_id]);
            $db->prepare("UPDATE pr_items SET status='ordered' WHERE pr_id=? AND status != 'cancelled'")
               ->execute([$pr_id]);

            $db->commit();
            set_message('success', "Purchase Order <b>$po_number</b> created successfully from this PR.");
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('purchase_requisitions.php');
    }
}

// ── edit_pr ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'edit_pr') {
    $pr_id       = (int)post('pr_id');
    $division_id = (int)post('division_id');
    $pr_date     = post('pr_date');
    $requested_by = trim(post('requested_by'));
    $notes       = trim(post('notes'));

    // items arrays
    $item_ids    = post('item_id')    ?: [];   // existing item ids ('' = new)
    $mat_ids     = post('mat_id')     ?: [];
    $req_qtys    = post('req_qty')    ?: [];
    $app_qtys    = post('app_qty')    ?: [];
    $units       = post('unit')       ?: [];
    $unit_prices = post('unit_price') ?: [];
    $del_flags   = post('del_item')   ?: [];   // checkboxes for deletion

    try {
        $db->beginTransaction();

        // Update header
        $db->prepare("
            UPDATE purchase_requisitions
            SET division_id=?, pr_date=?, requested_by=?, notes=?, updated_by='admin'
            WHERE pr_id=? AND status IN ('draft','submitted')
        ")->execute([$division_id, $pr_date, $requested_by ?: null, $notes ?: null, $pr_id]);

        // Process item rows
        // Detect whether estimated_total is a GENERATED column (local) or plain (cloud).
        // On generated columns, INSERT/UPDATE must NOT reference them; on plain columns they must be set manually.
        $etGenerated = false;
        foreach ($db->query("DESCRIBE pr_items")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if ($col['Field'] === 'estimated_total' && stripos($col['Extra'], 'GENERATED') !== false) {
                $etGenerated = true;
                break;
            }
        }

        if ($etGenerated) {
            // Local: generated column — don't touch it
            $insItem = $db->prepare("
                INSERT INTO pr_items (pr_id, material_id, required_qty, approved_qty, unit, estimated_unit_price, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $updItem = $db->prepare("
                UPDATE pr_items
                SET material_id=?, required_qty=?, approved_qty=?, unit=?, estimated_unit_price=?
                WHERE pr_item_id=? AND pr_id=?
            ");
        } else {
            // Cloud: plain column — compute and store manually
            $insItem = $db->prepare("
                INSERT INTO pr_items (pr_id, material_id, required_qty, approved_qty, unit, estimated_unit_price, estimated_total, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $updItem = $db->prepare("
                UPDATE pr_items
                SET material_id=?, required_qty=?, approved_qty=?, unit=?, estimated_unit_price=?, estimated_total=?
                WHERE pr_item_id=? AND pr_id=?
            ");
        }
        $delItem = $db->prepare("DELETE FROM pr_items WHERE pr_item_id=? AND pr_id=?");

        foreach ($mat_ids as $idx => $mat_id) {
            $mat_id    = (int)$mat_id;
            $item_id   = (int)($item_ids[$idx] ?? 0);
            $req_qty   = (float)($req_qtys[$idx] ?? 0);
            $app_qty   = strlen(trim($app_qtys[$idx] ?? '')) ? (float)$app_qtys[$idx] : null;
            $unit      = trim($units[$idx] ?? '');
            $u_price   = (float)($unit_prices[$idx] ?? 0);
            $to_delete = isset($del_flags[$idx]);
            $est_total = round(($app_qty ?? $req_qty) * $u_price, 2);

            if (!$mat_id || $req_qty <= 0) continue; // skip blank rows

            if ($to_delete && $item_id) {
                $delItem->execute([$item_id, $pr_id]);
            } elseif ($item_id) {
                $params = [$mat_id, $req_qty, $app_qty, $unit ?: null, $u_price];
                if (!$etGenerated) $params[] = $est_total;
                $params[] = $item_id;
                $params[] = $pr_id;
                $updItem->execute($params);
            } else {
                $params = [$pr_id, $mat_id, $req_qty, $app_qty, $unit ?: null, $u_price];
                if (!$etGenerated) $params[] = $est_total;
                $insItem->execute($params);
            }
        }

        $db->commit();
        set_message('success', 'Purchase Requisition updated successfully.');
    } catch (PDOException $e) {
        $db->rollBack();
        set_message('error', 'Error: ' . $e->getMessage());
    }
    redirect('purchase_requisitions.php?' . http_build_query(['year' => date('Y', strtotime($pr_date ?: 'now'))]));
}

// ── delete_pr ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete_pr') {
    $pr_id = (int)post('pr_id');
    try {
        $db->beginTransaction();
        // Reset any linked material requirements first
        $db->prepare("
            UPDATE material_requirements
            SET purchase_qty=0, pr_id=NULL, status='pending', updated_by='admin'
            WHERE pr_id=?
        ")->execute([$pr_id]);
        // pr_items cascade-deletes via FK
        $db->prepare("DELETE FROM purchase_requisitions WHERE pr_id=? AND status='draft'")
           ->execute([$pr_id]);
        $db->commit();
        set_message('success', 'Purchase Requisition deleted.');
    } catch (PDOException $e) {
        $db->rollBack();
        set_message('error', 'Error: ' . $e->getMessage());
    }
    redirect('purchase_requisitions.php');
}

// ─── Filters ──────────────────────────────────────────────────────────────────
// Default year: use the latest year that actually has PR data, fallback to current year
if (get('year') !== '') {
    $year = (int)get('year');
} else {
    try {
        $latestYear = (int)$db->query("SELECT COALESCE(MAX(YEAR(pr_date)), YEAR(CURDATE())) FROM purchase_requisitions")->fetchColumn();
    } catch (PDOException $e) { $latestYear = (int)date('Y'); }
    $year = $latestYear;
}
$month           = (int)(get('month')     ?: 0);
$division_filter = (int)(get('division_id') ?: 0);
$status_filter   = get('status');

// ─── Reference data ───────────────────────────────────────────────────────────
try {
    $divisions = $db->query("SELECT division_id, division_name FROM divisions WHERE status='active' ORDER BY division_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $divisions = []; }

try {
    $materials_list = $db->query("SELECT material_id, material_code, material_name, unit, unit_price FROM materials WHERE status='active' ORDER BY category, material_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $materials_list = []; }

// ─── Fetch PRs ────────────────────────────────────────────────────────────────
$pr_fetch_error = null;
try {
    $sql = "
        SELECT pr_id, pr_number, pr_date, division_id, division_name,
               requested_by, status, item_count, estimated_total, notes, created_at
        FROM vw_pr_summary
        WHERE YEAR(pr_date) = ?
    ";
    $params = [$year];

    if ($month > 0) {
        $sql .= " AND MONTH(pr_date) = ?";
        $params[] = $month;
    }
    if ($division_filter > 0) {
        $sql .= " AND division_id = ?";
        $params[] = $division_filter;
    }
    if ($status_filter !== '') {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }
    $sql .= " ORDER BY pr_date DESC, pr_id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $prs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $prs = [];
    $pr_fetch_error = $e->getMessage();
}

// ─── KPIs ─────────────────────────────────────────────────────────────────────
$total_prs       = count($prs);
$draft_cnt       = count(array_filter($prs, fn($r) => $r['status'] === 'draft'));
$submitted_cnt   = count(array_filter($prs, fn($r) => $r['status'] === 'submitted'));
$ordered_cnt     = count(array_filter($prs, fn($r) => $r['status'] === 'ordered'));
$total_est_value = array_sum(array_column($prs, 'estimated_total'));

$status_colours = [
    'draft'              => 'secondary',
    'submitted'          => 'primary',
    'ordered'            => 'success',
    'partially_received' => 'warning',
    'received'           => 'info',
    'cancelled'          => 'danger',
];

require_once 'includes/header.php';
?>

<style>
    .pr-blue  { color: #0d6efd !important; }
    .bg-pr    { background-color: #0d6efd !important; }
    .btn-pr   { background-color: #0d6efd; color:#fff; border:none; }
    .btn-pr:hover { background-color: #0b5ed7; color:#fff; }
    .detail-row td { background: #f8f9fa; }
    .detail-row .detail-inner { padding: .5rem 1rem; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="pr-blue"><i class="bi bi-file-earmark-check"></i> Purchase Requisitions</h1>
            <p class="text-muted mb-0">
                <a href="material_requirements.php" class="text-decoration-none">Material Requirements</a>
                &rsaquo; <span class="text-body">Purchase Requisitions</span>
                &rsaquo; <a href="purchase_orders.php" class="text-decoration-none">Purchase Orders</a>
            </p>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- ── Filters ─────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-pr text-white py-2"><i class="bi bi-funnel"></i> Filter</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = min((int)date('Y')-2, $year-1); $y <= max((int)date('Y')+1, $year+1); $y++): ?>
                        <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <option value="">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-1">Division</label>
                <select name="division_id" class="form-select form-select-sm">
                    <option value="">All Divisions</option>
                    <?php foreach ($divisions as $d): ?>
                        <option value="<?= $d['division_id'] ?>" <?= $d['division_id']==$division_filter?'selected':'' ?>>
                            <?= htmlspecialchars($d['division_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <?php foreach (array_keys($status_colours) as $s): ?>
                        <option value="<?= $s ?>" <?= $s===$status_filter?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-pr btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="purchase_requisitions.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── KPI cards ───────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Total PRs</div>
                <div class="fw-bold fs-4"><?= $total_prs ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Draft</div>
                <div class="fw-bold fs-4 text-secondary"><?= $draft_cnt ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Submitted</div>
                <div class="fw-bold fs-4 text-primary"><?= $submitted_cnt ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Ordered</div>
                <div class="fw-bold fs-4 text-success"><?= $ordered_cnt ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Total Estimated Value</div>
                <div class="fw-bold fs-5">Rp <?= number_format($total_est_value, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ── PR table ────────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header bg-pr text-white py-2">
        <i class="bi bi-table"></i> <?= $total_prs ?> Requisition(s) — <?= $year ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:32px;"></th>
                        <th>PR Number</th>
                        <th>Date</th>
                        <th>Division</th>
                        <th>Requested By</th>
                        <th class="text-center">Items</th>
                        <th class="text-end">Est. Total (Rp)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($pr_fetch_error): ?>
                    <tr><td colspan="9" class="text-center text-danger py-4">
                        <i class="bi bi-exclamation-triangle"></i>
                        Database error: <?= htmlspecialchars($pr_fetch_error) ?>
                    </td></tr>
                <?php elseif (empty($prs)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No purchase requisitions found.</td></tr>
                <?php else: ?>
                    <?php foreach ($prs as $r): ?>
                    <?php $can_act = in_array($r['status'], ['draft', 'submitted']); ?>
                    <tr>
                        <td>
                            <button class="btn btn-link btn-sm p-0 text-muted toggle-detail"
                                    data-pr="<?= $r['pr_id'] ?>" title="Show items">
                                <i class="bi bi-chevron-right" id="icon-<?= $r['pr_id'] ?>"></i>
                            </button>
                        </td>
                        <td class="fw-bold"><?= htmlspecialchars($r['pr_number']) ?></td>
                        <td><?= date('d/m/Y', strtotime($r['pr_date'])) ?></td>
                        <td><?= htmlspecialchars($r['division_name']) ?></td>
                        <td><?= htmlspecialchars($r['requested_by'] ?? '—') ?></td>
                        <td class="text-center"><?= (int)$r['item_count'] ?></td>
                        <td class="text-end"><?= number_format((float)$r['estimated_total'], 0, ',', '.') ?></td>
                        <td>
                            <span class="badge bg-<?= $status_colours[$r['status']] ?? 'secondary' ?>">
                                <?= ucfirst(str_replace('_', ' ', $r['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($can_act): ?>
                            <!-- Edit button (draft & submitted) -->
                            <button class="btn btn-warning btn-sm"
                                    onclick="openEditPR(<?= $r['pr_id'] ?>)"
                                    title="Edit PR">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <!-- Create PO button -->
                            <button class="btn btn-success btn-sm"
                                    onclick="openCreatePO(<?= $r['pr_id'] ?>, '<?= htmlspecialchars($r['pr_number'], ENT_QUOTES) ?>')"
                                    title="Create Purchase Order">
                                <i class="bi bi-cart-plus"></i> Create PO
                            </button>
                            <?php if ($r['status'] === 'draft'): ?>
                            <!-- Delete button (draft only) -->
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Permanently delete PR <?= htmlspecialchars($r['pr_number'], ENT_QUOTES) ?>? This cannot be undone.')">
                                <input type="hidden" name="action" value="delete_pr">
                                <input type="hidden" name="pr_id"  value="<?= $r['pr_id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Delete PR">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <!-- Cancel button (submitted only) -->
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Cancel PR <?= htmlspecialchars($r['pr_number'], ENT_QUOTES) ?>? This will also reset any linked material requirements.')">
                                <input type="hidden" name="action" value="cancel_pr">
                                <input type="hidden" name="pr_id"  value="<?= $r['pr_id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Cancel PR">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Expandable detail row (hidden by default) -->
                    <tr class="detail-row d-none" id="detail-<?= $r['pr_id'] ?>">
                        <td colspan="9" class="p-0">
                            <div class="detail-inner" id="detail-content-<?= $r['pr_id'] ?>">
                                <span class="text-muted small"><i class="bi bi-hourglass-split"></i> Loading…</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Create PO Modal ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="createPOModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <input type="hidden" name="action" value="create_po">
            <input type="hidden" name="pr_id"  id="po_pr_id">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-cart-plus"></i> Create Purchase Order from <span id="po_pr_number"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                        <input type="text" name="supplier_name" class="form-control form-control-sm"
                               placeholder="e.g. PT Agri Supplier Nusantara" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Supplier Contact <span class="text-muted small">(optional)</span></label>
                        <input type="text" name="supplier_contact" class="form-control form-control-sm"
                               placeholder="Phone / email">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expected Delivery Date</label>
                        <input type="date" name="expected_delivery_date" class="form-control form-control-sm"
                               value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-circle"></i> Create PO</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit PR Modal ────────────────────────────────────────────────────────── -->
<div class="modal fade" id="editPRModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form method="POST" id="editPRForm">
            <input type="hidden" name="action" value="edit_pr">
            <input type="hidden" name="pr_id"  id="edit_pr_id">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Edit PR — <span id="edit_pr_number"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <!-- Header fields -->
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-1">PR Date <span class="text-danger">*</span></label>
                            <input type="date" name="pr_date" id="edit_pr_date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-1">Division <span class="text-danger">*</span></label>
                            <select name="division_id" id="edit_division_id" class="form-select form-select-sm" required>
                                <?php foreach ($divisions as $d): ?>
                                <option value="<?= $d['division_id'] ?>"><?= htmlspecialchars($d['division_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label form-label-sm mb-1">Requested By</label>
                            <input type="text" name="requested_by" id="edit_requested_by" class="form-control form-control-sm"
                                   placeholder="Name of requester">
                        </div>
                        <div class="col-12">
                            <label class="form-label form-label-sm mb-1">Notes</label>
                            <textarea name="notes" id="edit_notes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>

                    <!-- Items table -->
                    <div class="table-responsive" style="overflow:visible;">
                        <table class="table table-sm table-bordered mb-1" id="edit_items_table" style="font-size:.85rem;">
                            <thead class="table-secondary">
                                <tr>
                                    <th style="width:32%">Material</th>
                                    <th style="width:11%" class="text-end">Required Qty</th>
                                    <th style="width:11%" class="text-end">Approved Qty</th>
                                    <th style="width:9%">Unit</th>
                                    <th style="width:14%" class="text-end">Unit Price (Rp)</th>
                                    <th style="width:14%" class="text-end">Est. Total</th>
                                    <th style="width:9%" class="text-center">Remove</th>
                                </tr>
                            </thead>
                            <tbody id="edit_items_tbody">
                                <!-- rows injected by JS -->
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addItemRow()">
                        <i class="bi bi-plus-circle"></i> Add Item
                    </button>

                </div><!-- /.modal-body -->
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-warning btn-sm text-dark">
                        <i class="bi bi-check-circle"></i> Save Changes
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- PHP-side material list encoded for JS -->
<script>
const MATERIALS = <?= json_encode(array_values($materials_list), JSON_UNESCAPED_UNICODE) ?>;

// Build a quick map for defaults — use String keys so sel.value (always a string) matches
const MAT_MAP = {};
MATERIALS.forEach(m => { MAT_MAP[String(m.material_id)] = m; });

// Keep a single Bootstrap Modal instance to avoid re-creation bugs
let _editPRModal = null;

// ── Edit PR modal helper ──────────────────────────────────────────────────────
function openEditPR(prId) {
    fetch('ajax/pr_detail.php?pr_id=' + prId)
        .then(r => r.json())
        .then(data => {
            if (data.error) { alert('Error: ' + data.error); return; }

            document.getElementById('edit_pr_id').value           = data.pr_id;
            document.getElementById('edit_pr_number').textContent = data.pr_number;
            document.getElementById('edit_division_id').value     = data.division_id;
            document.getElementById('edit_requested_by').value    = data.requested_by || '';
            document.getElementById('edit_notes').value           = data.notes || '';

            // Set date — footer.php initialises modal date inputs with static:true flatpickr
            const dateEl = document.getElementById('edit_pr_date');
            if (dateEl._flatpickr) {
                dateEl._flatpickr.setDate(data.pr_date, false);
            } else {
                dateEl.value = data.pr_date || '';
            }

            const tbody = document.getElementById('edit_items_tbody');
            tbody.innerHTML = '';
            (data.items || []).forEach(item => buildItemRow(tbody, item));

            if (!_editPRModal) {
                _editPRModal = new bootstrap.Modal(document.getElementById('editPRModal'));
            }
            _editPRModal.show();
        })
        .catch(err => alert('Could not load PR data: ' + err));
}

// Build one item row (existing item when item.pr_item_id set, new row when null)
function buildItemRow(tbody, item) {
    const idx    = tbody.rows.length;
    const tr     = document.createElement('tr');

    const reqQty  = item.required_qty         ?? '';
    const appQty  = item.approved_qty         ?? '';
    const unit    = item.unit                 ?? '';
    const price   = item.estimated_unit_price ?? 0;
    const itemId  = item.pr_item_id           ?? '';

    // Hidden item id
    const hiddenInput = document.createElement('input');
    hiddenInput.type  = 'hidden';
    hiddenInput.name  = 'item_id[]';
    hiddenInput.value = itemId;

    // Material select — built via DOM so the selected state is set programmatically
    const matSelect = document.createElement('select');
    matSelect.name      = 'mat_id[]';
    matSelect.className = 'form-select form-select-sm mat-select';
    MATERIALS.forEach(m => {
        const opt      = document.createElement('option');
        opt.value      = m.material_id;
        opt.textContent = m.material_name + ' (' + m.material_code + ')';
        if (String(m.material_id) === String(item.material_id)) opt.selected = true;
        matSelect.appendChild(opt);
    });
    matSelect.addEventListener('change', function() { applyMatDefaults(this); });

    const tdMat = document.createElement('td');
    tdMat.appendChild(hiddenInput);
    tdMat.appendChild(matSelect);

    // req_qty
    const inpReq = document.createElement('input');
    inpReq.type      = 'number'; inpReq.name = 'req_qty[]';
    inpReq.className = 'form-control form-control-sm text-end req-qty';
    inpReq.min = '0.01'; inpReq.step = '0.01'; inpReq.required = true;
    inpReq.value = reqQty;
    inpReq.addEventListener('change', function() { calcRow(this); });
    const tdReq = document.createElement('td'); tdReq.appendChild(inpReq);

    // app_qty
    const inpApp = document.createElement('input');
    inpApp.type      = 'number'; inpApp.name = 'app_qty[]';
    inpApp.className = 'form-control form-control-sm text-end';
    inpApp.min = '0.01'; inpApp.step = '0.01'; inpApp.placeholder = '—';
    inpApp.value = appQty;
    const tdApp = document.createElement('td'); tdApp.appendChild(inpApp);

    // unit
    const inpUnit = document.createElement('input');
    inpUnit.type      = 'text'; inpUnit.name = 'unit[]';
    inpUnit.className = 'form-control form-control-sm unit-field';
    inpUnit.placeholder = 'kg'; inpUnit.value = unit;
    const tdUnit = document.createElement('td'); tdUnit.appendChild(inpUnit);

    // unit_price
    const inpPrice = document.createElement('input');
    inpPrice.type      = 'number'; inpPrice.name = 'unit_price[]';
    inpPrice.className = 'form-control form-control-sm text-end unit-price';
    inpPrice.min = '0'; inpPrice.step = '1';
    inpPrice.value = price;
    inpPrice.addEventListener('change', function() { calcRow(this); });
    const tdPrice = document.createElement('td'); tdPrice.appendChild(inpPrice);

    // row total
    const tdTotal = document.createElement('td');
    tdTotal.className = 'text-end fw-semibold row-total';
    tdTotal.textContent = 'Rp ' + fmtNum(parseFloat(appQty || reqQty || 0) * parseFloat(price || 0));

    // delete checkbox
    const chk = document.createElement('input');
    chk.type = 'checkbox'; chk.name = 'del_item[' + idx + ']';
    chk.className = 'form-check-input del-check'; chk.title = 'Mark for removal';
    const tdDel = document.createElement('td'); tdDel.className = 'text-center'; tdDel.appendChild(chk);

    tr.appendChild(tdMat);
    tr.appendChild(tdReq);
    tr.appendChild(tdApp);
    tr.appendChild(tdUnit);
    tr.appendChild(tdPrice);
    tr.appendChild(tdTotal);
    tr.appendChild(tdDel);
    tbody.appendChild(tr);
}

// Add a blank row
function addItemRow() {
    const tbody = document.getElementById('edit_items_tbody');
    const first = MATERIALS[0] || {};
    buildItemRow(tbody, {
        material_id: first.material_id || '',
        required_qty: '', approved_qty: '',
        unit: first.unit || '',
        estimated_unit_price: first.unit_price || 0,
        pr_item_id: ''
    });
}

// Fill unit & price from material defaults when material changes
function applyMatDefaults(sel) {
    const row = sel.closest('tr');
    const mat = MAT_MAP[String(sel.value)];
    if (!mat) return;
    row.querySelector('.unit-field').value = mat.unit;
    row.querySelector('.unit-price').value = mat.unit_price;
    calcRow(sel);
}

// Recalculate row total
function calcRow(el) {
    const row   = el.closest('tr');
    const qty   = parseFloat(row.querySelector('.req-qty').value || 0);
    const price = parseFloat(row.querySelector('.unit-price').value || 0);
    row.querySelector('.row-total').textContent = 'Rp ' + fmtNum(qty * price);
}

function fmtNum(n) {
    return Math.round(n).toLocaleString('id-ID');
}

// ── Create PO modal helper ────────────────────────────────────────────────────
function openCreatePO(prId, prNumber) {
    document.getElementById('po_pr_id').value     = prId;
    document.getElementById('po_pr_number').textContent = prNumber;
    new bootstrap.Modal(document.getElementById('createPOModal')).show();
}

// ── Expandable detail rows ────────────────────────────────────────────────────
const loadedDetails = {};

document.querySelectorAll('.toggle-detail').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const prId    = this.dataset.pr;
        const row     = document.getElementById('detail-' + prId);
        const icon    = document.getElementById('icon-' + prId);
        const content = document.getElementById('detail-content-' + prId);

        if (!row.classList.contains('d-none')) {
            row.classList.add('d-none');
            icon.className = 'bi bi-chevron-right';
            return;
        }

        row.classList.remove('d-none');
        icon.className = 'bi bi-chevron-down';

        if (loadedDetails[prId]) return; // already loaded

        fetch('ajax/pr_items.php?pr_id=' + prId)
            .then(r => r.text())
            .then(html => {
                content.innerHTML = html;
                loadedDetails[prId] = true;
            })
            .catch(() => {
                content.innerHTML = '<span class="text-danger small">Failed to load items.</span>';
            });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
