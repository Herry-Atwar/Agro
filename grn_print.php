<?php
/**
 * grn_print.php  — Printable / PDF-ready GRN document
 * Usage: grn_print.php?grn_id=5
 */
require_once 'config/database.php';
require_once 'includes/functions.php';

$db     = getDB();
$grn_id = (int)(get('grn_id') ?: 0);
if (!$grn_id) { echo 'GRN not found.'; exit; }

// ── Header ────────────────────────────────────────────────────────────────────
try {
    $hdr = $db->prepare("
        SELECT g.*, po.po_number, po.supplier_name, po.supplier_contact,
               pr.pr_number, d.division_name,
               je.reference_number AS journal_ref
        FROM grn_headers g
        JOIN purchase_orders       po ON po.po_id      = g.po_id
        JOIN purchase_requisitions pr ON pr.pr_id      = po.pr_id
        JOIN divisions             d  ON d.division_id = pr.division_id
        LEFT JOIN journal_entries  je ON je.id         = g.journal_entry_id
        WHERE g.grn_id = ?
    ");
    $hdr->execute([$grn_id]);
    $grn = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$grn) { echo 'GRN not found.'; exit; }
} catch (PDOException $e) { echo 'Error: ' . $e->getMessage(); exit; }

// ── Items ─────────────────────────────────────────────────────────────────────
try {
    $itm = $db->prepare("
        SELECT m.material_code, m.material_name, m.unit,
               poi.ordered_qty, poi.received_qty, poi.unit_price,
               poi.received_qty * poi.unit_price AS line_total,
               mw.warehouse_name
        FROM po_items poi
        JOIN materials            m  ON m.material_id   = poi.material_id
        LEFT JOIN material_warehouses mw ON mw.warehouse_id = poi.warehouse_id
        WHERE poi.grn_id = ?
        ORDER BY poi.po_item_id
    ");
    $itm->execute([$grn_id]);
    $items = $itm->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $items = []; }

$grand_total = array_sum(array_column($items, 'line_total'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GRN <?= htmlspecialchars($grn['grn_number']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; background: #fff; padding: 24px; }
        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; border-bottom: 2px solid #111; padding-bottom: 12px; }
        .company-name { font-size: 18px; font-weight: bold; }
        .doc-title { text-align: right; }
        .doc-title h2 { font-size: 20px; font-weight: bold; letter-spacing: 1px; }
        .doc-title .grn-num { font-size: 14px; color: #555; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .meta-block { border: 1px solid #ccc; border-radius: 4px; padding: 10px 12px; }
        .meta-block h4 { font-size: 10px; text-transform: uppercase; letter-spacing: .6px; color: #777; margin-bottom: 6px; }
        .meta-row { display: flex; margin-bottom: 3px; }
        .meta-label { width: 130px; color: #555; flex-shrink: 0; }
        .meta-value { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th { background: #f0f0f0; border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 11px; }
        td { border: 1px solid #ccc; padding: 6px 8px; }
        .text-right { text-align: right; }
        tfoot td { background: #f7f7f7; font-weight: bold; }
        .sig-area { display: flex; justify-content: space-around; margin-top: 32px; }
        .sig-box { text-align: center; width: 160px; }
        .sig-line { border-top: 1px solid #555; margin-top: 48px; padding-top: 4px; font-size: 11px; }
        .journal-box { background: #f0f8f0; border: 1px solid #7cc77c; border-radius: 4px; padding: 8px 12px; margin-bottom: 16px; font-size: 11px; }
        .journal-box strong { color: #1a6b1a; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="no-print" style="margin-bottom:16px;">
    <button onclick="window.print()" style="padding:8px 16px;background:#198754;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;">
        🖨 Print / Save as PDF
    </button>
    <a href="grn.php" style="margin-left:8px;font-size:13px;">← Back to GRN List</a>
</div>

<!-- Header -->
<div class="doc-header">
    <div>
        <div class="company-name">erpAgro</div>
        <div style="color:#555;margin-top:2px;">Agrobusiness Management System</div>
    </div>
    <div class="doc-title">
        <h2>GOODS RECEIPT NOTE</h2>
        <div class="grn-num"><?= htmlspecialchars($grn['grn_number']) ?></div>
    </div>
</div>

<!-- Meta -->
<div class="meta-grid">
    <div class="meta-block">
        <h4>Receipt Details</h4>
        <div class="meta-row"><span class="meta-label">GRN Number</span><span class="meta-value"><?= htmlspecialchars($grn['grn_number']) ?></span></div>
        <div class="meta-row"><span class="meta-label">GRN Date</span><span class="meta-value"><?= date('d/m/Y', strtotime($grn['grn_date'])) ?></span></div>
        <div class="meta-row"><span class="meta-label">PO Reference</span><span class="meta-value"><?= htmlspecialchars($grn['po_number']) ?></span></div>
        <div class="meta-row"><span class="meta-label">PR Reference</span><span class="meta-value"><?= htmlspecialchars($grn['pr_number']) ?></span></div>
        <div class="meta-row"><span class="meta-label">Division</span><span class="meta-value"><?= htmlspecialchars($grn['division_name']) ?></span></div>
    </div>
    <div class="meta-block">
        <h4>Supplier Details</h4>
        <div class="meta-row"><span class="meta-label">Supplier</span><span class="meta-value"><?= htmlspecialchars($grn['supplier_name']) ?></span></div>
        <?php if ($grn['supplier_contact']): ?>
        <div class="meta-row"><span class="meta-label">Contact</span><span class="meta-value"><?= htmlspecialchars($grn['supplier_contact']) ?></span></div>
        <?php endif; ?>
        <?php if ($grn['supplier_do_no']): ?>
        <div class="meta-row"><span class="meta-label">Supplier DO No.</span><span class="meta-value"><?= htmlspecialchars($grn['supplier_do_no']) ?></span></div>
        <?php endif; ?>
        <div class="meta-row"><span class="meta-label">Received By</span><span class="meta-value"><?= htmlspecialchars($grn['received_by'] ?: '—') ?></span></div>
    </div>
</div>

<?php if ($grn['journal_ref']): ?>
<div class="journal-box">
    <strong>Journal Entry Auto-Posted:</strong>
    <?= htmlspecialchars($grn['journal_ref']) ?> — Dr Inventory / Cr Accounts Payable
</div>
<?php endif; ?>

<!-- Items -->
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Material Code</th>
            <th>Material Name</th>
            <th class="text-right">Ordered Qty</th>
            <th class="text-right">Received Qty</th>
            <th>Unit</th>
            <th class="text-right">Unit Price (Rp)</th>
            <th class="text-right">Line Total (Rp)</th>
            <th>Warehouse</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $i => $row): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($row['material_code']) ?></td>
            <td><?= htmlspecialchars($row['material_name']) ?></td>
            <td class="text-right"><?= number_format((float)$row['ordered_qty'],  2) ?></td>
            <td class="text-right"><strong><?= number_format((float)$row['received_qty'], 2) ?></strong></td>
            <td><?= htmlspecialchars($row['unit']) ?></td>
            <td class="text-right"><?= number_format((float)$row['unit_price'],  0, ',', '.') ?></td>
            <td class="text-right"><?= number_format((float)$row['line_total'],  0, ',', '.') ?></td>
            <td><?= htmlspecialchars($row['warehouse_name'] ?? '—') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="7" class="text-right">TOTAL RECEIVED VALUE</td>
            <td class="text-right">Rp <?= number_format($grand_total, 0, ',', '.') ?></td>
            <td></td>
        </tr>
    </tfoot>
</table>

<?php if ($grn['notes']): ?>
<div style="margin-bottom:16px;"><strong>Notes:</strong> <?= htmlspecialchars($grn['notes']) ?></div>
<?php endif; ?>

<!-- Signatures -->
<div class="sig-area">
    <div class="sig-box">
        <div class="sig-line">Prepared By<br><?= htmlspecialchars($grn['created_by'] ?? 'admin') ?></div>
    </div>
    <div class="sig-box">
        <div class="sig-line">Received By<br><?= htmlspecialchars($grn['received_by'] ?: '___________') ?></div>
    </div>
    <div class="sig-box">
        <div class="sig-line">Approved By<br>___________</div>
    </div>
</div>

<div style="margin-top:24px;font-size:10px;color:#aaa;text-align:center;border-top:1px solid #eee;padding-top:8px;">
    Generated by erpAgro · <?= date('d/m/Y H:i') ?>
</div>

</body>
</html>
