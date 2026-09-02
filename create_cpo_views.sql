-- Create CPO Stock Views
-- This file creates only the views needed for inventory_cpo.php

USE agro;

-- Create view for current stock by tank
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
GROUP BY t.tank_id, t.tank_code, t.tank_name, t.tank_type, t.capacity_kg, t.location, t.status;

-- Create view for tank utilization alerts
CREATE OR REPLACE VIEW vw_tank_utilization_alerts AS
SELECT 
    tank_id,
    tank_code,
    tank_name,
    capacity_kg,
    current_stock_kg,
    utilization_percentage,
    CASE 
        WHEN utilization_percentage >= 95 THEN 'CRITICAL - Nearly Full'
        WHEN utilization_percentage >= 85 THEN 'WARNING - High Utilization'
        WHEN utilization_percentage <= 10 THEN 'LOW - Nearly Empty'
        WHEN utilization_percentage <= 20 THEN 'NOTICE - Low Stock'
        ELSE 'NORMAL'
    END as alert_level,
    CASE 
        WHEN utilization_percentage >= 95 THEN 'danger'
        WHEN utilization_percentage >= 85 THEN 'warning'
        WHEN utilization_percentage <= 10 THEN 'danger'
        WHEN utilization_percentage <= 20 THEN 'info'
        ELSE 'success'
    END as alert_color
FROM vw_tank_stock_summary
WHERE status = 'active'
ORDER BY utilization_percentage DESC;

-- Create view for stock aging analysis
CREATE OR REPLACE VIEW vw_stock_aging AS
SELECT 
    t.tank_id,
    t.tank_code,
    t.tank_name,
    s.current_stock_kg,
    MIN(st.transaction_date) as oldest_stock_date,
    DATEDIFF(CURDATE(), MIN(st.transaction_date)) as days_in_storage,
    CASE 
        WHEN DATEDIFF(CURDATE(), MIN(st.transaction_date)) > 60 THEN 'OLD - Over 60 days'
        WHEN DATEDIFF(CURDATE(), MIN(st.transaction_date)) > 30 THEN 'AGING - 30-60 days'
        WHEN DATEDIFF(CURDATE(), MIN(st.transaction_date)) > 14 THEN 'MODERATE - 14-30 days'
        ELSE 'FRESH - Under 14 days'
    END as aging_status
FROM storage_tanks t
INNER JOIN vw_tank_stock_summary s ON t.tank_id = s.tank_id
LEFT JOIN cpo_stock_transactions st ON t.tank_id = st.storage_tank_id 
    AND st.transaction_type = 'in'
WHERE s.current_stock_kg > 0
GROUP BY t.tank_id, t.tank_code, t.tank_name, s.current_stock_kg
ORDER BY days_in_storage DESC;

SELECT 'CPO Views created successfully!' as Status;

-- Verify views exist
SHOW TABLES LIKE 'vw_%';

-- Made with Bob
