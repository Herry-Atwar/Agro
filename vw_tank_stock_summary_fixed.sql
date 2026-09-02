-- Fixed version of vw_tank_stock_summary view
-- Removed DEFINER clause to work with shared hosting

-- Drop view if exists
DROP VIEW IF EXISTS `vw_tank_stock_summary`;

-- Create view without DEFINER
CREATE VIEW `vw_tank_stock_summary` AS 
SELECT 
    `t`.`tank_id` AS `tank_id`, 
    `t`.`tank_code` AS `tank_code`, 
    `t`.`tank_name` AS `tank_name`, 
    `t`.`tank_type` AS `tank_type`, 
    `t`.`capacity_kg` AS `capacity_kg`, 
    `t`.`location` AS `location`, 
    `t`.`status` AS `status`, 
    COALESCE(SUM(
        CASE 
            WHEN `st`.`transaction_type` = 'in' THEN `st`.`quantity_kg` 
            WHEN `st`.`transaction_type` = 'out' THEN -`st`.`quantity_kg` 
            WHEN `st`.`transaction_type` = 'adjustment' THEN `st`.`quantity_kg` 
            WHEN `st`.`transaction_type` = 'transfer' THEN `st`.`quantity_kg` 
            ELSE 0 
        END
    ), 0) AS `current_stock_kg`, 
    ROUND(
        COALESCE(SUM(
            CASE 
                WHEN `st`.`transaction_type` = 'in' THEN `st`.`quantity_kg` 
                WHEN `st`.`transaction_type` = 'out' THEN -`st`.`quantity_kg` 
                WHEN `st`.`transaction_type` = 'adjustment' THEN `st`.`quantity_kg` 
                WHEN `st`.`transaction_type` = 'transfer' THEN `st`.`quantity_kg` 
                ELSE 0 
            END
        ), 0) / `t`.`capacity_kg` * 100, 
        2
    ) AS `utilization_percentage`, 
    COUNT(`st`.`transaction_id`) AS `total_transactions`, 
    MAX(`st`.`transaction_date`) AS `last_transaction_date` 
FROM 
    `storage_tanks` `t` 
    LEFT JOIN `cpo_stock_transactions` `st` ON `t`.`tank_id` = `st`.`storage_tank_id` 
GROUP BY 
    `t`.`tank_id`, 
    `t`.`tank_code`, 
    `t`.`tank_name`, 
    `t`.`tank_type`, 
    `t`.`capacity_kg`, 
    `t`.`location`, 
    `t`.`status`;

-- Made with Bob
