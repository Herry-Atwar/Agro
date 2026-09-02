<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

$db    = getDB();
$po_id = (int)(get('po_id') ?: 0);
$mode  = get('mode') ?: 'view';   // 'view' | 'receive'

if (!$po_id) {
    echo '<span class="text-danger small">Invalid request.</span>';
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT
            poi.po_item_id,
            poi.ordered_qty,
            poi.received_qty,
            poi.unit_price,
            poi.total_price,
            poi.received_date,
            poi.status,
            m.material_name,
            m.material_code,
            m.unit AS unit_of_measure,
            mw.warehouse_name
        FROM po_items poi
        JOIN materials          m  ON m.material_id    = poi.material_id
        LEFT JOIN material_warehouses mw ON mw.warehouse_id  = poi.warehouse_id
        WHERE poi.po_id = ?
        ORDER BY poi.po_item_id
    ");
    $stmt->execute([$po_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo '<span class="text-danger small">Error: ' . htmlspecialchars($e->getMessage()) . '</span>';
    exit;
}

$item_status_colours = [
    'pending'   => 'secondary',
    'partial'   => 'warning',
    'received'  => 'success',
    'cancelled' => 'danger',
];

if (empty($items)):
?>
    <p class="text-muted small mb-0">No items found for this PO.</p>
<?php elseif ($mode === 'receive'):
    // Filter to items that are still pending/partial (receivable)
    $receivable = array_filter($items, fn($r) => in_array($r['status'], ['pending','partial']));
    if (empty($receivable)):
?>
    <div class="alert alert-info mb-0">All items on this PO have already been received or cancelled.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-sm table-bordered mb-0" style="font-size:.85rem;">
        <thead class="table-secondary">
            <tr>
                <th>#</th>
                <th>Material</th>
                <th class="text-end">Ordered Qty</th>
                <th>Unit</th>
                <th>Warehouse</th>
                <th class="text-end">Unit Price (Rp)</th>
                <th class="text-end" style="width:130px;">Receive Qty</th>
            </tr>
        </thead>
        <tbody>
        <?php $i = 0; foreach ($receivable as $row): $i++; ?>
            <input type="hidden" name="po_item_id[]" value="<?= (int)$row['po_item_id'] ?>">
            <tr>
                <td class="text-muted"><?= $i ?></td>
                <td>
                    <span class="fw-semibold"><?= htmlspecialchars($row['material_name']) ?></span>
                    <br><small class="text-muted"><?= htmlspecialchars($row['material_code']) ?></small>
                </td>
                <td class="text-end"><?= number_format((float)$row['ordered_qty'], 2) ?></td>
                <td><?= htmlspecialchars($row['unit_of_measure'] ?? '—') ?></td>
                <td><?= htmlspecialchars($row['warehouse_name'] ?? '—') ?></td>
                <td class="text-end"><?= number_format((float)$row['unit_price'], 0, ',', '.') ?></td>
                <td class="text-end">
                    <input type="number"
                           name="received_qty[]"
                           class="form-control form-control-sm text-end"
                           min="0"
                           step="0.01"
                           value="<?= number_format((float)$row['ordered_qty'] - (float)$row['received_qty'], 2, '.', '') ?>"
                           style="width:110px;">
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="mt-2 text-muted small">
    <i class="bi bi-info-circle"></i>
    Enter <strong>0</strong> for any item you are not receiving now.
    Partial deliveries are supported — PO will be marked <em>Partially Received</em>.
</div>
<?php endif; ?>
<?php else: // mode = view ?>
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
                <th class="text-end">Total Price (Rp)</th>
                <th>Warehouse</th>
                <th>Received Date</th>
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
                <td class="text-end"><?= number_format((float)$row['ordered_qty'], 2) ?></td>
                <td class="text-end"><?= number_format((float)$row['received_qty'], 2) ?></td>
                <td><?= htmlspecialchars($row['unit_of_measure'] ?? '—') ?></td>
                <td class="text-end"><?= number_format((float)$row['unit_price'], 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format((float)$row['total_price'], 0, ',', '.') ?></td>
                <td><?= htmlspecialchars($row['warehouse_name'] ?? '—') ?></td>
                <td><?= $row['received_date'] ? htmlspecialchars($row['received_date']) : '<span class="text-muted">—</span>' ?></td>
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
                <td class="text-end">Rp <?= number_format(array_sum(array_column($items,'total_price')), 0, ',', '.') ?></td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>
