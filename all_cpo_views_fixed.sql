-- ALL CPO VIEWS - Fixed for Production (No DEFINER)
-- Import this single file to create all required views

-- ============================================
-- 1. vw_tank_stock_summary
-- ============================================
DROP VIEW IF EXISTS `vw_tank_stock_summary`;

CREATE VIEW `vw_tank_stock_summary` AS 
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
    ), 0) AS current_stock_kg, 
    ROUND(
        COALESCE(SUM(
            CASE 
                WHEN st.transaction_type = 'in' THEN st.quantity_kg 
                WHEN st.transaction_type = 'out' THEN -st.quantity_kg 
                WHEN st.transaction_type = 'adjustment' THEN st.quantity_kg 
                WHEN st.transaction_type = 'transfer' THEN st.quantity_kg 
                ELSE 0 
            END
        ), 0) / t.capacity_kg * 100, 
        2
    ) AS utilization_percentage, 
    COUNT(st.transaction_id) AS total_transactions, 
    MAX(st.transaction_date) AS last_transaction_date 
FROM 
    storage_tanks t 
    LEFT JOIN cpo_stock_transactions st ON t.tank_id = st.storage_tank_id 
GROUP BY 
    t.tank_id, 
    t.tank_code, 
    t.tank_name, 
    t.tank_type, 
    t.capacity_kg, 
    t.location, 
    t.status;

-- ============================================
-- 2. vw_stock_aging
-- ============================================
DROP VIEW IF EXISTS `vw_stock_aging`;

CREATE VIEW `vw_stock_aging` AS 
SELECT 
    t.tank_id, 
    t.tank_code, 
    t.tank_name, 
    s.current_stock_kg, 
    s.last_transaction_date, 
    DATEDIFF(CURDATE(), s.last_transaction_date) AS days_since_last_transaction, 
    CASE 
        WHEN DATEDIFF(CURDATE(), s.last_transaction_date) > 30 THEN 'Old Stock' 
        WHEN DATEDIFF(CURDATE(), s.last_transaction_date) > 14 THEN 'Aging' 
        ELSE 'Fresh' 
    END AS stock_age_category 
FROM 
    storage_tanks t 
    INNER JOIN vw_tank_stock_summary s ON t.tank_id = s.tank_id 
WHERE 
    s.current_stock_kg > 0 
ORDER BY 
    DATEDIFF(CURDATE(), s.last_transaction_date) DESC;

-- ============================================
-- 3. vw_tank_utilization_alerts
-- ============================================
DROP VIEW IF EXISTS `vw_tank_utilization_alerts`;

CREATE VIEW `vw_tank_utilization_alerts` AS 
SELECT 
    tank_id,
    tank_code,
    tank_name,
    capacity_kg,
    current_stock_kg,
    utilization_percentage,
    CASE 
        WHEN utilization_percentage >= 95 THEN 'CRITICAL - Nearly Full'
        WHEN utilization_percentage >= 90 THEN 'HIGH - Almost Full'
        WHEN utilization_percentage >= 75 THEN 'MEDIUM - Good Level'
        WHEN utilization_percentage >= 25 THEN 'NORMAL'
        WHEN utilization_percentage >= 10 THEN 'LOW - Need Refill'
        ELSE 'CRITICAL - Very Low'
    END AS alert_level,
    CASE 
        WHEN utilization_percentage >= 95 THEN 'Urgent: Tank almost full, plan dispatch'
        WHEN utilization_percentage >= 90 THEN 'Warning: High utilization'
        WHEN utilization_percentage <= 10 THEN 'Urgent: Very low stock'
        WHEN utilization_percentage <= 25 THEN 'Warning: Low stock level'
        ELSE 'Normal operation'
    END AS alert_message
FROM 
    vw_tank_stock_summary
WHERE 
    status = 'active';

-- ============================================
-- Verification Queries (Run after import)
-- ============================================

-- Check if all views were created:
-- SHOW TABLES LIKE 'vw_%';

-- Test each view:
-- SELECT * FROM vw_tank_stock_summary LIMIT 5;
-- SELECT * FROM vw_stock_aging LIMIT 5;
-- SELECT * FROM vw_tank_utilization_alerts LIMIT 5;

-- ============================================
-- DONE!
-- All CPO views created without DEFINER
-- ============================================

-- Made with Bob
