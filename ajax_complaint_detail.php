<?php
// ─── AJAX: Complaint detail fragment ─────────────────────────────────────────
// Called by openDetailModal() in delivery_complaints.php
// Returns an HTML fragment (no full page layout).

require_once 'config/database.php';
require_once 'includes/functions.php';

$db  = getDB();
$cid = (int)($_GET['complaint_id'] ?? 0);
if (!$cid) { echo '<div class="alert alert-danger">Invalid complaint ID.</div>'; exit; }

// ── Header ────────────────────────────────────────────────────────────────────
$cmp = $db->prepare("
    SELECT cmp.*,
           cu.customer_name, c.company_name,
           pd.delivery_number, pd.delivery_date, pd.product_type,
           pd.net_weight_kg      AS del_net_kg,
           gr.receiving_number,  gr.receiving_date,
           gr.received_net_kg,   gr.quantity_status, gr.quality_status,
           gr.weight_difference_kg, gr.deduction_amount AS gr_deduction,
           gr.received_by,       gr.receiver_position
    FROM delivery_complaints cmp
    JOIN customers           cu  ON cmp.customer_id  = cu.customer_id
    JOIN companies           c   ON cmp.company_id   = c.company_id
    JOIN product_deliveries  pd  ON cmp.delivery_id  = pd.delivery_id
    JOIN delivery_receivings gr  ON cmp.receiving_id = gr.receiving_id
    WHERE cmp.complaint_id = ?
");
$cmp->execute([$cid]);
$r = $cmp->fetch(PDO::FETCH_ASSOC);
if (!$r) { echo '<div class="alert alert-warning">Complaint not found.</div>'; exit; }

// ── Items ─────────────────────────────────────────────────────────────────────
$items = $db->prepare("SELECT * FROM complaint_items WHERE complaint_id = ? ORDER BY item_id");
$items->execute([$cid]);
$item_rows = $items->fetchAll(PDO::FETCH_ASSOC);

$status_colours = [
    'open'         => 'danger',
    'under_review' => 'warning',
    'resolved'     => 'success',
    'rejected'     => 'secondary',
    'closed'       => 'dark',
];
$type_colours = [
    'quantity'         => 'info',
    'quality'          => 'warning',
    'quantity_quality' => 'danger',
    'packaging'        => 'primary',
    'other'            => 'secondary',
];
?>
<!-- ── Complaint header info ───────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card border-danger h-100">
            <div class="card-header py-2 bg-danger text-white"><i class="bi bi-exclamation-triangle-fill"></i> Complaint</div>
            <div class="card-body py-2 small">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted" style="width:46%">Number</th><td class="fw-bold"><?= htmlspecialchars($r['complaint_number']) ?></td></tr>
                    <tr><th class="text-muted">Date</th><td><?= date('d/m/Y', strtotime($r['complaint_date'])) ?></td></tr>
                    <tr><th class="text-muted">Type</th>
                        <td><span class="badge bg-<?= $type_colours[$r['complaint_type']] ?? 'secondary' ?>">
                            <?= ucwords(str_replace('_',' ',$r['complaint_type'])) ?></span></td></tr>
                    <tr><th class="text-muted">Status</th>
                        <td><span class="badge bg-<?= $status_colours[$r['status']] ?? 'secondary' ?>">
                            <?= ucwords(str_replace('_',' ',$r['status'])) ?></span></td></tr>
                    <tr><th class="text-muted">Customer</th><td><?= htmlspecialchars($r['customer_name']) ?><br><small class="text-muted"><?= htmlspecialchars($r['company_name']) ?></small></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-warning h-100">
            <div class="card-header py-2 bg-warning text-dark"><i class="bi bi-truck"></i> Delivery / Receiving</div>
            <div class="card-body py-2 small">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted" style="width:50%">Delivery #</th><td><?= htmlspecialchars($r['delivery_number']) ?></td></tr>
                    <tr><th class="text-muted">Del. Date</th><td><?= date('d/m/Y', strtotime($r['delivery_date'])) ?></td></tr>
                    <tr><th class="text-muted">Product</th><td><?= htmlspecialchars($r['product_type']) ?></td></tr>
                    <tr><th class="text-muted">Supplier Net</th><td><?= number_format($r['del_net_kg'] / 1000, 3) ?> MT</td></tr>
                    <tr><th class="text-muted">GR #</th><td><?= htmlspecialchars($r['receiving_number']) ?></td></tr>
                    <tr><th class="text-muted">Received Net</th><td><?= number_format($r['received_net_kg'] / 1000, 3) ?> MT</td></tr>
                    <tr><th class="text-muted">Variance</th>
                        <td class="<?= $r['weight_difference_kg'] < 0 ? 'text-danger' : 'text-success' ?>">
                            <?= ($r['weight_difference_kg'] >= 0 ? '+' : '') . number_format($r['weight_difference_kg'], 0) ?> kg
                        </td></tr>
                    <tr><th class="text-muted">Qty Status</th><td><?= ucfirst($r['quantity_status']) ?></td></tr>
                    <tr><th class="text-muted">Quality</th><td><?= ucwords(str_replace('_',' ',$r['quality_status'])) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-success h-100">
            <div class="card-header py-2 bg-success text-white"><i class="bi bi-cash-coin"></i> Financial Settlement</div>
            <div class="card-body py-2 small">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted" style="width:50%">Claimed (Rp)</th>
                        <td class="text-danger fw-bold"><?= number_format($r['claimed_deduction'], 0) ?></td></tr>
                    <tr><th class="text-muted">Agreed (Rp)</th>
                        <td class="fw-bold <?= $r['agreed_deduction'] > 0 ? 'text-warning' : '' ?>">
                            <?= $r['agreed_deduction'] > 0 ? number_format($r['agreed_deduction'], 0) : '—' ?>
                        </td></tr>
                    <tr><th class="text-muted">Credit Note</th>
                        <td><?= $r['credit_note_number'] ? '<span class="badge bg-success">'.htmlspecialchars($r['credit_note_number']).'</span>' : '<span class="text-muted">—</span>' ?></td></tr>
                    <tr><th class="text-muted">Journal Entry</th>
                        <td><?= $r['journal_entry_id'] ? '<span class="badge bg-info">#'.htmlspecialchars($r['journal_entry_id']).'</span>' : '<span class="text-muted">—</span>' ?></td></tr>
                    <?php if ($r['resolved_at']): ?>
                    <tr><th class="text-muted">Resolved</th><td><?= date('d/m/Y', strtotime($r['resolved_at'])) ?> by <?= htmlspecialchars($r['resolved_by'] ?? '—') ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Subject & Description -->
<div class="mb-3 p-3 bg-light rounded border">
    <div class="fw-bold mb-1"><i class="bi bi-chat-text"></i> <?= htmlspecialchars($r['subject']) ?></div>
    <?php if ($r['description']): ?>
        <div class="text-muted small"><?= nl2br(htmlspecialchars($r['description'])) ?></div>
    <?php endif; ?>
</div>

<!-- Resolution -->
<?php if ($r['resolution']): ?>
<div class="mb-3 p-3 bg-success bg-opacity-10 rounded border border-success">
    <div class="fw-bold text-success mb-1"><i class="bi bi-check-circle"></i> Resolution</div>
    <div class="small"><?= nl2br(htmlspecialchars($r['resolution'])) ?></div>
</div>
<?php endif; ?>

<!-- Complaint items -->
<?php if (!empty($item_rows)): ?>
<h6 class="text-danger mb-2"><i class="bi bi-list-ul"></i> Complaint Item Lines</h6>
<div class="table-responsive">
    <table class="table table-sm table-bordered align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>#</th>
                <th>Issue Type</th>
                <th>Parameter</th>
                <th>Description</th>
                <th class="text-end">Contract</th>
                <th class="text-end">Actual</th>
                <th>Unit</th>
                <th class="text-end">Qty Affected (kg)</th>
                <th class="text-end">Claimed (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($item_rows as $i => $item): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><span class="badge bg-secondary"><?= ucwords(str_replace('_',' ',$item['item_type'])) ?></span></td>
                <td><?= htmlspecialchars($item['param_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($item['description']) ?></td>
                <td class="text-end"><?= $item['contract_value'] !== null ? number_format($item['contract_value'], 4) : '—' ?></td>
                <td class="text-end <?= ($item['contract_value'] !== null && $item['actual_value'] !== null && $item['actual_value'] > $item['contract_value']) ? 'text-danger fw-bold' : '' ?>">
                    <?= $item['actual_value'] !== null ? number_format($item['actual_value'], 4) : '—' ?>
                </td>
                <td><?= htmlspecialchars($item['unit'] ?? '—') ?></td>
                <td class="text-end"><?= $item['quantity_affected_kg'] !== null ? number_format($item['quantity_affected_kg'], 0) : '—' ?></td>
                <td class="text-end text-danger"><?= number_format($item['claimed_amount'], 0) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
            <tr>
                <td colspan="8" class="text-end fw-bold">Total Claimed:</td>
                <td class="text-end fw-bold text-danger">
                    <?= number_format(array_sum(array_column($item_rows, 'claimed_amount')), 0) ?>
                </td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>
