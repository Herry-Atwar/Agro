<?php
/**
 * Create CPO Views Only - Create missing vw_tank_stock_summary view
 * This script only creates views without dropping/recreating tables
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "Creating CPO inventory views...\n\n";
    
    // Create view for current stock by tank
    echo "Creating vw_tank_stock_summary...\n";
    $db->exec("
        CREATE OR REPLACE VIEW vw_tank_stock_summary AS
        SELECT 
            t.tank_id,
            t.tank_code,
            t.tank_name,
            t.tank_type,
            t.capacity_kg,
            t.location,
            t.status,
            COALESCE(SUM(
                CASE 
                    WHEN st.transaction_type = 'in' THEN st.quantity_kg
                    WHEN st.transaction_type = 'out' THEN -st.quantity_kg
                    WHEN st.transaction_type = 'adjustment' THEN st.quantity_kg
                    WHEN st.transaction_type = 'transfer' THEN st.quantity_kg
                    ELSE 0
                END
            ), 0) as current_stock_kg,
            ROUND(COALESCE(SUM(
                CASE 
                    WHEN st.transaction_type = 'in' THEN st.quantity_kg
                    WHEN st.transaction_type = 'out' THEN -st.quantity_kg
                    WHEN st.transaction_type = 'adjustment' THEN st.quantity_kg
                    WHEN st.transaction_type = 'transfer' THEN st.quantity_kg
                    ELSE 0
                END
            ), 0) / t.capacity_kg * 100, 2) as utilization_percentage,
            COUNT(st.transaction_id) as total_transactions,
            MAX(st.transaction_date) as last_transaction_date
        FROM storage_tanks t
        LEFT JOIN cpo_stock_transactions st ON t.tank_id = st.storage_tank_id
        GROUP BY t.tank_id, t.tank_code, t.tank_name, t.tank_type, t.capacity_kg, t.location, t.status
    ");
    echo "✓ vw_tank_stock_summary created\n\n";
    
    // Create view for stock movements by date
    echo "Creating vw_daily_stock_movements...\n";
    $db->exec("
        CREATE OR REPLACE VIEW vw_daily_stock_movements AS
        SELECT 
            DATE(transaction_date) as movement_date,
            SUM(CASE WHEN transaction_type = 'in' THEN quantity_kg ELSE 0 END) as total_in,
            SUM(CASE WHEN transaction_type = 'out' THEN quantity_kg ELSE 0 END) as total_out,
            SUM(CASE WHEN transaction_type = 'adjustment' THEN quantity_kg ELSE 0 END) as total_adjustment,
            COUNT(*) as transaction_count
        FROM cpo_stock_transactions
        GROUP BY DATE(transaction_date)
        ORDER BY movement_date DESC
    ");
    echo "✓ vw_daily_stock_movements created\n\n";
    
    // Create view for tank utilization alerts
    echo "Creating vw_tank_utilization_alerts...\n";
    $db->exec("
        CREATE OR REPLACE VIEW vw_tank_utilization_alerts AS
        SELECT 
            t.tank_id,
            t.tank_code,
            t.tank_name,
            t.capacity_kg,
            s.current_stock_kg,
            s.utilization_percentage,
            CASE 
                WHEN s.utilization_percentage >= 95 THEN 'Critical - Near Full'
                WHEN s.utilization_percentage >= 85 THEN 'Warning - High'
                WHEN s.utilization_percentage <= 10 THEN 'Warning - Low'
                WHEN s.utilization_percentage <= 5 THEN 'Critical - Near Empty'
                ELSE 'Normal'
            END as alert_level,
            t.capacity_kg - s.current_stock_kg as available_capacity_kg
        FROM storage_tanks t
        LEFT JOIN vw_tank_stock_summary s ON t.tank_id = s.tank_id
        WHERE t.status = 'active'
        HAVING alert_level != 'Normal'
        ORDER BY 
            CASE alert_level
                WHEN 'Critical - Near Full' THEN 1
                WHEN 'Critical - Near Empty' THEN 2
                WHEN 'Warning - High' THEN 3
                WHEN 'Warning - Low' THEN 4
                ELSE 5
            END
    ");
    echo "✓ vw_tank_utilization_alerts created\n\n";
    
    // Create view for stock aging
    echo "Creating vw_stock_aging...\n";
    $db->exec("
        CREATE OR REPLACE VIEW vw_stock_aging AS
        SELECT 
            t.tank_id,
            t.tank_code,
            t.tank_name,
            s.current_stock_kg,
            s.last_transaction_date,
            DATEDIFF(CURDATE(), s.last_transaction_date) as days_since_last_transaction,
            CASE 
                WHEN DATEDIFF(CURDATE(), s.last_transaction_date) > 30 THEN 'Old Stock'
                WHEN DATEDIFF(CURDATE(), s.last_transaction_date) > 14 THEN 'Aging'
                ELSE 'Fresh'
            END as stock_age_category
        FROM storage_tanks t
        INNER JOIN vw_tank_stock_summary s ON t.tank_id = s.tank_id
        WHERE s.current_stock_kg > 0
        ORDER BY days_since_last_transaction DESC
    ");
    echo "✓ vw_stock_aging created\n\n";
    
    echo "========================================\n";
    echo "✓ All CPO inventory views created successfully!\n";
    echo "========================================\n\n";
    echo "Views created:\n";
    echo "- vw_tank_stock_summary\n";
    echo "- vw_daily_stock_movements\n";
    echo "- vw_tank_utilization_alerts\n";
    echo "- vw_stock_aging\n\n";
    echo "You can now access inventory_cpo.php\n";
    
} catch (PDOException $e) {
    echo "✗ Database Error: " . $e->getMessage() . "\n";
    echo "\nError Code: " . $e->getCode() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Powered by IBM Bob
