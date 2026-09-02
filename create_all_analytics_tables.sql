-- ============================================
-- COMPLETE FIX for Analytics.php Missing Tables
-- Creates ALL required tables WITHOUT foreign key constraints
-- ============================================

USE agro;

-- ============================================
-- 1. HARVEST TABLES
-- ============================================

DROP TABLE IF EXISTS harvest_quality_control;
DROP TABLE IF EXISTS harvest_productivity;
DROP TABLE IF EXISTS harvest_realizations;
DROP TABLE IF EXISTS harvest_plans;

CREATE TABLE harvest_plans (
    harvest_plan_id INT AUTO_INCREMENT PRIMARY KEY,
    plan_number VARCHAR(50) NOT NULL UNIQUE,
    block_id INT NOT NULL,
    plan_date DATE NOT NULL,
    planned_start_date DATE NOT NULL,
    planned_end_date DATE,
    estimated_quantity_kg DECIMAL(12,2),
    estimated_bunches INT,
    harvesting_round ENUM('Round 1', 'Round 2', 'Round 3', 'Round 4') DEFAULT 'Round 1',
    harvesting_criteria VARCHAR(255) COMMENT 'Ripeness criteria',
    assigned_team VARCHAR(100),
    supervisor VARCHAR(100),
    status ENUM('Planned', 'In Progress', 'Completed', 'Cancelled') DEFAULT 'Planned',
    notes TEXT,
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(50),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_block (block_id),
    INDEX idx_plan_date (plan_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE harvest_realizations (
    harvest_id INT AUTO_INCREMENT PRIMARY KEY,
    harvest_number VARCHAR(50) NOT NULL UNIQUE,
    harvest_plan_id INT,
    block_id INT NOT NULL,
    harvest_date DATE NOT NULL,
    actual_quantity_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
    actual_bunches INT NOT NULL DEFAULT 0,
    loose_fruits_kg DECIMAL(10,2) DEFAULT 0,
    average_bunch_weight DECIMAL(10,2) COMMENT 'kg per bunch',
    harvesting_round ENUM('Round 1', 'Round 2', 'Round 3', 'Round 4') DEFAULT 'Round 1',
    harvester_count INT,
    harvester_names TEXT COMMENT 'Comma-separated names',
    supervisor VARCHAR(100),
    quality_grade ENUM('Premium', 'Grade A', 'Grade B', 'Grade C', 'Reject') DEFAULT 'Grade A',
    ripeness_level ENUM('Under Ripe', 'Ripe', 'Over Ripe') DEFAULT 'Ripe',
    weather_condition VARCHAR(100),
    transport_vehicle VARCHAR(100),
    delivery_destination VARCHAR(255) COMMENT 'Mill or collection point',
    delivery_time TIME,
    status ENUM('Harvested', 'In Transit', 'Delivered', 'Rejected') DEFAULT 'Harvested',
    notes TEXT,
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_harvest_date (harvest_date),
    INDEX idx_block (block_id),
    INDEX idx_harvest_plan (harvest_plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE harvest_productivity (
    productivity_id INT AUTO_INCREMENT PRIMARY KEY,
    harvest_id INT NOT NULL,
    harvester_name VARCHAR(100) NOT NULL,
    harvest_date DATE NOT NULL,
    bunches_harvested INT NOT NULL DEFAULT 0,
    quantity_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    loose_fruits_kg DECIMAL(10,2) DEFAULT 0,
    working_hours DECIMAL(5,2),
    productivity_rate DECIMAL(10,2) COMMENT 'kg per hour',
    payment_amount DECIMAL(12,2) COMMENT 'Based on quantity or bunches',
    payment_type ENUM('Per Bunch', 'Per Kg', 'Daily Rate') DEFAULT 'Per Bunch',
    notes TEXT,
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_harvest (harvest_id),
    INDEX idx_harvester (harvester_name),
    INDEX idx_harvest_date (harvest_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE harvest_quality_control (
    quality_id INT AUTO_INCREMENT PRIMARY KEY,
    harvest_id INT NOT NULL,
    inspection_date DATE NOT NULL,
    inspector_name VARCHAR(100),
    quality_grade ENUM('Premium', 'Grade A', 'Grade B', 'Grade C', 'Reject') NOT NULL,
    ripeness_level ENUM('Under Ripe', 'Ripe', 'Over Ripe') NOT NULL,
    defect_percentage DECIMAL(5,2) DEFAULT 0,
    defect_types VARCHAR(255) COMMENT 'e.g., Bruised, Damaged, Diseased',
    oil_content_percentage DECIMAL(5,2),
    moisture_content_percentage DECIMAL(5,2),
    foreign_matter_percentage DECIMAL(5,2),
    passed BOOLEAN DEFAULT TRUE,
    rejection_reason TEXT,
    corrective_action TEXT,
    notes TEXT,
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_harvest (harvest_id),
    INDEX idx_inspection_date (inspection_date),
    INDEX idx_quality_grade (quality_grade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 2. COST TRACKING TABLE
-- ============================================

DROP TABLE IF EXISTS block_costs;

CREATE TABLE block_costs (
    cost_id INT AUTO_INCREMENT PRIMARY KEY,
    block_id INT NOT NULL,
    cost_date DATE NOT NULL,
    cost_category ENUM('labor', 'material', 'equipment', 'overhead', 'fertilizer', 'pesticide', 'harvesting', 'maintenance', 'other') NOT NULL,
    cost_description VARCHAR(200),
    cost_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    quantity DECIMAL(10,2),
    unit VARCHAR(20),
    reference_no VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(50),
    INDEX idx_block (block_id),
    INDEX idx_cost_date (cost_date),
    INDEX idx_cost_category (cost_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 3. SALES TABLES
-- ============================================

DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS customers;

CREATE TABLE customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_code VARCHAR(50) NOT NULL UNIQUE,
    customer_name VARCHAR(200) NOT NULL,
    customer_type ENUM('Mill', 'Trader', 'Exporter', 'Refinery', 'Direct Consumer') NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    status ENUM('Active', 'Inactive', 'Suspended') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(50),
    INDEX idx_customer_code (customer_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sales (
    sale_id INT AUTO_INCREMENT PRIMARY KEY,
    sale_date DATE NOT NULL,
    company_id INT NOT NULL,
    customer_id INT NOT NULL,
    product_type ENUM('FFB', 'CPO', 'Kernel', 'PKO', 'Other') NOT NULL,
    quantity_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    payment_status ENUM('pending', 'partial', 'paid') DEFAULT 'pending',
    invoice_number VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(50),
    INDEX idx_sale_date (sale_date),
    INDEX idx_company (company_id),
    INDEX idx_customer (customer_id),
    INDEX idx_product_type (product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 4. BUDGETS TABLE
-- ============================================

DROP TABLE IF EXISTS budgets;

CREATE TABLE budgets (
    budget_id INT AUTO_INCREMENT PRIMARY KEY,
    budget_year YEAR NOT NULL,
    budget_type ENUM('operational', 'capital', 'maintenance', 'harvesting', 'fertilizer', 'labor', 'other') NOT NULL,
    company_id INT NOT NULL,
    division_id INT,
    block_id INT,
    planned_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    actual_amount DECIMAL(15,2) DEFAULT 0,
    variance DECIMAL(15,2) GENERATED ALWAYS AS (planned_amount - actual_amount) STORED,
    variance_percentage DECIMAL(10,2) GENERATED ALWAYS AS (
        CASE WHEN planned_amount > 0
        THEN ((planned_amount - actual_amount) / planned_amount * 100)
        ELSE 0 END
    ) STORED,
    description VARCHAR(255),
    status ENUM('draft', 'approved', 'active', 'closed') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(50),
    INDEX idx_budget_year (budget_year),
    INDEX idx_company (company_id),
    INDEX idx_budget_type (budget_type),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- INSERT SAMPLE DATA
-- ============================================

-- Sample harvest plans
INSERT IGNORE INTO harvest_plans (plan_number, block_id, plan_date, planned_start_date, planned_end_date,
                          estimated_quantity_kg, estimated_bunches, harvesting_round,
                          assigned_team, supervisor, status, created_by)
VALUES
('HP-2024-0001', 1, '2024-01-01', '2024-01-05', '2024-01-07', 5000, 250, 'Round 1',
 'Team A', 'John Supervisor', 'Completed', 'admin'),
('HP-2024-0002', 2, '2024-01-08', '2024-01-10', '2024-01-12', 4500, 225, 'Round 1',
 'Team B', 'Jane Supervisor', 'In Progress', 'admin'),
('HP-2024-0003', 1, '2024-01-15', '2024-01-18', '2024-01-20', 5200, 260, 'Round 2',
 'Team A', 'John Supervisor', 'Planned', 'admin');

-- Sample harvest data
INSERT IGNORE INTO harvest_realizations (harvest_number, block_id, harvest_date, actual_quantity_kg,
                                         actual_bunches, average_bunch_weight, created_by)
VALUES
('HV-2024-0001', 1, '2024-01-05', 5200, 260, 20.0, 'system'),
('HV-2024-0002', 1, '2024-02-10', 4800, 240, 20.0, 'system'),
('HV-2024-0003', 2, '2024-03-15', 4600, 230, 20.0, 'system'),
('HV-2024-0004', 1, '2024-04-20', 5000, 250, 20.0, 'system'),
('HV-2024-0005', 2, '2024-05-25', 4700, 235, 20.0, 'system');

-- Sample cost data
INSERT IGNORE INTO block_costs (block_id, cost_date, cost_category, cost_description, cost_amount, created_by)
VALUES 
(1, '2024-01-15', 'labor', 'Field maintenance', 1500000, 'system'),
(1, '2024-02-15', 'fertilizer', 'NPK application', 3000000, 'system'),
(1, '2024-03-15', 'harvesting', 'FFB harvesting', 2500000, 'system'),
(2, '2024-01-20', 'labor', 'Weeding', 1200000, 'system'),
(2, '2024-02-20', 'pesticide', 'Pest control', 800000, 'system');

-- Sample customers
INSERT IGNORE INTO customers (customer_code, customer_name, customer_type, created_by)
VALUES 
('CUST-001', 'PT Wilmar Indonesia', 'Mill', 'system'),
('CUST-002', 'PT Musim Mas', 'Refinery', 'system'),
('CUST-003', 'PT Sinar Mas Agro', 'Trader', 'system');

-- Sample sales data
INSERT IGNORE INTO sales (sale_date, company_id, customer_id, product_type, quantity_kg,
                          unit_price, total_amount, invoice_number, created_by)
VALUES
('2024-01-10', 1, 1, 'FFB', 25000, 1800, 45000000, 'INV-2024-0001', 'system'),
('2024-02-15', 1, 2, 'CPO', 18000, 13000, 234000000, 'INV-2024-0002', 'system'),
('2024-03-20', 1, 3, 'Kernel', 8000, 9000, 72000000, 'INV-2024-0003', 'system'),
('2024-04-25', 1, 1, 'FFB', 28000, 1850, 51800000, 'INV-2024-0004', 'system'),
('2024-05-30', 1, 2, 'CPO', 20000, 13500, 270000000, 'INV-2024-0005', 'system');

-- Sample budget data
INSERT IGNORE INTO budgets (budget_year, budget_type, company_id, planned_amount, actual_amount,
                            description, status, created_by)
VALUES
(2024, 'operational', 1, 500000000, 450000000, 'Operational budget 2024', 'active', 'system'),
(2024, 'capital', 1, 200000000, 180000000, 'Capital expenditure 2024', 'active', 'system'),
(2024, 'maintenance', 1, 150000000, 140000000, 'Maintenance budget 2024', 'active', 'system'),
(2024, 'harvesting', 1, 300000000, 280000000, 'Harvesting operations 2024', 'active', 'system'),
(2024, 'fertilizer', 1, 250000000, 240000000, 'Fertilizer program 2024', 'active', 'system'),
(2024, 'labor', 1, 400000000, 390000000, 'Labor costs 2024', 'active', 'system');

-- ============================================
-- VERIFICATION
-- ============================================

SELECT '✓ SUCCESS: All analytics tables created!' AS Status;
SELECT 'harvest_plans' AS TableName, COUNT(*) AS RecordCount FROM harvest_plans
UNION ALL
SELECT 'harvest_realizations', COUNT(*) FROM harvest_realizations
UNION ALL
SELECT 'harvest_productivity', COUNT(*) FROM harvest_productivity
UNION ALL
SELECT 'harvest_quality_control', COUNT(*) FROM harvest_quality_control
UNION ALL
SELECT 'block_costs', COUNT(*) FROM block_costs
UNION ALL
SELECT 'sales', COUNT(*) FROM sales
UNION ALL
SELECT 'customers', COUNT(*) FROM customers
UNION ALL
SELECT 'budgets', COUNT(*) FROM budgets;

-- Show table list
SHOW TABLES LIKE '%harvest%';
SHOW TABLES LIKE '%cost%';
SHOW TABLES LIKE '%sales%';
SHOW TABLES LIKE '%customer%';

-- Made with Bob
