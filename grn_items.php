<?php
/**
 * ajax/grn_items.php
 * Returns GRN line items as HTML for the detail panel.
 */
ob_start();
ini_set('display_errors', '0');
require_once '../config/database.php';
require_once '../includes/functions.php';
ob_clean();

$db     = getDB();
$grn_id = (int)(get('grn_id') ?: 0);

if (!$grn_id) { echo '<span class="text-danger small">Invalid request.</span>'; exit; }

try {
    $stmt = $db->prepare("
        SELECT
            poi.po_item_id,
            poi.ordered_qty,
            poi.received_qty,
            poi.unit_price,
            poi.received_qty * poi.unit_price  AS line_total,
            poi.received_date,
            m.material_name,
            m.material_code,
            m.unit                             AS unit_of_measure,
            mw.warehouse_name
        FROM po_items poi
        JOIN materials            m  ON m.material_id   = poi.material_id
        LEFT JOIN material_warehouses mw ON mw.warehouse_id = poi.warehouse_id
        WHERE poi.grn_id = ?
        ORDER BY poi.po_item_id
    ");
    $stmt->execute([$grn_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo '<span class="text-danger small">Error: ' . htmlspecialchars($e->getMessage()) . '</span>';
    exit;
}

if (empty($items)): ?>
    <p class="text-muted small mb-0">No items found for this GRN.</p>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-sm table-bordered mb-0" style="font-size:.85rem;">
        <thead class="table-secondary">
            <tr>
                <th>#</th>
                <th>Material</th>
                <th class="text-end">Ordered Qty</th>
                <th class="text-end">Received Qty</th>
                <th>Unit</th>
                <th class="text-end">Unit Price (Rp)</th>
                <th class="text-end">Line Total (Rp)</th>
                <th>Warehouse</th>
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
                <td class="text-end"><?= number_format((float)$row['ordered_qty'],  2) ?></td>
                <td class="text-end fw-semibold text-success"><?= number_format((float)$row['received_qty'], 2) ?></td>
                <td><?= htmlspecialchars($row['unit_of_measure'] ?? '—') ?></td>
                <td class="text-end"><?= number_format((float)$row['unit_price'],  0, ',', '.') ?></td>
                <td class="text-end"><?= number_format((float)$row['line_total'],  0, ',', '.') ?></td>
                <td><?= htmlspecialchars($row['warehouse_name'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
            <tr>
                <td colspan="6" class="text-end">Total Received Value</td>
                <td class="text-end">Rp <?= number_format(array_sum(array_column($items, 'line_total')), 0, ',', '.') ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>
