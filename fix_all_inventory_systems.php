<?php
/**
 * Fix ALL Inventory Systems
 * Creates CPO, Kernel, and Materials inventory tables and views
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "<h2>Creating ALL Inventory Systems</h2>";
    echo "<pre>";
    
    // Disable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "✓ Disabled foreign key checks\n\n";
    
    // ============================================
    // 1. CPO INVENTORY SYSTEM
    // ============================================
    echo "========================================\n";
    echo "1. CPO INVENTORY SYSTEM\n";
    echo "========================================\n\n";
    
    $db->exec("DROP TABLE IF EXISTS cpo_stock_transactions");
    $db->exec("DROP TABLE IF EXISTS storage_tanks");
    echo "✓ Dropped existing CPO tables\n";
    
    $db->exec("CREATE TABLE storage_tanks (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $db->exec("CREATE TABLE cpo_stock_transactions (
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
        INDEX idx_tank (storage_tank_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created CPO tables\n";
    
    $db->exec("INSERT INTO storage_tanks (tank_code, tank_name, tank_type, capacity_kg, location, status, created_by) VALUES
    ('TANK-001', 'Main Storage Tank 1', 'vertical', 500000, 'Mill Area A', 'active', 'system'),
    ('TANK-002', 'Main Storage Tank 2', 'vertical', 500000, 'Mill Area A', 'active', 'system'),
    ('TANK-003', 'Reserve Tank', 'vertical', 300000, 'Mill Area B', 'active', 'system')");
    
    $db->exec("INSERT INTO cpo_stock_transactions (transaction_date, transaction_time, transaction_type, storage_tank_id, quantity_kg, reference_no, created_by) VALUES
    (CURDATE() - INTERVAL 10 DAY, '08:00:00', 'in', 1, 45000, 'PROD-001', 'system'),
    (CURDATE() - INTERVAL 5 DAY, '14:00:00', 'out', 1, 20000, 'SALE-001', 'system'),
    (CURDATE() - INTERVAL 2 DAY, '08:00:00', 'in', 2, 50000, 'PROD-002', 'system')");
    echo "✓ Inserted CPO sample data\n";
    
    $db->exec("CREATE OR REPLACE VIEW vw_tank_stock_summary AS
    SELECT t.tank_id, t.tank_code, t.tank_name, t.tank_type, t.capacity_kg, t.location, t.status,
        COALESCE(SUM(CASE WHEN st.transaction_type = 'in' THEN st.quantity_kg
            WHEN st.transaction_type = 'out' THEN -st.quantity_kg
            WHEN st.transaction_type = 'adjustment' THEN st.quantity_kg
            WHEN st.transaction_type = 'transfer' THEN st.quantity_kg ELSE 0 END), 0) as current_stock_kg,
        ROUND(COALESCE(SUM(CASE WHEN st.transaction_type = 'in' THEN st.quantity_kg
            WHEN st.transaction_type = 'out' THEN -st.quantity_kg
            WHEN st.transaction_type = 'adjustment' THEN st.quantity_kg
            WHEN st.transaction_type = 'transfer' THEN st.quantity_kg ELSE 0 END), 0) / t.capacity_kg * 100, 2) as utilization_percentage,
        COUNT(st.transaction_id) as total_transactions,
        MAX(st.transaction_date) as last_transaction_date
    FROM storage_tanks t
    LEFT JOIN cpo_stock_transactions st ON t.tank_id = st.storage_tank_id
    GROUP BY t.tank_id, t.tank_code, t.tank_name, t.tank_type, t.capacity_kg, t.location, t.status");
    
    $db->exec("CREATE OR REPLACE VIEW vw_tank_utilization_alerts AS
    SELECT tank_id, tank_code, tank_name, capacity_kg, current_stock_kg, utilization_percentage,
        CASE WHEN utilization_percentage >= 95 THEN 'CRITICAL - Nearly Full'
            WHEN utilization_percentage >= 85 THEN 'WARNING - High Utilization'
            WHEN utilization_percentage <= 10 THEN 'LOW - Nearly Empty'
            WHEN utilization_percentage <= 20 THEN 'NOTICE - Low Stock' ELSE 'NORMAL' END as alert_level,
        CASE WHEN utilization_percentage >= 95 THEN 'danger'
            WHEN utilization_percentage >= 85 THEN 'warning'
            WHEN utilization_percentage <= 10 THEN 'danger'
            WHEN utilization_percentage <= 20 THEN 'info' ELSE 'success' END as alert_color
    FROM vw_tank_stock_summary WHERE status = 'active'");
    
    $db->exec("CREATE OR REPLACE VIEW vw_stock_aging AS
    SELECT t.tank_id, t.tank_code, t.tank_name, s.current_stock_kg,
        MIN(st.transaction_date) as oldest_stock_date,
        DATEDIFF(CURDATE(), MIN(st.transaction_date)) as days_in_storage,
        CASE WHEN DATEDIFF(CURDATE(), MIN(st.transaction_date)) > 60 THEN 'OLD - Over 60 days'
            WHEN DATEDIFF(CURDATE(), MIN(st.transaction_date)) > 30 THEN 'AGING - 30-60 days'
            WHEN DATEDIFF(CURDATE(), MIN(st.transaction_date)) > 14 THEN 'MODERATE - 14-30 days'
            ELSE 'FRESH - Under 14 days' END as aging_status
    FROM storage_tanks t
    INNER JOIN vw_tank_stock_summary s ON t.tank_id = s.tank_id
    LEFT JOIN cpo_stock_transactions st ON t.tank_id = st.storage_tank_id AND st.transaction_type = 'in'
    WHERE s.current_stock_kg > 0
    GROUP BY t.tank_id, t.tank_code, t.tank_name, s.current_stock_kg");
    echo "✓ Created CPO views\n\n";
    
    // ============================================
    // 2. KERNEL INVENTORY SYSTEM
    // ============================================
    echo "========================================\n";
    echo "2. KERNEL INVENTORY SYSTEM\n";
    echo "========================================\n\n";
    
    $db->exec("DROP TABLE IF EXISTS kernel_stock_transactions");
    $db->exec("DROP TABLE IF EXISTS kernel_storage");
    echo "✓ Dropped existing Kernel tables\n";
    
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $db->exec("CREATE TABLE kernel_stock_transactions (
        transaction_id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_date DATE NOT NULL,
        transaction_time TIME NOT NULL,
        transaction_type ENUM('in', 'out', 'adjustment', 'transfer') NOT NULL,
        storage_id INT NOT NULL,
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
        INDEX idx_storage (storage_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created Kernel tables\n";
    
    $db->exec("INSERT INTO kernel_storage (storage_code, storage_name, storage_type, capacity_kg, location, status, created_by) VALUES
    ('KRN-SILO-01', 'Kernel Silo 1', 'silo', 300000, 'Mill Area', 'active', 'system'),
    ('KRN-WH-01', 'Kernel Warehouse 1', 'warehouse', 200000, 'Storage Area', 'active', 'system')");
    
    $db->exec("INSERT INTO kernel_stock_transactions (transaction_date, transaction_time, transaction_type, storage_id, quantity_kg, reference_no, created_by) VALUES
    (CURDATE() - INTERVAL 10 DAY, '08:00:00', 'in', 1, 25000, 'KPROD-001', 'system'),
    (CURDATE() - INTERVAL 5 DAY, '14:00:00', 'out', 1, 15000, 'KSALE-001', 'system')");
    echo "✓ Inserted Kernel sample data\n";
    
    $db->exec("CREATE OR REPLACE VIEW vw_kernel_stock_summary AS
    SELECT s.storage_id, s.storage_code, s.storage_name, s.storage_type, s.capacity_kg, s.location, s.status,
        COALESCE(SUM(CASE WHEN t.transaction_type = 'in' THEN t.quantity_kg
            WHEN t.transaction_type = 'out' THEN -t.quantity_kg
            WHEN t.transaction_type = 'adjustment' THEN t.quantity_kg
            WHEN t.transaction_type = 'transfer' THEN t.quantity_kg ELSE 0 END), 0) as current_stock_kg,
        ROUND(COALESCE(SUM(CASE WHEN t.transaction_type = 'in' THEN t.quantity_kg
            WHEN t.transaction_type = 'out' THEN -t.quantity_kg
            WHEN t.transaction_type = 'adjustment' THEN t.quantity_kg
            WHEN t.transaction_type = 'transfer' THEN t.quantity_kg ELSE 0 END), 0) / s.capacity_kg * 100, 2) as utilization_percentage,
        COUNT(t.transaction_id) as total_transactions,
        MAX(t.transaction_date) as last_transaction_date
    FROM kernel_storage s
    LEFT JOIN kernel_stock_transactions t ON s.storage_id = t.storage_id
    GROUP BY s.storage_id, s.storage_code, s.storage_name, s.storage_type, s.capacity_kg, s.location, s.status");
    
    $db->exec("CREATE OR REPLACE VIEW vw_kernel_storage_alerts AS
    SELECT storage_id, storage_code, storage_name, capacity_kg, current_stock_kg, utilization_percentage,
        CASE WHEN utilization_percentage >= 95 THEN 'CRITICAL - Nearly Full'
            WHEN utilization_percentage >= 85 THEN 'WARNING - High Utilization'
            WHEN utilization_percentage <= 10 THEN 'LOW - Nearly Empty'
            WHEN utilization_percentage <= 20 THEN 'NOTICE - Low Stock' ELSE 'NORMAL' END as alert_level,
        CASE WHEN utilization_percentage >= 95 THEN 'danger'
            WHEN utilization_percentage >= 85 THEN 'warning'
            WHEN utilization_percentage <= 10 THEN 'danger'
            WHEN utilization_percentage <= 20 THEN 'info' ELSE 'success' END as alert_color
    FROM vw_kernel_stock_summary WHERE status = 'active'");
    
    $db->exec("CREATE OR REPLACE VIEW vw_kernel_stock_aging AS
    SELECT s.storage_id, s.storage_code, s.storage_name, st.current_stock_kg,
        MIN(t.transaction_date) as oldest_stock_date,
        DATEDIFF(CURDATE(), MIN(t.transaction_date)) as days_in_storage,
        CASE WHEN DATEDIFF(CURDATE(), MIN(t.transaction_date)) > 60 THEN 'OLD - Over 60 days'
            WHEN DATEDIFF(CURDATE(), MIN(t.transaction_date)) > 30 THEN 'AGING - 30-60 days'
            WHEN DATEDIFF(CURDATE(), MIN(t.transaction_date)) > 14 THEN 'MODERATE - 14-30 days'
            ELSE 'FRESH - Under 14 days' END as aging_status
    FROM kernel_storage s
    INNER JOIN vw_kernel_stock_summary st ON s.storage_id = st.storage_id
    LEFT JOIN kernel_stock_transactions t ON s.storage_id = t.storage_id AND t.transaction_type = 'in'
    WHERE st.current_stock_kg > 0
    GROUP BY s.storage_id, s.storage_code, s.storage_name, st.current_stock_kg");
    echo "✓ Created Kernel views\n\n";
    
    // ============================================
    // 3. MATERIALS INVENTORY SYSTEM
    // ============================================
    echo "========================================\n";
    echo "3. MATERIALS INVENTORY SYSTEM\n";
    echo "========================================\n\n";
    
    $db->exec("DROP TABLE IF EXISTS material_transactions");
    $db->exec("DROP TABLE IF EXISTS materials");
    $db->exec("DROP TABLE IF EXISTS material_warehouses");
    echo "✓ Dropped existing Materials tables\n";

    $db->exec("CREATE TABLE material_warehouses (
        warehouse_id INT AUTO_INCREMENT PRIMARY KEY,
        warehouse_code VARCHAR(50) NOT NULL UNIQUE,
        warehouse_name VARCHAR(100) NOT NULL,
        warehouse_type ENUM('main', 'field', 'workshop', 'chemical') DEFAULT 'main',
        location VARCHAR(200),
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(50),
        INDEX idx_warehouse_code (warehouse_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE materials (
        material_id INT AUTO_INCREMENT PRIMARY KEY,
        material_code VARCHAR(50) NOT NULL UNIQUE,
        material_name VARCHAR(200) NOT NULL,
        category ENUM('fertilizer','pesticide','herbicide','equipment','fuel','spare_parts','other') NOT NULL,
        unit VARCHAR(20) NOT NULL,
        reorder_level DECIMAL(12,2) DEFAULT 0,
        max_stock DECIMAL(12,2) DEFAULT 0,
        unit_price DECIMAL(12,2) DEFAULT 0,
        default_warehouse_id INT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(50),
        INDEX idx_material_code (material_code),
        INDEX idx_category (category),
        INDEX idx_warehouse (default_warehouse_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE material_transactions (
        transaction_id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_date DATE NOT NULL,
        transaction_time TIME NOT NULL DEFAULT '08:00:00',
        transaction_type ENUM('in','out','adjustment','transfer') NOT NULL,
        warehouse_id INT NOT NULL,
        material_id INT NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        unit_price DECIMAL(12,2) DEFAULT 0,
        total_value DECIMAL(15,2) DEFAULT 0,
        reference_no VARCHAR(100),
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(50),
        updated_by VARCHAR(50),
        INDEX idx_transaction_date (transaction_date),
        INDEX idx_transaction_type (transaction_type),
        INDEX idx_warehouse (warehouse_id),
        INDEX idx_material (material_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ Created Materials tables\n";

    $db->exec("INSERT INTO material_warehouses (warehouse_code, warehouse_name, warehouse_type, location, created_by) VALUES
    ('WH-MAIN',     'Main Warehouse',      'main',     'Central Storage Area',    'system'),
    ('WH-FIELD-01', 'Field Warehouse 1',   'field',    'Division A - North',      'system'),
    ('WH-FIELD-02', 'Field Warehouse 2',   'field',    'Division B - South',      'system'),
    ('WH-CHEM',     'Chemical Storage',    'chemical', 'Chemical Secure Area',    'system'),
    ('WH-WORKSHOP', 'Workshop Store',      'workshop', 'Maintenance Workshop',    'system')");

    $db->exec("INSERT INTO materials
    (material_code, material_name, category, unit, reorder_level, max_stock, unit_price, default_warehouse_id, created_by) VALUES
    ('FERT-NPK-001',  'NPK Fertilizer 15-15-15',     'fertilizer', 'kg',    5000,  25000, 8500,   1, 'system'),
    ('FERT-UREA-001', 'Urea Fertilizer 46%',          'fertilizer', 'kg',    3000,  15000, 6500,   1, 'system'),
    ('FERT-MOP-001',  'Muriate of Potash (MOP)',      'fertilizer', 'kg',    2000,  10000, 9200,   1, 'system'),
    ('HERB-001',      'Herbicide Roundup 486 SL',     'herbicide',  'liter',  100,    500, 95000,  4, 'system'),
    ('HERB-002',      'Herbicide Gramoxone 276 SL',   'herbicide',  'liter',   80,    400, 110000, 4, 'system'),
    ('PEST-001',      'Insecticide Fastac 15 EC',     'pesticide',  'liter',   50,    300, 125000, 4, 'system'),
    ('PEST-002',      'Fungicide Anvil 50 SC',        'pesticide',  'liter',   30,    200, 145000, 4, 'system'),
    ('FUEL-DIESEL',   'Diesel Fuel',                  'fuel',       'liter', 2000,  10000, 12500,  1, 'system'),
    ('FUEL-PETROL',   'Petrol / Gasoline',            'fuel',       'liter',  500,   3000, 13500,  1, 'system'),
    ('EQUIP-001',     'Harvesting Chisel',            'equipment',  'pcs',     50,    200, 85000,  5, 'system'),
    ('EQUIP-002',     'Harvesting Sickle',            'equipment',  'pcs',     30,    150, 65000,  5, 'system'),
    ('SPARE-001',     'Excavator Filter Set',         'spare_parts','set',     10,     50, 350000, 5, 'system'),
    ('SPARE-002',     'Generator Spark Plug',         'spare_parts','pcs',     20,    100, 75000,  5, 'system'),
    ('OTHER-001',     'Personal Protective Equipment','other',       'set',    100,    500, 45000,  1, 'system')");

    $db->exec("INSERT INTO material_transactions
    (transaction_date, transaction_time, transaction_type, warehouse_id, material_id, quantity, unit_price, total_value, reference_no, remarks, created_by) VALUES
    -- NPK Fertilizer
    (CURDATE()-INTERVAL 45 DAY,'08:00:00','in',  1,1,15000,8500, 127500000,'PO-2024-001','Purchase Order NPK',  'system'),
    (CURDATE()-INTERVAL 30 DAY,'07:00:00','out', 1,1, 4000,8500,  34000000,'REQ-001',    'Field application Div A','system'),
    (CURDATE()-INTERVAL 20 DAY,'07:00:00','out', 1,1, 3500,8500,  29750000,'REQ-002',    'Field application Div B','system'),
    (CURDATE()-INTERVAL 10 DAY,'08:00:00','in',  1,1, 8000,8500,  68000000,'PO-2024-002','Purchase Order NPK',  'system'),
    (CURDATE()-INTERVAL  5 DAY,'07:00:00','out', 1,1, 2000,8500,  17000000,'REQ-003',    'Field application Div C','system'),
    -- Urea
    (CURDATE()-INTERVAL 40 DAY,'09:00:00','in',  1,2,10000,6500,  65000000,'PO-2024-003','Purchase Order Urea', 'system'),
    (CURDATE()-INTERVAL 25 DAY,'07:00:00','out', 1,2, 3000,6500,  19500000,'REQ-004',    'Field application',   'system'),
    (CURDATE()-INTERVAL 12 DAY,'07:00:00','out', 1,2, 2500,6500,  16250000,'REQ-005',    'Field application',   'system'),
    -- MOP
    (CURDATE()-INTERVAL 35 DAY,'08:30:00','in',  1,3, 5000,9200,  46000000,'PO-2024-004','Purchase Order MOP',  'system'),
    (CURDATE()-INTERVAL 18 DAY,'07:00:00','out', 1,3, 1500,9200,  13800000,'REQ-006',    'Field application',   'system'),
    -- Herbicide Roundup
    (CURDATE()-INTERVAL 50 DAY,'10:00:00','in',  4,4,  300,95000, 28500000,'PO-2024-005','Chemical purchase',   'system'),
    (CURDATE()-INTERVAL 28 DAY,'08:00:00','out', 4,4,   80,95000,  7600000,'REQ-007',    'Weeding operation',   'system'),
    (CURDATE()-INTERVAL 14 DAY,'08:00:00','out', 4,4,   60,95000,  5700000,'REQ-008',    'Weeding operation',   'system'),
    -- Gramoxone
    (CURDATE()-INTERVAL 45 DAY,'10:30:00','in',  4,5,  250,110000,27500000,'PO-2024-006','Chemical purchase',   'system'),
    (CURDATE()-INTERVAL 22 DAY,'08:00:00','out', 4,5,   70,110000, 7700000,'REQ-009',    'Weeding operation',   'system'),
    -- Insecticide
    (CURDATE()-INTERVAL 60 DAY,'09:00:00','in',  4,6,  150,125000,18750000,'PO-2024-007','Insecticide purchase','system'),
    (CURDATE()-INTERVAL 35 DAY,'08:00:00','out', 4,6,   40,125000, 5000000,'REQ-010',    'Pest control',        'system'),
    (CURDATE()-INTERVAL 15 DAY,'08:00:00','out', 4,6,   30,125000, 3750000,'REQ-011',    'Pest control',        'system'),
    -- Fungicide
    (CURDATE()-INTERVAL 55 DAY,'09:30:00','in',  4,7,  120,145000,17400000,'PO-2024-008','Fungicide purchase',  'system'),
    (CURDATE()-INTERVAL 30 DAY,'08:00:00','out', 4,7,   25,145000, 3625000,'REQ-012',    'Disease treatment',   'system'),
    -- Diesel Fuel
    (CURDATE()-INTERVAL 30 DAY,'06:00:00','in',  1,8, 5000,12500, 62500000,'PO-2024-009','Fuel delivery',       'system'),
    (CURDATE()-INTERVAL 20 DAY,'06:00:00','out', 1,8, 1200,12500, 15000000,'REQ-013',    'Machinery fuel',      'system'),
    (CURDATE()-INTERVAL 10 DAY,'06:00:00','in',  1,8, 5000,12500, 62500000,'PO-2024-010','Fuel delivery',       'system'),
    (CURDATE()-INTERVAL  5 DAY,'06:00:00','out', 1,8,  800,12500, 10000000,'REQ-014',    'Machinery fuel',      'system'),
    -- Petrol
    (CURDATE()-INTERVAL 30 DAY,'06:30:00','in',  1,9, 2000,13500, 27000000,'PO-2024-011','Fuel delivery',       'system'),
    (CURDATE()-INTERVAL 15 DAY,'06:30:00','out', 1,9,  500,13500,  6750000,'REQ-015',    'Light vehicle fuel',  'system'),
    -- Harvesting Chisel
    (CURDATE()-INTERVAL 90 DAY,'11:00:00','in',  5,10, 150,85000, 12750000,'PO-2024-012','Tools purchase',      'system'),
    (CURDATE()-INTERVAL 45 DAY,'11:00:00','out', 5,10,  30,85000,  2550000,'REQ-016',    'Issued to harvesters','system'),
    (CURDATE()-INTERVAL 15 DAY,'11:00:00','out', 5,10,  20,85000,  1700000,'REQ-017',    'Replacement issue',   'system'),
    -- Harvesting Sickle
    (CURDATE()-INTERVAL 90 DAY,'11:30:00','in',  5,11, 100,65000,  6500000,'PO-2024-013','Tools purchase',      'system'),
    (CURDATE()-INTERVAL 45 DAY,'11:30:00','out', 5,11,  20,65000,  1300000,'REQ-018',    'Issued to harvesters','system'),
    -- Spare Parts
    (CURDATE()-INTERVAL 60 DAY,'13:00:00','in',  5,12,  30,350000,10500000,'PO-2024-014','Spare parts purchase','system'),
    (CURDATE()-INTERVAL 20 DAY,'13:00:00','out', 5,12,   5,350000, 1750000,'REQ-019',    'Excavator maintenance','system'),
    -- Generator Spark Plug
    (CURDATE()-INTERVAL 60 DAY,'13:30:00','in',  5,13,  60,75000,  4500000,'PO-2024-015','Spare parts purchase','system'),
    (CURDATE()-INTERVAL 25 DAY,'13:30:00','out', 5,13,  10,75000,   750000,'REQ-020',    'Generator service',   'system'),
    -- PPE
    (CURDATE()-INTERVAL 120 DAY,'14:00:00','in', 1,14, 300,45000, 13500000,'PO-2024-016','PPE purchase',        'system'),
    (CURDATE()-INTERVAL  60 DAY,'14:00:00','out',1,14,  80,45000,  3600000,'REQ-021',    'Issued to workers',   'system'),
    (CURDATE()-INTERVAL  30 DAY,'14:00:00','out',1,14,  60,45000,  2700000,'REQ-022',    'Issued to workers',   'system'),
    -- Stock adjustments
    (CURDATE()-INTERVAL   7 DAY,'16:00:00','adjustment',1,1, -50,8500,   -425000,'ADJ-001','Stock count variance','system'),
    (CURDATE()-INTERVAL   7 DAY,'16:00:00','adjustment',4,4,  -5,95000,  -475000,'ADJ-002','Stock count variance','system')");
    echo "✓ Inserted Materials sample data (14 materials, 42 transactions)\n";

    $db->exec("CREATE OR REPLACE VIEW vw_material_stock_summary AS
    SELECT
        m.material_id,
        m.material_code,
        m.material_name,
        m.category,
        m.unit,
        m.reorder_level,
        m.max_stock,
        m.unit_price,
        COALESCE(SUM(
            CASE
                WHEN t.transaction_type = 'in'         THEN  t.quantity
                WHEN t.transaction_type = 'out'        THEN -t.quantity
                WHEN t.transaction_type = 'adjustment' THEN  t.quantity
                WHEN t.transaction_type = 'transfer'   THEN  t.quantity
                ELSE 0
            END
        ), 0) AS current_stock,
        COALESCE(SUM(
            CASE
                WHEN t.transaction_type = 'in'         THEN  t.quantity
                WHEN t.transaction_type = 'out'        THEN -t.quantity
                WHEN t.transaction_type = 'adjustment' THEN  t.quantity
                WHEN t.transaction_type = 'transfer'   THEN  t.quantity
                ELSE 0
            END
        ), 0) * m.unit_price AS stock_value
    FROM materials m
    LEFT JOIN material_transactions t ON m.material_id = t.material_id
    WHERE m.status = 'active'
    GROUP BY m.material_id, m.material_code, m.material_name,
             m.category, m.unit, m.reorder_level, m.max_stock, m.unit_price");

    $db->exec("CREATE OR REPLACE VIEW vw_material_stock_alerts AS
    SELECT
        material_id,
        material_code,
        material_name,
        category,
        unit,
        current_stock,
        reorder_level,
        max_stock,
        unit_price,
        stock_value,
        CASE
            WHEN current_stock = 0                   THEN 'OUT OF STOCK'
            WHEN current_stock <= reorder_level      THEN 'LOW - Reorder Required'
            WHEN current_stock >= max_stock          THEN 'HIGH - Overstock'
            ELSE 'NORMAL'
        END AS alert_level,
        CASE
            WHEN current_stock = 0                   THEN 'danger'
            WHEN current_stock <= reorder_level      THEN 'warning'
            WHEN current_stock >= max_stock          THEN 'info'
            ELSE 'success'
        END AS alert_color,
        CASE
            WHEN current_stock > 0 AND reorder_level > 0
            THEN FLOOR(current_stock / (reorder_level / 30))
            ELSE 999
        END AS days_until_stockout
    FROM vw_material_stock_summary");
    echo "✓ Created Materials views\n\n";
    
    // Re-enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Final verification
    echo "========================================\n";
    echo "VERIFICATION\n";
    echo "========================================\n\n";
    
    $cpo_tanks      = $db->query("SELECT COUNT(*) FROM storage_tanks")->fetchColumn();
    $kernel_storage = $db->query("SELECT COUNT(*) FROM kernel_storage")->fetchColumn();
    $material_wh    = $db->query("SELECT COUNT(*) FROM material_warehouses")->fetchColumn();
    $material_items = $db->query("SELECT COUNT(*) FROM materials")->fetchColumn();
    $material_trans = $db->query("SELECT COUNT(*) FROM material_transactions")->fetchColumn();

    echo "✓ CPO: $cpo_tanks tanks created\n";
    echo "✓ Kernel: $kernel_storage storage locations created\n";
    echo "✓ Materials: $material_wh warehouses, $material_items materials, $material_trans transactions\n\n";
    
    $views = $db->query("SHOW TABLES LIKE 'vw_%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "✓ Total views created: " . count($views) . "\n";
    foreach ($views as $view) {
        echo "  - $view\n";
    }
    
    echo "\n========================================\n";
    echo "✓✓✓ SUCCESS! ALL INVENTORY SYSTEMS READY! ✓✓✓\n";
    echo "========================================\n";
    echo "\nYou can now access:\n";
    echo "- inventory_cpo.php\n";
    echo "- inventory_kernel.php\n";
    echo "- inventory_materials.php\n";
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
