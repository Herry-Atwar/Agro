<?php
/**
 * ajax_delivery_lines.php
 * Returns HTML fragment for the contract lines of a product delivery.
 * Called by product_deliveries.php via fetch().
 */
require_once 'config/database.php';
require_once 'includes/functions.php';

$db         = getDB();
$del_id     = (int)($_GET['delivery_id'] ?? 0);
$format     = $_GET['format'] ?? 'html';

if ($del_id <= 0) {
    if ($format === 'json') { header('Content-Type: application/json'); echo json_encode(['error' => 'Invalid delivery ID']); }
    else { echo '<p class="text-danger p-3">Invalid delivery ID.</p>'; }
    exit;
}

// Header
$pd = $db->prepare("
    SELECT pd.*, cu.customer_name, c.company_name
    FROM product_deliveries pd
    JOIN customers  cu ON pd.customer_id = cu.customer_id
    JOIN companies  c  ON pd.company_id  = c.company_id
    WHERE pd.delivery_id = ?
");
$pd->execute([$del_id]);
$header = $pd->fetch(PDO::FETCH_ASSOC);
if (!$header) {
    if ($format === 'json') { header('Content-Type: application/json'); echo json_encode(['error' => 'Delivery not found']); }
    else { echo '<p class="text-danger p-3">Delivery not found.</p>'; }
    exit;
}

// Lines
$lines_stmt = $db->prepare("
    SELECT dcl.*, sc.contract_number, sc.product_type, cu.customer_name AS sc_customer
    FROM delivery_contract_lines dcl
    JOIN sales_contracts sc ON dcl.contract_id = sc.contract_id
    JOIN customers cu        ON sc.customer_id  = cu.customer_id
    WHERE dcl.delivery_id = ?
    ORDER BY dcl.line_id
");
$lines_stmt->execute([$del_id]);
$lines = $lines_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── JSON response for Edit modal ──────────────────────────────────────────────
if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode(['header' => $header, 'lines' => $lines]);
    exit;
}

$total_qty = array_sum(array_column($lines, 'quantity_kg'));
$total_amt = array_sum(array_column($lines, 'line_amount'));
$product_colours = ['FFB'=>'success','CPO'=>'warning','Kernel'=>'info','PKO'=>'primary','Other'=>'secondary'];
?>
<div class="border-bottom pb-2 mb-3">
    <strong><?= htmlspecialchars($header['delivery_number']) ?></strong>
    &nbsp;—&nbsp; <?= date('d/m/Y', strtotime($header['delivery_date'])) ?>
    &nbsp;|&nbsp;
    <span class="badge bg-<?= $product_colours[$header['product_type']] ?? 'secondary' ?>"><?= $header['product_type'] ?></span>
    &nbsp;|&nbsp; Net: <strong><?= number_format($header['net_weight_kg']/1000, 3) ?> MT</strong>
    &nbsp;|&nbsp; <?= htmlspecialchars($header['customer_name']) ?>
</div>

<?php if (empty($lines)): ?>
    <p class="text-muted text-center py-2">No contract lines defined yet.</p>
<?php else: ?>
<table class="table table-sm table-bordered mb-0">
    <thead class="table-light">
        <tr>
            <th>#</th>
            <th>Contract</th>
            <th>Customer</th>
            <th class="text-end">Qty (kg)</th>
            <th class="text-end">Qty (MT)</th>
            <th class="text-end">Unit Price</th>
            <th class="text-end">Line Amount (Rp)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($lines as $i => $l): ?>
        <tr>
            <td><?= $i+1 ?></td>
            <td>
                <a href="sales_contracts.php" class="text-decoration-none fw-bold">
                    <?= htmlspecialchars($l['contract_number']) ?>
                </a>
            </td>
            <td><?= htmlspecialchars($l['sc_customer']) ?></td>
            <td class="text-end"><?= number_format($l['quantity_kg'], 2) ?></td>
            <td class="text-end"><?= number_format($l['quantity_kg']/1000, 3) ?></td>
            <td class="text-end"><?= number_format($l['unit_price'], 2) ?></td>
            <td class="text-end"><?= number_format($l['line_amount'], 0) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light fw-bold">
        <tr>
            <td colspan="3">Total</td>
            <td class="text-end"><?= number_format($total_qty, 2) ?></td>
            <td class="text-end"><?= number_format($total_qty/1000, 3) ?></td>
            <td></td>
            <td class="text-end">Rp <?= number_format($total_amt, 0) ?></td>
        </tr>
    </tfoot>
</table>

<?php
$diff = $header['net_weight_kg'] - $total_qty;
if (abs($diff) > 0.01):
    $cls = $diff > 0 ? 'warning' : 'danger';
    $msg = $diff > 0
        ? 'Under-allocated by ' . number_format($diff, 2) . ' kg'
        : 'Over-allocated by '  . number_format(abs($diff), 2) . ' kg';
?>
<div class="alert alert-<?= $cls ?> py-1 mt-2 mb-0 small">
    <i class="bi bi-exclamation-triangle"></i> <?= $msg ?>
</div>
<?php else: ?>
<div class="alert alert-success py-1 mt-2 mb-0 small">
    <i class="bi bi-check-circle"></i> Fully allocated — net weight matches contract lines.
</div>
<?php endif; ?>

<?php endif; ?>
