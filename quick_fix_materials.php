<?php
/**
 * Quick Fix for Materials Inventory
 */

require_once 'config/database.php';

try {
    $db = getDB();
    echo "<h2>Quick Fix: Materials Inventory</h2><pre>";
    
    // Disable FK checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Drop and create materials table
    $db->exec("DROP TABLE IF EXISTS material_stock_transactions");
    $db->exec("DROP TABLE IF EXISTS materials");
    echo "✓ Dropped old tables\n";
    
    $db->exec("CREATE TABLE materials (
        material_id INT AUTO_INCREMENT PRIMARY KEY,
        material_code VARCHAR(50) NOT NULL UNIQUE,
        material_name VARCHAR(200) NOT NULL,
        category ENUM('fertilizer', 'pesticide', 'herbicide', 'tools', 'equipment', 'spare_parts', 'fuel', 'other') NOT NULL,
        unit VARCHAR(20) NOT NULL,
        min_stock DECIMAL(12,2) DEFAULT 0,
        max_stock DECIMAL(12,2) DEFAULT 0,
        reorder_level DECIMAL(12,2) DEFAULT 0,
        unit_price DECIMAL(12,2) DEFAULT 0,
        default_warehouse_id INT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(50),
        INDEX idx_material_code (material_code),
        INDEX idx_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created materials table\n";
    
    $db->exec("CREATE TABLE material_stock_transactions (
        transaction_id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_date DATE NOT NULL,
        transaction_type ENUM('in', 'out', 'adjustment', 'transfer') NOT NULL,
        warehouse_id INT NOT NULL,
        material_id INT NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        unit_price DECIMAL(12,2) DEFAULT 0,
        total_value DECIMAL(15,2) DEFAULT 0,
        reference_no VARCHAR(100),
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(50),
        INDEX idx_transaction_date (transaction_date),
        INDEX idx_warehouse (warehouse_id),
        INDEX idx_material (material_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created material_stock_transactions table\n";
    
    // Insert sample data
    $db->exec("INSERT INTO materials (material_code, material_name, category, unit, min_stock, max_stock, reorder_level, unit_price, default_warehouse_id, created_by) VALUES
    ('FERT-NPK-001', 'NPK Fertilizer 15-15-15', 'fertilizer', 'kg', 5000, 20000, 7500, 8500, 1, 'system'),
    ('PEST-001', 'Insecticide Type A', 'pesticide', 'liter', 100, 500, 200, 125000, 1, 'system'),
    ('TOOL-001', 'Harvesting Chisel', 'tools', 'pcs', 50, 200, 100, 85000, 1, 'system')");
    echo "✓ Inserted 3 materials\n";
    
    $db->exec("INSERT INTO material_stock_transactions (transaction_date, transaction_type, warehouse_id, material_id, quantity, unit_price, total_value, reference_no, created_by) VALUES
    (CURDATE() - INTERVAL 10 DAY, 'in', 1, 1, 10000, 8500, 85000000, 'PO-001', 'system'),
    (CURDATE() - INTERVAL 5 DAY, 'out', 1, 1, 2000, 8500, 17000000, 'REQ-001', 'system'),
    (CURDATE() - INTERVAL 3 DAY, 'in', 1, 2, 200, 125000, 25000000, 'PO-002', 'system')");
    echo "✓ Inserted 3 transactions\n";
    
    // Create/update view
    $db->exec("CREATE OR REPLACE VIEW vw_material_stock_summary AS
    SELECT w.warehouse_id, w.warehouse_code, w.warehouse_name, m.material_id, m.material_code, m.material_name,
        m.category, m.unit, m.min_stock, m.max_stock, m.reorder_level,
        COALESCE(SUM(CASE WHEN t.transaction_type = 'in' THEN t.quantity
            WHEN t.transaction_type = 'out' THEN -t.quantity
            WHEN t.transaction_type = 'adjustment' THEN t.quantity
            WHEN t.transaction_type = 'transfer' THEN t.quantity ELSE 0 END), 0) as current_stock,
        m.unit_price,
        COALESCE(SUM(CASE WHEN t.transaction_type = 'in' THEN t.quantity
            WHEN t.transaction_type = 'out' THEN -t.quantity
            WHEN t.transaction_type = 'adjustment' THEN t.quantity
            WHEN t.transaction_type = 'transfer' THEN t.quantity ELSE 0 END), 0) * m.unit_price as stock_value
    FROM material_warehouses w
    CROSS JOIN materials m
    LEFT JOIN material_stock_transactions t ON w.warehouse_id = t.warehouse_id AND m.material_id = t.material_id
    WHERE w.status = 'active' AND m.status = 'active'
    GROUP BY w.warehouse_id, w.warehouse_code, w.warehouse_name, m.material_id, m.material_code, m.material_name,
        m.category, m.unit, m.min_stock, m.max_stock, m.reorder_level, m.unit_price");
    echo "✓ Created vw_material_stock_summary\n";
    
    // Create material_stock_alerts view
    $db->exec("CREATE OR REPLACE VIEW vw_material_stock_alerts AS
    SELECT
        warehouse_id, warehouse_code, warehouse_name,
        material_id, material_code, material_name, category,
        current_stock, reorder_level, min_stock, max_stock,
        CASE
            WHEN current_stock <= min_stock THEN 'CRITICAL - Below Minimum'
            WHEN current_stock <= reorder_level THEN 'LOW - Reorder Required'
            WHEN current_stock >= max_stock THEN 'HIGH - Overstock'
            ELSE 'NORMAL'
        END as alert_level,
        CASE
            WHEN current_stock <= min_stock THEN 'danger'
            WHEN current_stock <= reorder_level THEN 'warning'
            WHEN current_stock >= max_stock THEN 'info'
            ELSE 'success'
        END as alert_color,
        CASE
            WHEN current_stock > 0 AND current_stock <= reorder_level
            THEN GREATEST(0, FLOOR(current_stock / NULLIF((SELECT AVG(quantity)
                FROM material_stock_transactions
                WHERE material_id = vw_material_stock_summary.material_id
                AND transaction_type = 'out'
                AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 0)))
            ELSE NULL
        END as days_until_stockout
    FROM vw_material_stock_summary
    WHERE current_stock >= 0");
    echo "✓ Created vw_material_stock_alerts\n";
    
    // Create alias view for compatibility
    $db->exec("CREATE OR REPLACE VIEW material_transactions AS SELECT * FROM material_stock_transactions");
    echo "✓ Created material_transactions (alias)\n";
    
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Test
    $count = $db->query("SELECT COUNT(*) FROM materials")->fetchColumn();
    echo "\n✓✓✓ SUCCESS! $count materials in database\n";
    echo "You can now access inventory_materials.php\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre>ERROR: " . $e->getMessage() . "</pre>";
}
?>

// Powered by IBM Bob
