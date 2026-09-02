<?php
/**
 * AJAX endpoint: cascade filters for journal entry form
 * Returns JSON for business_units, divisions, blocks, or activities.
 * NOTE: intentionally does NOT include functions.php — session_start() there
 *       would send headers before our Content-Type and corrupt the JSON response.
 */
if (ob_get_level()) ob_end_clean();
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db   = getDB();
    $type = $_GET['type'] ?? '';

    switch ($type) {
        case 'bu':
            $company_id = intval($_GET['company_id'] ?? 0);
            if (!$company_id) { echo '[]'; exit; }
            $stmt = $db->prepare("
                SELECT business_unit_id AS id, unit_name AS name
                FROM   business_units
                WHERE  company_id = ? AND status = 'Active'
                ORDER BY unit_name
            ");
            $stmt->execute([$company_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'division':
            $bu_id = intval($_GET['business_unit_id'] ?? 0);
            if (!$bu_id) { echo '[]'; exit; }
            $stmt = $db->prepare("
                SELECT division_id AS id, division_name AS name
                FROM   divisions
                WHERE  business_unit_id = ? AND status = 'Active'
                ORDER BY division_name
            ");
            $stmt->execute([$bu_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'block':
            $div_id = intval($_GET['division_id'] ?? 0);
            if (!$div_id) { echo '[]'; exit; }
            $stmt = $db->prepare("
                SELECT block_id AS id,
                       CONCAT(block_code, ' — ', COALESCE(block_name,'')) AS name
                FROM   blocks
                WHERE  division_id = ?
                ORDER BY block_code
            ");
            $stmt->execute([$div_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'activity':
            // Optional: filter by business_unit_id context (just return all active for now)
            $stmt = $db->query("
                SELECT id, CONCAT(activity_code, ' — ', activity_name) AS name
                FROM   activities
                WHERE  is_active = 1
                ORDER BY activity_code
            ");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        default:
            echo json_encode([]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
