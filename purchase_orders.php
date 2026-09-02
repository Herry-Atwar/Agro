<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db         = getDB();
$page_title = __('pt_purchase_orders');

// ─── Helper: GRN number generator ─────────────────────────────────────────────
function gen_grn_number(PDO $db): string {
    $ym     = date('Ym');
    $prefix = 'GRN-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(grn_number,'-',-1) AS UNSIGNED)),0)+1
        FROM grn_headers WHERE grn_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── Helper: Resolve GL account id by code ────────────────────────────────────
function gl_id(PDO $db, string $code): int {
    static $cache = [];
    if (!isset($cache[$code])) {
        $cache[$code] = (int)$db->query(
            "SELECT id FROM general_ledger_accounts WHERE account_code='$code' LIMIT 1"
        )->fetchColumn();
    }
    return $cache[$code];
}

// ─── Helper: Map material category → Inventory GL account code ───────────────
function inventory_gl(string $category): string {
    return match($category) {
        'fertilizer'  => '1311',
        'pesticide'   => '1312',
        'herbicide'   => '1313',
        'equipment'   => '1314',
        'fuel'        => '1315',
        'spare_parts' => '1316',
        default       => '1319',
    };
}

// ─── Helper: Create GRN journal entry (Dr Inventory / Cr AP) ─────────────────
function create_grn_journal(PDO $db, string $grn_number, string $grn_date,
                             string $po_number, array $line_items,
                             ?int $division_id): int
{
    // Reference: JE-GRN-YYYYMM-NNNN
    $ym     = date('Ym', strtotime($grn_date));
    $prefix = 'JE-GRN-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reference_number,'-',-1) AS UNSIGNED)),0)+1
        FROM journal_entries WHERE reference_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    $ref = $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);

    $total = array_sum(array_column($line_items, 'amount'));
    $ap_id = gl_id($db, '2111');   // AP - Material Suppliers

    // Insert header (auto-posted, matching payment_receives.php pattern)
    $db->prepare("
        INSERT INTO journal_entries
            (entry_date, entry_type, reference_number, description,
             division_id, total_debit, total_credit,
             currency_code, status, posted_date, posted_by, created_by)
        VALUES (?, 'material_purchase', ?, ?, ?, ?, ?, 'IDR', 'posted', NOW(), 1, 'admin')
    ")->execute([
        $grn_date, $ref,
        'GRN: ' . $grn_number . ' / PO: ' . $po_number,
        $division_id,
        $total, $total,
    ]);
    $je_id = (int)$db->lastInsertId();

    // Lines: Dr Inventory per category, then one Cr AP line
    $line_num = 1;
    foreach ($line_items as $li) {
        $inv_id = gl_id($db, inventory_gl($li['category']));
        $db->prepare("
            INSERT INTO journal_entry_lines
                (journal_entry_id, line_number, gl_account_id,
                 debit_amount, credit_amount, cost_category, description,
                 division_id, quantity, unit, unit_cost, reference_info)
            VALUES (?,?,?, ?,0, 'material',?,  ?,?,?,?,?)
        ")->execute([
            $je_id, $line_num, $inv_id,
            $li['amount'],
            'Receive ' . $li['material_name'] . ' — ' . $grn_number,
            $division_id,
            $li['qty'], $li['unit'], $li['unit_price'],
            $po_number,
        ]);
        $line_num++;
    }

    // Cr Accounts Payable (one summary line)
    $db->prepare("
        INSERT INTO journal_entry_lines
            (journal_entry_id, line_number, gl_account_id,
             debit_amount, credit_amount, description, reference_info)
        VALUES (?,?,?, 0,?,?,?)
    ")->execute([
        $je_id, $line_num, $ap_id,
        $total,
        'AP — ' . $po_number . ' / ' . $grn_number,
        $po_number,
    ]);

    return $je_id;
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    $po_id  = (int)post('po_id');

    // ── receive_items ─────────────────────────────────────────────────────────
    if ($action === 'receive_items' && $po_id) {
        $po_item_ids   = $_POST['po_item_id']    ?? [];
        $received_qtys = $_POST['received_qty']  ?? [];
        $supplier_do   = trim(post('supplier_do_no') ?: '');
        $received_by   = trim(post('received_by')    ?: '');
        $grn_notes     = trim(post('grn_notes')      ?: '');

        try {
            $db->beginTransaction();

            // Fetch PO header
            $po_row = $db->prepare("
                SELECT po.po_number, po.pr_id, pr.division_id
                FROM purchase_orders po
                JOIN purchase_requisitions pr ON pr.pr_id = po.pr_id
                WHERE po.po_id = ?
            ");
            $po_row->execute([$po_id]);
            $po = $po_row->fetch(PDO::FETCH_ASSOC);
            if (!$po) throw new Exception('Purchase Order not found.');

            $po_number   = $po['po_number'];
            $pr_id       = (int)$po['pr_id'];
            $division_id = $po['division_id'] ? (int)$po['division_id'] : null;
            $today       = date('Y-m-d');

            // ── Generate GRN number ────────────────────────────────────────────
            $grn_number = gen_grn_number($db);

            // Insert GRN header (journal_entry_id filled after JE creation)
            $db->prepare("
                INSERT INTO grn_headers
                    (grn_number, grn_date, po_id, received_by, supplier_do_no, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 'admin')
            ")->execute([
                $grn_number, $today, $po_id,
                $received_by ?: null,
                $supplier_do ?: null,
                $grn_notes   ?: null,
            ]);
            $grn_id = (int)$db->lastInsertId();

            $items_received = 0;
            $budget_updates = 0;
            $journal_lines  = [];   // collect for JE

            foreach ($po_item_ids as $idx => $po_item_id) {
                $po_item_id   = (int)$po_item_id;
                $received_qty = (float)($received_qtys[$idx] ?? 0);
                if ($po_item_id <= 0 || $received_qty <= 0) continue;

                // Fetch po_item details + material category
                $item_row = $db->prepare("
                    SELECT poi.material_id, poi.warehouse_id, poi.unit_price,
                           poi.ordered_qty, poi.received_qty AS already_received,
                           poi.pr_item_id,
                           m.material_name, m.unit, m.category
                    FROM po_items poi
                    JOIN materials m ON m.material_id = poi.material_id
                    WHERE poi.po_item_id = ? AND poi.po_id = ?
                ");
                $item_row->execute([$po_item_id, $po_id]);
                $item = $item_row->fetch(PDO::FETCH_ASSOC);
                if (!$item) continue;

                // ── Over-receipt validation ────────────────────────────────────
                $max_receivable = (float)$item['ordered_qty'] - (float)$item['already_received'];
                if ($received_qty > $max_receivable + 0.001) {
                    throw new Exception(
                        "Over-receipt on {$item['material_name']}: " .
                        "ordered {$item['ordered_qty']}, " .
                        "already received {$item['already_received']}, " .
                        "you entered $received_qty."
                    );
                }

                // a. Update po_item — link to GRN
                $db->prepare("
                    UPDATE po_items
                    SET received_qty  = ?,
                        received_date = ?,
                        grn_id        = ?,
                        status        = 'received'
                    WHERE po_item_id = ?
                ")->execute([$received_qty, $today, $grn_id, $po_item_id]);

                // b. Stock IN transaction
                $db->prepare("
                    INSERT INTO material_transactions
                        (transaction_date, transaction_type, warehouse_id, material_id,
                         quantity, unit_price, reference_no, remarks, created_by)
                    VALUES (?, 'in', ?, ?, ?, ?, ?, ?, 'admin')
                ")->execute([
                    $today,
                    (int)$item['warehouse_id'],
                    (int)$item['material_id'],
                    $received_qty,
                    (float)$item['unit_price'],
                    $grn_number,
                    'GRN: ' . $grn_number . ' / PO: ' . $po_number,
                ]);

                // Collect for journal entry
                $journal_lines[] = [
                    'category'      => $item['category'],
                    'material_name' => $item['material_name'],
                    'qty'           => $received_qty,
                    'unit'          => $item['unit'],
                    'unit_price'    => (float)$item['unit_price'],
                    'amount'        => round($received_qty * (float)$item['unit_price'], 2),
                ];

                $items_received++;

                // c. Budget path trace — silently skip if any link is broken
                try {
                    $pr_item_id = (int)$item['pr_item_id'];
                    $r1 = $db->prepare("SELECT material_req_id FROM pr_items WHERE pr_item_id = ?");
                    $r1->execute([$pr_item_id]);
                    $material_req_id = (int)$r1->fetchColumn();
                    if (!$material_req_id) throw new Exception('no mr');

                    $r2 = $db->prepare("SELECT plan_item_id FROM material_requirements WHERE req_id = ?");
                    $r2->execute([$material_req_id]);
                    $plan_item_id = (int)$r2->fetchColumn();
                    if (!$plan_item_id) throw new Exception('no pi');

                    $r3 = $db->prepare("SELECT budget_month_id FROM daily_activity_plan_items WHERE item_id = ?");
                    $r3->execute([$plan_item_id]);
                    $budget_month_id = (int)$r3->fetchColumn();
                    if (!$budget_month_id) throw new Exception('no bm');

                    $db->prepare("
                        UPDATE activity_budget_monthly
                        SET actual_cost = actual_cost + ?
                        WHERE monthly_id = ?
                    ")->execute([$received_qty * (float)$item['unit_price'], $budget_month_id]);

                    $budget_updates++;
                } catch (Exception $e) { /* Budget link broken — skip silently */ }
            }

            if ($items_received === 0) {
                throw new Exception('No valid quantities entered. Please enter at least one received qty > 0.');
            }

            // ── d. Create journal entry (Dr Inventory / Cr AP) ────────────────
            $je_id = null;
            try {
                $je_id = create_grn_journal(
                    $db, $grn_number, $today, $po_number, $journal_lines, $division_id
                );
                // Back-fill journal_entry_id on GRN header
                $db->prepare("UPDATE grn_headers SET journal_entry_id=? WHERE grn_id=?")
                   ->execute([$je_id, $grn_id]);
            } catch (Exception $e) {
                // JE failed (missing GL accounts) — GRN still valid, just no JE
                // We don't throw — stock and GRN are more important
            }

            // ── e. Auto-update PO and PR status ───────────────────────────────
            $status_row = $db->prepare("
                SELECT COUNT(*) AS total,
                       SUM(status = 'received')  AS done,
                       SUM(status = 'cancelled') AS cancelled
                FROM po_items WHERE po_id = ?
            ");
            $status_row->execute([$po_id]);
            $sc = $status_row->fetch(PDO::FETCH_ASSOC);

            $active_total  = (int)$sc['total'] - (int)$sc['cancelled'];
            $done_count    = (int)$sc['done'];
            $new_po_status = ($active_total > 0 && $done_count >= $active_total) ? 'received' : 'partially_received';
            $new_pr_status = $new_po_status;

            $db->prepare("UPDATE purchase_orders SET status=?, updated_by='admin' WHERE po_id=?")
               ->execute([$new_po_status, $po_id]);
            $db->prepare("UPDATE purchase_requisitions SET status=?, updated_by='admin' WHERE pr_id=?")
               ->execute([$new_pr_status, $pr_id]);

            $db->commit();

            $je_msg     = $je_id ? " Journal entry <b>auto-posted</b> (Dr Inventory / Cr AP)." : '';
            $budget_msg = $budget_updates > 0 ? " Budget actuals updated for <b>$budget_updates</b> item(s)." : '';
            set_message('success',
                "GRN <b>$grn_number</b> created. Received <b>$items_received</b> item(s). Stock updated.$je_msg$budget_msg"
            );
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('purchase_orders.php');
    }

    // ── cancel_po ─────────────────────────────────────────────────────────────
    if ($action === 'cancel_po' && $po_id) {
        try {
            $db->beginTransaction();

            // Get pr_id before cancelling
            $r = $db->prepare("SELECT pr_id FROM purchase_orders WHERE po_id=?");
            $r->execute([$po_id]);
            $pr_id_cancel = (int)$r->fetchColumn();

            $db->prepare("UPDATE purchase_orders SET status='cancelled', updated_by='admin' WHERE po_id=?")
               ->execute([$po_id]);
            $db->prepare("UPDATE po_items SET status='cancelled' WHERE po_id=?")
               ->execute([$po_id]);

            // Revert PR to submitted (so it can be re-ordered)
            if ($pr_id_cancel) {
                $db->prepare("UPDATE purchase_requisitions SET status='submitted', updated_by='admin' WHERE pr_id=?")
                   ->execute([$pr_id_cancel]);
            }

            $db->commit();
            set_message('success', 'Purchase Order cancelled. PR reverted to <b>Submitted</b>.');
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('purchase_orders.php');
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$year          = get('year') ?: '';   // '' = all years (default)
$month         = (int)(get('month')  ?: 0);
$status_filter = get('status') ?: '';

// ─── Fetch POs ────────────────────────────────────────────────────────────────
try {
    $sql = "
        SELECT po_id, po_number, po_date, pr_id, pr_number, division_name,
               supplier_name, supplier_contact, expected_delivery_date,
               status, item_count, ordered_total, received_total,
               notes, created_at
        FROM vw_po_summary
        WHERE 1=1
    ";
    $params = [];

    if ($year !== '') {
        $sql .= " AND YEAR(po_date) = ?";
        $params[] = (int)$year;
    }
    if ($month > 0) {
        $sql .= " AND MONTH(po_date) = ?";
        $params[] = $month;
    }
    if ($status_filter !== '') {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }
    $sql .= " ORDER BY po_date DESC, po_id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $pos = []; }

// ─── KPIs ─────────────────────────────────────────────────────────────────────
$total_pos        = count($pos);
$pending_cnt      = count(array_filter($pos, fn($r) => in_array($r['status'], ['draft','sent'])));
$partial_cnt      = count(array_filter($pos, fn($r) => $r['status'] === 'partially_received'));
$received_cnt     = count(array_filter($pos, fn($r) => $r['status'] === 'received'));
$total_po_value   = array_sum(array_column($pos, 'ordered_total'));

$status_colours = [
    'draft'              => 'secondary',
    'sent'               => 'primary',
    'partially_received' => 'warning',
    'received'           => 'success',
    'cancelled'          => 'danger',
];

require_once 'includes/header.php';
?>

<style>
    .po-green  { color: #198754 !important; }
    .bg-po     { background-color: #198754 !important; }
    .btn-po    { background-color: #198754; color:#fff; border:none; }
    .btn-po:hover { background-color: #157347; color:#fff; }
    .detail-row td { background: #f8f9fa; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="po-green"><i class="bi bi-cart-check"></i> Purchase Orders</h1>
            <p class="text-muted mb-0">
                <a href="material_requirements.php" class="text-decoration-none">Material Requirements</a>
                &rsaquo; <a href="purchase_requisitions.php" class="text-decoration-none">Purchase Requisitions</a>
                &rsaquo; <span class="text-body">Purchase Orders</span>
            </p>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- ── Filters ─────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-po text-white py-2"><i class="bi bi-funnel"></i> Filter</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <option value="" <?= $year===''?'selected':'' ?>>All Years</option>
                    <?php for ($y = date('Y')-2; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= (string)$y===$year?'selected':'' ?>><?= $y ?></option>
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
                <label class="form-label form-label-sm mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <?php foreach (array_keys($status_colours) as $s): ?>
                        <option value="<?= $s ?>" <?= $s===$status_filter?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-po btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="purchase_orders.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── KPI cards ───────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Total POs</div>
                <div class="fw-bold fs-4"><?= $total_pos ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Pending</div>
                <div class="fw-bold fs-4 text-secondary"><?= $pending_cnt ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Partially Received</div>
                <div class="fw-bold fs-4 text-warning"><?= $partial_cnt ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Received</div>
                <div class="fw-bold fs-4 text-success"><?= $received_cnt ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Total PO Value</div>
                <div class="fw-bold fs-5">Rp <?= number_format($total_po_value, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ── PO table ─────────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header bg-po text-white py-2">
        <i class="bi bi-table"></i> <?= $total_pos ?> Purchase Order(s)<?= $year !== '' ? ' — ' . $year : '' ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:32px;"></th>
                        <th>PO Number</th>
                        <th>PO Date</th>
                        <th>Supplier</th>
                        <th>Expected Delivery</th>
                        <th class="text-center">Items</th>
                        <th class="text-end">Ordered (Rp)</th>
                        <th class="text-end">Received (Rp)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($pos)): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">
                            No purchase orders found for the selected filters.
                        </td>
                    </tr>
                <?php else: ?>
                <?php foreach ($pos as $po): ?>
                    <?php
                    $status      = strtolower(trim((string)$po['status']));
                    $can_receive = in_array($status, ['draft','sent','partially_received']);
                    $can_cancel  = in_array($status, ['draft','sent']);
                    ?>
                    <tr class="po-header-row" style="cursor:pointer;"
                        onclick="togglePoDetail(<?= $po['po_id'] ?>)">
                        <td class="text-center">
                            <i class="bi bi-chevron-right toggle-icon-<?= $po['po_id'] ?>" style="font-size:.7rem;"></i>
                        </td>
                        <td class="fw-semibold"><?= htmlspecialchars($po['po_number']) ?></td>
                        <td><?= htmlspecialchars($po['po_date']) ?></td>
                        <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                        <td><?= $po['expected_delivery_date'] ? htmlspecialchars($po['expected_delivery_date']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-center"><?= (int)$po['item_count'] ?></td>
                        <td class="text-end"><?= number_format((float)$po['ordered_total'], 0, ',', '.') ?></td>
                        <td class="text-end"><?= number_format((float)$po['received_total'], 0, ',', '.') ?></td>
                        <td>
                            <span class="badge bg-<?= $status_colours[$status] ?? 'secondary' ?>">
                                <?= ucfirst(str_replace('_',' ', $status)) ?>
                            </span>
                        </td>
                        <td onclick="event.stopPropagation();">
                            <?php if ($can_receive): ?>
                                <button class="btn btn-po btn-sm py-0 px-2"
                                    onclick="openReceiveModal(<?= $po['po_id'] ?>, '<?= htmlspecialchars($po['po_number'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-box-arrow-in-down"></i> Receive
                                </button>
                            <?php endif; ?>
                            <a href="grn.php?po_id=<?= $po['po_id'] ?>" class="btn btn-outline-secondary btn-sm py-0 px-2" title="View GRNs for this PO">
                                <i class="bi bi-box-seam"></i>
                            </a>
                            <?php if ($can_cancel): ?>
                                <form method="POST" class="d-inline"
                                    onsubmit="return confirm('Cancel PO <?= htmlspecialchars($po['po_number'], ENT_QUOTES) ?>? This will revert the PR to Submitted.');">
                                    <input type="hidden" name="action" value="cancel_po">
                                    <input type="hidden" name="po_id"  value="<?= $po['po_id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2">
                                        <i class="bi bi-x-circle"></i> Cancel
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Expandable detail row -->
                    <tr id="detail-row-<?= $po['po_id'] ?>" class="detail-row" style="display:none;">
                        <td colspan="10" class="p-0">
                            <div class="detail-inner px-4 py-2"
                                id="detail-content-<?= $po['po_id'] ?>"
                                data-loaded="0">
                                <div class="text-muted small py-2">
                                    <i class="bi bi-arrow-clockwise"></i> Loading items…
                                </div>
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

<!-- ── Receive Items Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="receiveModal" tabindex="-1" aria-labelledby="receiveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-po text-white">
                <h5 class="modal-title" id="receiveModalLabel">
                    <i class="bi bi-box-arrow-in-down"></i> Receive Goods
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="receiveForm">
                <input type="hidden" name="action" value="receive_items">
                <input type="hidden" name="po_id"  id="receive_po_id" value="">
                <div class="modal-body p-0">
                    <!-- GRN meta fields -->
                    <div class="px-3 pt-3 pb-2 border-bottom bg-light row g-2">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-1">Received By</label>
                            <input type="text" name="received_by" class="form-control form-control-sm"
                                   placeholder="Name of receiver">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-1">Supplier DO No. <span class="text-muted small">(optional)</span></label>
                            <input type="text" name="supplier_do_no" class="form-control form-control-sm"
                                   placeholder="Supplier delivery order no.">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-1">Notes <span class="text-muted small">(optional)</span></label>
                            <input type="text" name="grn_notes" class="form-control form-control-sm"
                                   placeholder="Condition, remarks…">
                        </div>
                    </div>
                    <div id="receive-modal-content" class="p-3">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-arrow-clockwise"></i> Loading…
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-po">
                        <i class="bi bi-check-circle"></i> Confirm Receipt &amp; Create GRN
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
// ── Expandable PO detail rows ──────────────────────────────────────────────
function togglePoDetail(poId) {
    const row     = document.getElementById('detail-row-' + poId);
    const content = document.getElementById('detail-content-' + poId);
    const icon    = document.querySelector('.toggle-icon-' + poId);

    if (!row) return;

    const isHidden = (row.style.display === 'none' || row.style.display === '');
    row.style.display = isHidden ? 'table-row' : 'none';

    if (icon) {
        icon.classList.toggle('bi-chevron-right', !isHidden);
        icon.classList.toggle('bi-chevron-down',   isHidden);
    }

    if (isHidden && content.dataset.loaded === '0') {
        content.dataset.loaded = '1';
        fetch('ajax/po_items.php?po_id=' + poId)
            .then(r => r.text())
            .then(html => { content.innerHTML = html; })
            .catch(() => { content.innerHTML = '<span class="text-danger small">Failed to load items.</span>'; });
    }
}

// ── Receive Items Modal ────────────────────────────────────────────────────
function openReceiveModal(poId, poNumber) {
    document.getElementById('receive_po_id').value = poId;
    document.getElementById('receiveModalLabel').innerHTML =
        '<i class="bi bi-box-arrow-in-down"></i> Receive Goods — ' + poNumber;

    const content = document.getElementById('receive-modal-content');
    content.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-arrow-clockwise"></i> Loading…</div>';

    const modal = new bootstrap.Modal(document.getElementById('receiveModal'));
    modal.show();

    fetch('ajax/po_items.php?po_id=' + poId + '&mode=receive')
        .then(r => r.text())
        .then(html => { content.innerHTML = html; })
        .catch(() => { content.innerHTML = '<span class="text-danger small">Failed to load items.</span>'; });
}
</script>
