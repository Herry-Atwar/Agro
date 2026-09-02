<?php
/**
 * Setup CPO Inventory System - Create tables and views
 * This script creates all required tables and views for CPO inventory
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "Setting up CPO Inventory System...\n\n";
    
    // Step 1: Create storage_tanks table
    echo "Step 1: Creating storage_tanks table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS storage_tanks (
            tank_id INT AUTO_INCREMENT PRIMARY KEY,
            tank_code VARCHAR(50) NOT NULL UNIQUE,
            tank_name VARCHAR(100) NOT NULL,
            tank_type ENUM('vertical', 'horizontal', 'underground') DEFAULT 'vertical',
            capacity_kg DECIMAL(12,2) NOT NULL,
            location VARCHAR(200),
            status ENUM('active', 'maintenance', 'inactive') DEFAULT 'active',
            remarks TEXT,
            
            -- Audit fields
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by VARCHAR(50),
            updated_by VARCHAR(50),
            
            -- Indexes
            INDEX idx_tank_code (tank_code),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ storage_tanks table created\n\n";
    
    // Step 2: Create cpo_stock_transactions table
    echo "Step 2: Creating cpo_stock_transactions table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS cpo_stock_transactions (
            transaction_id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_date DATE NOT NULL,
            transaction_time TIME NOT NULL,
            transaction_type ENUM('in', 'out', 'adjustment', 'transfer') NOT NULL,
            storage_tank_id INT NOT NULL,
            production_id INT NULL COMMENT 'Link to mill_production for stock in',
            quantity_kg DECIMAL(12,2) NOT NULL,
            reference_no VARCHAR(100),
            remarks TEXT,
            
            -- Audit fields
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by VARCHAR(50),
            updated_by VARCHAR(50),
            
            -- Foreign keys
            FOREIGN KEY (storage_tank_id) REFERENCES storage_tanks(tank_id) ON DELETE RESTRICT,
            
            -- Indexes
            INDEX idx_transaction_date (transaction_date),
            INDEX idx_transaction_type (transaction_type),
            INDEX idx_storage_tank (storage_tank_id),
            INDEX idx_production (production_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ cpo_stock_transactions table created\n\n";
    
    // Step 3: Insert sample storage tanks if table is empty
    echo "Step 3: Checking for sample data...\n";
    $count = $db->query("SELECT COUNT(*) FROM storage_tanks")->fetchColumn();
    if ($count == 0) {
        echo "Inserting sample storage tanks...\n";
        $db->exec("
            INSERT INTO storage_tanks (tank_code, tank_name, tank_type, capacity_kg, location, status, created_by) VALUES
            ('TANK-001', 'Storage Tank 1', 'vertical', 50000.00, 'Mill Area A', 'active', 'system'),
            ('TANK-002', 'Storage Tank 2', 'vertical', 50000.00, 'Mill Area A', 'active', 'system'),
            ('TANK-003', 'Storage Tank 3', 'horizontal', 30000.00, 'Mill Area B', 'active', 'system'),
            ('TANK-004', 'Storage Tank 4', 'vertical', 75000.00, 'Mill Area B', 'active', 'system')
        ");
        echo "✓ Sample storage tanks inserted\n\n";
    } else {
        echo "✓ Storage tanks already exist ($count tanks)\n\n";
    }
    
    // Step 4: Create views
    echo "Step 4: Creating views...\n\n";
    
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
    echo "✓ vw_tank_stock_summary created\n";
    
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
    echo "✓ vw_daily_stock_movements created\n";
    
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
    echo "✓ vw_tank_utilization_alerts created\n";
    
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
    echo "✓ CPO Inventory System Setup Complete!\n";
    echo "========================================\n\n";
    
    echo "Tables created:\n";
    echo "- storage_tanks\n";
    echo "- cpo_stock_transactions\n\n";
    
    echo "Views created:\n";
    echo "- vw_tank_stock_summary\n";
    echo "- vw_daily_stock_movements\n";
    echo "- vw_tank_utilization_alerts\n";
    echo "- vw_stock_aging\n\n";
    
    echo "You can now access inventory_cpo.php\n";
    
} catch (PDOException $e) {
    echo "✗ Database Error: " . $e->getMessage() . "\n";
    echo "\nError Code: " . $e->getCode() . "\n";
    echo "\nSQL State: " . $e->errorInfo[0] . "\n";
    exit(1);
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Powered by IBM Bob
