<?php
/**
 * ajax_receiving_params.php
 * Returns HTML fragment of quality parameters for a receiving record.
 */
require_once 'config/database.php';
require_once 'includes/functions.php';

$db  = getDB();
$rid = (int)($_GET['receiving_id'] ?? 0);
if ($rid <= 0) { echo '<p class="text-danger p-3">Invalid receiving ID.</p>'; exit; }

$gr = $db->prepare("
    SELECT gr.*, pd.delivery_number, pd.product_type, cu.customer_name
    FROM delivery_receivings gr
    JOIN product_deliveries pd ON gr.delivery_id = pd.delivery_id
    JOIN customers cu           ON gr.customer_id = cu.customer_id
    WHERE gr.receiving_id = ?
");
$gr->execute([$rid]);
$header = $gr->fetch(PDO::FETCH_ASSOC);
if (!$header) { echo '<p class="text-danger p-3">Record not found.</p>'; exit; }

$params = $db->prepare("SELECT * FROM receiving_quality_params WHERE receiving_id=? ORDER BY param_id");
$params->execute([$rid]);
$rows = $params->fetchAll(PDO::FETCH_ASSOC);

$product_colours = ['FFB'=>'success','CPO'=>'warning','Kernel'=>'info','PKO'=>'primary','Other'=>'secondary'];
?>
<div class="border-bottom pb-2 mb-3">
    <strong><?= htmlspecialchars($header['receiving_number']) ?></strong>
    &nbsp;|&nbsp; <?= date('d/m/Y', strtotime($header['receiving_date'])) ?>
    &nbsp;|&nbsp;
    <span class="badge bg-<?= $product_colours[$header['product_type']]??'secondary' ?>"><?= $header['product_type'] ?></span>
    &nbsp;|&nbsp; <?= htmlspecialchars($header['customer_name']) ?>
    &nbsp;|&nbsp; Delivery: <strong><?= htmlspecialchars($header['delivery_number']) ?></strong>
</div>

<div class="row g-2 mb-3">
    <div class="col-auto">
        <span class="badge bg-<?= $header['quantity_status']==='accepted'?'success':'danger' ?> p-2">
            Qty: <?= ucfirst($header['quantity_status']) ?>
        </span>
    </div>
    <div class="col-auto">
        <span class="badge bg-<?= $header['quality_status']==='accepted'?'success':($header['quality_status']==='conditionally_accepted'?'warning':'danger') ?> p-2">
            Quality: <?= ucwords(str_replace('_',' ',$header['quality_status'])) ?>
        </span>
    </div>
    <div class="col-auto">
        <span class="badge bg-secondary p-2">
            Variance: <?= number_format($header['received_net_kg'] - 0, 0) ?> vs <?= number_format($header['weight_difference_kg'],0) ?> kg diff
        </span>
    </div>
</div>

<?php if (empty($rows)): ?>
    <p class="text-muted text-center py-2">No quality parameters recorded.</p>
<?php else: ?>
<table class="table table-sm table-bordered mb-2">
    <thead class="table-light">
        <tr>
            <th>Parameter</th>
            <th>Unit</th>
            <th class="text-center">Contract Spec</th>
            <th class="text-center">Actual</th>
            <th class="text-center">Tolerance ±</th>
            <th class="text-center">Result</th>
            <th>Remarks</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['pass']?'table-success':'table-danger' ?>">
            <td class="fw-bold"><?= htmlspecialchars($r['param_name']) ?></td>
            <td><?= htmlspecialchars($r['unit'] ?? '—') ?></td>
            <td class="text-center"><?= $r['contract_spec'] !== null ? $r['contract_spec'] : '—' ?></td>
            <td class="text-center fw-bold"><?= $r['actual_value'] ?></td>
            <td class="text-center"><?= $r['tolerance'] !== null ? '±'.$r['tolerance'] : '—' ?></td>
            <td class="text-center">
                <?php if ($r['pass']): ?>
                    <span class="badge bg-success">✓ PASS</span>
                <?php else: ?>
                    <span class="badge bg-danger">✗ FAIL</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['remarks'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
$fails = array_filter($rows, fn($r)=>!$r['pass']);
if (count($fails)):
?>
<div class="alert alert-danger py-1 mb-0 small">
    <i class="bi bi-exclamation-triangle"></i>
    <strong><?= count($fails) ?> parameter(s) FAILED.</strong>
    Consider raising a <a href="delivery_complaints.php?receiving_id=<?= $rid ?>">complaint</a>.
</div>
<?php else: ?>
<div class="alert alert-success py-1 mb-0 small">
    <i class="bi bi-check-circle"></i> All <?= count($rows) ?> parameter(s) passed.
</div>
<?php endif; ?>
<?php endif; ?>
