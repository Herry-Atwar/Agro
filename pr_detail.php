<?php
/**
 * ajax/pr_detail.php
 * Returns PR header + items as JSON for the Edit PR modal.
 * Only accessible for draft / submitted PRs.
 */
ob_start();                        // buffer everything — catches any stray warnings/notices
ini_set('display_errors', '0');    // never mix PHP errors into JSON response

require_once '../config/database.php';
require_once '../includes/functions.php';

ob_clean();                        // discard any output from includes (warnings, BOM, etc.)
header('Content-Type: application/json');

$db    = getDB();
$pr_id = (int)(get('pr_id') ?: 0);

if (!$pr_id) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

try {
    // Header
    $hdr = $db->prepare("
        SELECT pr_id, pr_number, pr_date, division_id, requested_by, status, notes
        FROM purchase_requisitions
        WHERE pr_id = ? AND status IN ('draft','submitted')
    ");
    $hdr->execute([$pr_id]);
    $pr = $hdr->fetch(PDO::FETCH_ASSOC);

    if (!$pr) {
        echo json_encode(['error' => 'PR not found or not editable']);
        exit;
    }

    // Items (exclude cancelled)
    $itm = $db->prepare("
        SELECT pri.pr_item_id, pri.material_id, pri.required_qty,
               pri.approved_qty, pri.unit, pri.estimated_unit_price,
               pri.estimated_total, pri.status
        FROM pr_items pri
        WHERE pri.pr_id = ? AND pri.status != 'cancelled'
        ORDER BY pri.pr_item_id
    ");
    $itm->execute([$pr_id]);
    $pr['items'] = $itm->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($pr);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
