<?php
// Prevent any output before JSON
ob_start();

require_once 'config/database.php';

// Clear any previous output
ob_end_clean();

// Set JSON header
header('Content-Type: application/json');

$plan_id = $_GET['plan_id'] ?? '';

if (empty($plan_id)) {
    echo json_encode(['success' => false, 'message' => 'Plan ID is required']);
    exit;
}

try {
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT
            abp.plan_id,
            abp.budget_year,
            abp.frequency_type,
            abp.executions_per_year,
            abp.start_month,
            abp.status,
            b.block_code,
            b.block_name,
            a.activity_name
        FROM activity_budget_plans abp
        INNER JOIN blocks b ON abp.block_id = b.block_id
        INNER JOIN activities a ON abp.activity_id = a.id
        WHERE abp.plan_id = ?
    ");
    
    $stmt->execute([$plan_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($plan) {
        echo json_encode(['success' => true, 'plan' => $plan]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Plan not found']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;

// Powered by IBM Bob
