<?php
/**
 * Fix CPO Tables - Handles existing tables properly
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "<h2>Fixing CPO Stock Tables</h2>";
    echo "<pre>";
    
    // Step 1: Check existing storage_tanks structure
    echo "Step 1: Checking existing storage_tanks table...\n";
    try {
        $columns = $db->query("DESCRIBE storage_tanks")->fetchAll(PDO::FETCH_COLUMN);
        echo "Existing columns: " . implode(', ', $columns) . "\n\n";
        
        // Check if it has the right structure
        if (!in_array('tank_code', $columns)) {
            echo "⚠ Table exists but has wrong structure!\n";
            echo "  Dropping and recreating...\n\n";
            
            // Disable foreign key checks
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            // Drop tables
            $db->exec("DROP TABLE IF EXISTS cpo_stock_transactions");
            echo "✓ Dropped cpo_stock_transactions\n";
            
            $db->exec("DROP TABLE IF EXISTS storage_tanks");
            echo "✓ Dropped storage_tanks\n";
            
            // Re-enable foreign key checks
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            echo "✓ Foreign key checks re-enabled\n\n";
        }
    } catch (PDOException $e) {
        echo "Table doesn't exist yet - will create new\n\n";
    }
    
    // Step 2: Create storage_tanks table
    echo "Step 2: Creating storage_tanks table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS storage_tanks (
        tank_id INT AUTO_INCREMENT PRIMARY KEY,
        tank_code VARCHAR(50) NOT NULL UNIQUE,
        tank_name VARCHAR(100) NOT NULL,
        tank_type ENUM('vertical', 'horizontal', 'underground') DEFAULT 'vertical',
        capacity_kg DECIMAL(12,2) NOT NULL,
        location VARCHAR(200),
        status ENUM('active', 'maintenance', 'inactive') DEFAULT 'active',
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by VARCHAR(50),
        updated_by VARCHAR(50),
        INDEX idx_tank_code (tank_code),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ storage_tanks table created\n\n";
    
    // Step 3: Create cpo_stock_transactions table
    echo "Step 3: Creating cpo_stock_transactions table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS cpo_stock_transactions (
        transaction_id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_date DATE NOT NULL,
        transaction_time TIME NOT NULL,
        transaction_type ENUM('in', 'out', 'adjustment', 'transfer') NOT NULL,
        storage_tank_id INT NOT NULL,
        production_id INT NULL,
        quantity_kg DECIMAL(12,2) NOT NULL,
        reference_no VARCHAR(100),
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by VARCHAR(50),
        updated_by VARCHAR(50),
        INDEX idx_transaction_date (transaction_date),
        INDEX idx_transaction_type (transaction_type),
        INDEX idx_tank (storage_tank_id),
        INDEX idx_production (production_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ cpo_stock_transactions table created\n\n";
    
    // Step 4: Insert sample data
    echo "Step 4: Inserting sample data...\n";
    
    // Check if data already exists
    $tank_count = $db->query("SELECT COUNT(*) FROM storage_tanks")->fetchColumn();
    
    if ($tank_count == 0) {
        $db->exec("INSERT INTO storage_tanks (tank_code, tank_name, tank_type, capacity_kg, location, status, created_by) VALUES
        ('TANK-001', 'Main Storage Tank 1', 'vertical', 500000, 'Mill Area A - Section 1', 'active', 'system'),
        ('TANK-002', 'Main Storage Tank 2', 'vertical', 500000, 'Mill Area A - Section 2', 'active', 'system'),
        ('TANK-003', 'Main Storage Tank 3', 'vertical', 500000, 'Mill Area A - Section 3', 'active', 'system'),
        ('TANK-004', 'Reserve Tank 1', 'vertical', 300000, 'Mill Area B - Section 1', 'active', 'system'),
        ('TANK-005', 'Reserve Tank 2', 'vertical', 300000, 'Mill Area B - Section 2', 'active', 'system'),
        ('TANK-006', 'Export Holding Tank', 'horizontal', 200000, 'Export Terminal', 'active', 'system'),
        ('TANK-007', 'Quality Control Tank', 'vertical', 100000, 'QC Laboratory Area', 'active', 'system'),
        ('TANK-008', 'Emergency Tank', 'underground', 150000, 'Underground Storage', 'maintenance', 'system')");
        echo "✓ Inserted 8 storage tanks\n";
        
        $db->exec("INSERT INTO cpo_stock_transactions 
        (transaction_date, transaction_time, transaction_type, storage_tank_id, quantity_kg, reference_no, remarks, created_by)
        VALUES
        (CURDATE() - INTERVAL 30 DAY, '08:00:00', 'in', 1, 45000, 'PROD-001', 'Initial stock from production', 'system'),
        (CURDATE() - INTERVAL 29 DAY, '09:00:00', 'in', 2, 48000, 'PROD-002', 'Stock from production', 'system'),
        (CURDATE() - INTERVAL 28 DAY, '10:00:00', 'in', 3, 42000, 'PROD-003', 'Stock from production', 'system'),
        (CURDATE() - INTERVAL 25 DAY, '14:00:00', 'out', 1, 20000, 'SALE-001', 'Export shipment', 'system'),
        (CURDATE() - INTERVAL 24 DAY, '15:00:00', 'out', 2, 25000, 'SALE-002', 'Domestic sale', 'system'),
        (CURDATE() - INTERVAL 20 DAY, '08:30:00', 'in', 1, 50000, 'PROD-004', 'Stock from production', 'system'),
        (CURDATE() - INTERVAL 18 DAY, '16:00:00', 'out', 3, 30000, 'SALE-003', 'Export shipment', 'system'),
        (CURDATE() - INTERVAL 15 DAY, '09:00:00', 'in', 4, 35000, 'PROD-005', 'Stock from production', 'system'),
        (CURDATE() - INTERVAL 10 DAY, '11:00:00', 'transfer', 1, -15000, 'TRF-001', 'Transfer to TANK-006', 'system'),
        (CURDATE() - INTERVAL 10 DAY, '11:30:00', 'transfer', 6, 15000, 'TRF-001', 'Transfer from TANK-001', 'system'),
        (CURDATE() - INTERVAL 5 DAY, '10:00:00', 'adjustment', 2, 150, 'ADJ-001', 'Stock count adjustment', 'system'),
        (CURDATE() - INTERVAL 2 DAY, '08:00:00', 'in', 5, 40000, 'PROD-006', 'Stock from production', 'system'),
        (CURDATE() - INTERVAL 1 DAY, '14:00:00', 'out', 6, 10000, 'SALE-004', 'Export shipment', 'system')");
        echo "✓ Inserted 13 sample transactions\n\n";
    } else {
        echo "⚠ Data already exists, skipping insert\n\n";
    }
    
    // Step 5: Create views
    echo "Step 5: Creating views...\n";
    
    $db->exec("CREATE OR REPLACE VIEW vw_tank_stock_summary AS
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
    GROUP BY t.tank_id, t.tank_code, t.tank_name, t.tank_type, t.capacity_kg, t.location, t.status");
    echo "✓ Created vw_tank_stock_summary\n";
    
    $db->exec("CREATE OR REPLACE VIEW vw_tank_utilization_alerts AS
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
    ORDER BY utilization_percentage DESC");
    echo "✓ Created vw_tank_utilization_alerts\n";
    
    $db->exec("CREATE OR REPLACE VIEW vw_stock_aging AS
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
    ORDER BY days_in_storage DESC");
    echo "✓ Created vw_stock_aging\n\n";
    
    // Step 6: Verification
    echo "Step 6: Verification...\n";
    $tank_count = $db->query("SELECT COUNT(*) FROM storage_tanks")->fetchColumn();
    echo "✓ Storage tanks: $tank_count records\n";
    
    $trans_count = $db->query("SELECT COUNT(*) FROM cpo_stock_transactions")->fetchColumn();
    echo "✓ Transactions: $trans_count records\n";
    
    $views = $db->query("SHOW TABLES LIKE 'vw_%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "✓ Views: " . count($views) . " created\n";
    foreach ($views as $view) {
        echo "  - $view\n";
    }
    
    // Test the main view
    echo "\nTesting vw_tank_stock_summary:\n";
    $summary = $db->query("SELECT tank_code, current_stock_kg, utilization_percentage FROM vw_tank_stock_summary LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($summary as $row) {
        echo "  {$row['tank_code']}: " . number_format($row['current_stock_kg']) . " kg ({$row['utilization_percentage']}%)\n";
    }
    
    echo "\n========================================\n";
    echo "✓ SUCCESS! All CPO tables and views are ready!\n";
    echo "You can now access inventory_cpo.php\n";
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
