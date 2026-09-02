<?php
/**
 * Fix Kernel Storage Tables
 * Creates kernel_storage, kernel_stock_transactions tables and views
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "<h2>Creating Kernel Storage System</h2>";
    echo "<pre>";
    
    // Disable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "✓ Disabled foreign key checks\n\n";
    
    // Drop existing tables
    echo "Step 1: Dropping existing tables (if any)...\n";
    $db->exec("DROP TABLE IF EXISTS kernel_stock_transactions");
    echo "✓ Dropped kernel_stock_transactions\n";
    
    $db->exec("DROP TABLE IF EXISTS kernel_storage");
    echo "✓ Dropped kernel_storage\n\n";
    
    // Re-enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Create kernel_storage table
    echo "Step 2: Creating kernel_storage table...\n";
    $db->exec("CREATE TABLE kernel_storage (
        storage_id INT AUTO_INCREMENT PRIMARY KEY,
        storage_code VARCHAR(50) NOT NULL UNIQUE,
        storage_name VARCHAR(100) NOT NULL,
        storage_type ENUM('silo', 'warehouse', 'shed', 'container') DEFAULT 'warehouse',
        capacity_kg DECIMAL(12,2) NOT NULL,
        location VARCHAR(200),
        status ENUM('active', 'maintenance', 'inactive') DEFAULT 'active',
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by VARCHAR(50),
        updated_by VARCHAR(50),
        INDEX idx_storage_code (storage_code),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ kernel_storage table created\n\n";
    
    // Create kernel_stock_transactions table
    echo "Step 3: Creating kernel_stock_transactions table...\n";
    $db->exec("CREATE TABLE kernel_stock_transactions (
        transaction_id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_date DATE NOT NULL,
        transaction_time TIME NOT NULL,
        transaction_type ENUM('in', 'out', 'adjustment', 'transfer') NOT NULL,
        storage_id INT NOT NULL,
        production_id INT NULL COMMENT 'Link to mill_production',
        quantity_kg DECIMAL(12,2) NOT NULL,
        reference_no VARCHAR(100),
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by VARCHAR(50),
        updated_by VARCHAR(50),
        INDEX idx_transaction_date (transaction_date),
        INDEX idx_transaction_type (transaction_type),
        INDEX idx_storage (storage_id),
        INDEX idx_production (production_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ kernel_stock_transactions table created\n\n";
    
    // Insert sample data
    echo "Step 4: Inserting sample data...\n";
    
    $db->exec("INSERT INTO kernel_storage (storage_code, storage_name, storage_type, capacity_kg, location, status, created_by) VALUES
    ('KRN-SILO-01', 'Kernel Silo 1', 'silo', 300000, 'Mill Area - Kernel Section A', 'active', 'system'),
    ('KRN-SILO-02', 'Kernel Silo 2', 'silo', 300000, 'Mill Area - Kernel Section B', 'active', 'system'),
    ('KRN-WH-01', 'Kernel Warehouse 1', 'warehouse', 200000, 'Storage Area - Building 1', 'active', 'system'),
    ('KRN-WH-02', 'Kernel Warehouse 2', 'warehouse', 200000, 'Storage Area - Building 2', 'active', 'system'),
    ('KRN-SHED-01', 'Kernel Shed 1', 'shed', 150000, 'Open Storage Area', 'active', 'system'),
    ('KRN-CONT-01', 'Export Container Storage', 'container', 100000, 'Export Terminal', 'active', 'system')");
    echo "✓ Inserted 6 kernel storage locations\n";
    
    $db->exec("INSERT INTO kernel_stock_transactions 
    (transaction_date, transaction_time, transaction_type, storage_id, quantity_kg, reference_no, remarks, created_by)
    VALUES
    (CURDATE() - INTERVAL 30 DAY, '08:00:00', 'in', 1, 25000, 'KPROD-001', 'Kernel from production', 'system'),
    (CURDATE() - INTERVAL 29 DAY, '09:00:00', 'in', 2, 28000, 'KPROD-002', 'Kernel from production', 'system'),
    (CURDATE() - INTERVAL 28 DAY, '10:00:00', 'in', 3, 22000, 'KPROD-003', 'Kernel from production', 'system'),
    (CURDATE() - INTERVAL 25 DAY, '14:00:00', 'out', 1, 15000, 'KSALE-001', 'Kernel export', 'system'),
    (CURDATE() - INTERVAL 24 DAY, '15:00:00', 'out', 2, 18000, 'KSALE-002', 'Kernel domestic sale', 'system'),
    (CURDATE() - INTERVAL 20 DAY, '08:30:00', 'in', 1, 30000, 'KPROD-004', 'Kernel from production', 'system'),
    (CURDATE() - INTERVAL 18 DAY, '16:00:00', 'out', 3, 20000, 'KSALE-003', 'Kernel export', 'system'),
    (CURDATE() - INTERVAL 15 DAY, '09:00:00', 'in', 4, 26000, 'KPROD-005', 'Kernel from production', 'system'),
    (CURDATE() - INTERVAL 10 DAY, '11:00:00', 'transfer', 1, -10000, 'KTRF-001', 'Transfer to container', 'system'),
    (CURDATE() - INTERVAL 10 DAY, '11:30:00', 'transfer', 6, 10000, 'KTRF-001', 'Transfer from silo', 'system'),
    (CURDATE() - INTERVAL 5 DAY, '10:00:00', 'adjustment', 2, 100, 'KADJ-001', 'Stock count adjustment', 'system'),
    (CURDATE() - INTERVAL 2 DAY, '08:00:00', 'in', 5, 24000, 'KPROD-006', 'Kernel from production', 'system'),
    (CURDATE() - INTERVAL 1 DAY, '14:00:00', 'out', 6, 8000, 'KSALE-004', 'Kernel export', 'system')");
    echo "✓ Inserted 13 sample transactions\n\n";
    
    // Create views
    echo "Step 5: Creating views...\n";
    
    $db->exec("CREATE OR REPLACE VIEW vw_kernel_stock_summary AS
    SELECT 
        s.storage_id,
        s.storage_code,
        s.storage_name,
        s.storage_type,
        s.capacity_kg,
        s.location,
        s.status,
        COALESCE(SUM(
            CASE 
                WHEN t.transaction_type = 'in' THEN t.quantity_kg
                WHEN t.transaction_type = 'out' THEN -t.quantity_kg
                WHEN t.transaction_type = 'adjustment' THEN t.quantity_kg
                WHEN t.transaction_type = 'transfer' THEN t.quantity_kg
                ELSE 0
            END
        ), 0) as current_stock_kg,
        ROUND(COALESCE(SUM(
            CASE 
                WHEN t.transaction_type = 'in' THEN t.quantity_kg
                WHEN t.transaction_type = 'out' THEN -t.quantity_kg
                WHEN t.transaction_type = 'adjustment' THEN t.quantity_kg
                WHEN t.transaction_type = 'transfer' THEN t.quantity_kg
                ELSE 0
            END
        ), 0) / s.capacity_kg * 100, 2) as utilization_percentage,
        COUNT(t.transaction_id) as total_transactions,
        MAX(t.transaction_date) as last_transaction_date
    FROM kernel_storage s
    LEFT JOIN kernel_stock_transactions t ON s.storage_id = t.storage_id
    GROUP BY s.storage_id, s.storage_code, s.storage_name, s.storage_type, s.capacity_kg, s.location, s.status");
    echo "✓ Created vw_kernel_stock_summary\n";
    
    $db->exec("CREATE OR REPLACE VIEW vw_kernel_utilization_alerts AS
    SELECT
        storage_id,
        storage_code,
        storage_name,
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
    FROM vw_kernel_stock_summary
    WHERE status = 'active'
    ORDER BY utilization_percentage DESC");
    echo "✓ Created vw_kernel_utilization_alerts\n";
    
    // Create alias view for compatibility
    $db->exec("CREATE OR REPLACE VIEW vw_kernel_storage_alerts AS
    SELECT * FROM vw_kernel_utilization_alerts");
    echo "✓ Created vw_kernel_storage_alerts (alias)\n";
    
    $db->exec("CREATE OR REPLACE VIEW vw_kernel_stock_aging AS
    SELECT 
        s.storage_id,
        s.storage_code,
        s.storage_name,
        st.current_stock_kg,
        MIN(t.transaction_date) as oldest_stock_date,
        DATEDIFF(CURDATE(), MIN(t.transaction_date)) as days_in_storage,
        CASE 
            WHEN DATEDIFF(CURDATE(), MIN(t.transaction_date)) > 60 THEN 'OLD - Over 60 days'
            WHEN DATEDIFF(CURDATE(), MIN(t.transaction_date)) > 30 THEN 'AGING - 30-60 days'
            WHEN DATEDIFF(CURDATE(), MIN(t.transaction_date)) > 14 THEN 'MODERATE - 14-30 days'
            ELSE 'FRESH - Under 14 days'
        END as aging_status
    FROM kernel_storage s
    INNER JOIN vw_kernel_stock_summary st ON s.storage_id = st.storage_id
    LEFT JOIN kernel_stock_transactions t ON s.storage_id = t.storage_id 
        AND t.transaction_type = 'in'
    WHERE st.current_stock_kg > 0
    GROUP BY s.storage_id, s.storage_code, s.storage_name, st.current_stock_kg
    ORDER BY days_in_storage DESC");
    echo "✓ Created vw_kernel_stock_aging\n\n";
    
    // Verification
    echo "Step 6: Verification...\n";
    $storage_count = $db->query("SELECT COUNT(*) FROM kernel_storage")->fetchColumn();
    echo "✓ Kernel storage locations: $storage_count records\n";
    
    $trans_count = $db->query("SELECT COUNT(*) FROM kernel_stock_transactions")->fetchColumn();
    echo "✓ Transactions: $trans_count records\n";
    
    $views = $db->query("SHOW TABLES LIKE 'vw_kernel%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "✓ Views: " . count($views) . " created\n";
    foreach ($views as $view) {
        echo "  - $view\n";
    }
    
    // Test the main view
    echo "\nTesting vw_kernel_stock_summary:\n";
    $summary = $db->query("SELECT storage_code, current_stock_kg, utilization_percentage FROM vw_kernel_stock_summary LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($summary as $row) {
        echo "  {$row['storage_code']}: " . number_format($row['current_stock_kg']) . " kg ({$row['utilization_percentage']}%)\n";
    }
    
    echo "\n========================================\n";
    echo "✓ SUCCESS! Kernel storage system is ready!\n";
    echo "You can now access inventory_kernel.php\n";
    echo "========================================\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre>";
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString();
    echo "</pre>";
}
?>

// Powered by IBM Bob
