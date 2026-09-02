<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

$db    = getDB();
$pr_id = (int)(get('pr_id') ?: 0);

if (!$pr_id) {
    echo '<span class="text-danger small">Invalid request.</span>';
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT
            pri.pr_item_id,
            m.material_name,
            m.material_code,
            pri.required_qty,
            pri.approved_qty,
            pri.unit,
            pri.estimated_unit_price,
            pri.estimated_total,
            pri.status
        FROM pr_items pri
        JOIN materials m ON m.material_id = pri.material_id
        WHERE pri.pr_id = ?
        ORDER BY pri.pr_item_id
    ");
    $stmt->execute([$pr_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo '<span class="text-danger small">Error: ' . htmlspecialchars($e->getMessage()) . '</span>';
    exit;
}

$item_status_colours = [
    'pending'   => 'secondary',
    'ordered'   => 'success',
    'received'  => 'info',
    'cancelled' => 'danger',
];
?>
<?php if (empty($items)): ?>
    <p class="text-muted small mb-0">No items found for this PR.</p>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-sm table-bordered mb-0" style="font-size:.85rem;">
        <thead class="table-secondary">
            <tr>
                <th>#</th>
                <th>Material</th>
                <th class="text-end">Required Qty</th>
                <th class="text-end">Approved Qty</th>
                <th>Unit</th>
                <th class="text-end">Unit Price (Rp)</th>
                <th class="text-end">Est. Total (Rp)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i => $row): ?>
            <tr>
                <td class="text-muted"><?= $i + 1 ?></td>
                <td>
                    <span class="fw-semibold"><?= htmlspecialchars($row['material_name']) ?></span>
                    <br><small class="text-muted"><?= htmlspecialchars($row['material_code']) ?></small>
                </td>
                <td class="text-end"><?= number_format((float)$row['required_qty'], 2) ?></td>
                <td class="text-end"><?= number_format((float)$row['approved_qty'], 2) ?></td>
                <td><?= htmlspecialchars($row['unit'] ?? '—') ?></td>
                <td class="text-end"><?= number_format((float)$row['estimated_unit_price'], 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format((float)$row['estimated_total'], 0, ',', '.') ?></td>
                <td>
                    <span class="badge bg-<?= $item_status_colours[$row['status']] ?? 'secondary' ?>">
                        <?= ucfirst($row['status']) ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
            <tr>
                <td colspan="6" class="text-end">Total</td>
                <td class="text-end">Rp <?= number_format(array_sum(array_column($items,'estimated_total')), 0, ',', '.') ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>
