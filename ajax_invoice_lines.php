<?php
/**
 * ajax_invoice_lines.php
 * Returns HTML fragment showing all DOs inside a sales_invoice.
 */
require_once 'config/database.php';
require_once 'includes/functions.php';

$db  = getDB();
$inv_id = (int)($_GET['invoice_id'] ?? 0);
if ($inv_id <= 0) { echo '<p class="text-danger p-3">Invalid invoice ID.</p>'; exit; }

$inv = $db->prepare("
    SELECT si.*, cu.customer_name, c.company_name
    FROM sales_invoices si
    JOIN customers cu ON si.customer_id = cu.customer_id
    JOIN companies  c ON si.company_id  = c.company_id
    WHERE si.invoice_id = ?
");
$inv->execute([$inv_id]);
$header = $inv->fetch(PDO::FETCH_ASSOC);
if (!$header) { echo '<p class="text-danger p-3">Invoice not found.</p>'; exit; }

$lines = $db->prepare("
    SELECT sil.*, sc.contract_number
    FROM sales_invoice_lines sil
    JOIN delivery_orders do2 ON sil.do_id = do2.do_id
    JOIN sales_contracts sc  ON do2.contract_id = sc.contract_id
    WHERE sil.invoice_id = ?
    ORDER BY sil.do_date, sil.line_id
");
$lines->execute([$inv_id]);
$lines = $lines->fetchAll(PDO::FETCH_ASSOC);

$total_qty = array_sum(array_column($lines, 'quantity_kg'));
$total_amt = array_sum(array_column($lines, 'line_amount'));
$product_colours = ['FFB'=>'success','CPO'=>'warning','Kernel'=>'info','PKO'=>'primary','Other'=>'secondary'];
?>

<div class="border-bottom pb-2 mb-3">
    <strong><?= htmlspecialchars($header['invoice_number']) ?></strong>
    &nbsp;—&nbsp; <?= date('d/m/Y', strtotime($header['invoice_date'])) ?>
    &nbsp;|&nbsp; <?= htmlspecialchars($header['customer_name']) ?>
    &nbsp;|&nbsp;
    <?php if ($header['period_from']): ?>
        Period: <strong><?= date('d/m/Y', strtotime($header['period_from'])) ?>
        <?= $header['period_to'] !== $header['period_from'] ? ' ~ ' . date('d/m/Y', strtotime($header['period_to'])) : '' ?></strong>
    <?php endif; ?>
    &nbsp;|&nbsp; Total: <strong class="text-success">Rp <?= number_format($header['total_amount'], 0) ?></strong>
</div>

<?php if (empty($lines)): ?>
    <p class="text-muted text-center py-2">No lines found.</p>
<?php else: ?>
<table class="table table-sm table-bordered mb-0">
    <thead class="table-light">
        <tr>
            <th>#</th>
            <th>DO Number</th>
            <th>DO Date</th>
            <th>Contract</th>
            <th>Product</th>
            <th class="text-end">Qty (MT)</th>
            <th class="text-end">Unit Price</th>
            <th class="text-end">Amount (Rp)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($lines as $i => $l): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td class="fw-bold"><?= htmlspecialchars($l['do_number']) ?></td>
            <td><?= date('d/m/Y', strtotime($l['do_date'])) ?></td>
            <td><?= htmlspecialchars($l['contract_number']) ?></td>
            <td><span class="badge bg-<?= $product_colours[$l['product_type']] ?? 'secondary' ?>"><?= $l['product_type'] ?></span></td>
            <td class="text-end"><?= number_format($l['quantity_kg'] / 1000, 3) ?></td>
            <td class="text-end"><?= number_format($l['unit_price'], 0) ?></td>
            <td class="text-end fw-bold"><?= number_format($l['line_amount'], 0) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light fw-bold">
        <tr>
            <td colspan="5">Total (<?= count($lines) ?> DOs)</td>
            <td class="text-end"><?= number_format($total_qty / 1000, 3) ?> MT</td>
            <td></td>
            <td class="text-end">Rp <?= number_format($total_amt, 0) ?></td>
        </tr>
    </tfoot>
</table>
<?php endif; ?>
