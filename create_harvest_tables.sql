-- ============================================
-- Fix for Missing harvest_realizations Table
-- Execute this file in phpMyAdmin or MySQL command line
-- ============================================

USE agro;

-- Drop existing tables if they exist (to avoid conflicts)
DROP TABLE IF EXISTS harvest_quality_control;
DROP TABLE IF EXISTS harvest_productivity;
DROP TABLE IF EXISTS harvest_realizations;
DROP TABLE IF EXISTS harvest_plans;

-- Harvest Plans Table
CREATE TABLE IF NOT EXISTS harvest_plans (
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
    FOREIGN KEY (block_id) REFERENCES blocks(block_id),
    INDEX idx_plan_number (plan_number),
    INDEX idx_block (block_id),
    INDEX idx_plan_date (plan_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Harvest Realizations Table (THE MISSING TABLE)
CREATE TABLE IF NOT EXISTS harvest_realizations (
    harvest_id INT AUTO_INCREMENT PRIMARY KEY,
    harvest_number VARCHAR(50) NOT NULL UNIQUE,
    harvest_plan_id INT,
    block_id INT NOT NULL,
    harvest_date DATE NOT NULL,
    actual_quantity_kg DECIMAL(12,2) NOT NULL,
    actual_bunches INT NOT NULL,
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
    FOREIGN KEY (harvest_plan_id) REFERENCES harvest_plans(harvest_plan_id) ON DELETE SET NULL,
    FOREIGN KEY (block_id) REFERENCES blocks(block_id),
    INDEX idx_harvest_number (harvest_number),
    INDEX idx_harvest_date (harvest_date),
    INDEX idx_block (block_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Harvest Productivity Table
CREATE TABLE IF NOT EXISTS harvest_productivity (
    productivity_id INT AUTO_INCREMENT PRIMARY KEY,
    harvest_id INT NOT NULL,
    harvester_name VARCHAR(100) NOT NULL,
    harvest_date DATE NOT NULL,
    bunches_harvested INT NOT NULL,
    quantity_kg DECIMAL(10,2) NOT NULL,
    loose_fruits_kg DECIMAL(10,2) DEFAULT 0,
    working_hours DECIMAL(5,2),
    productivity_rate DECIMAL(10,2) COMMENT 'kg per hour',
    payment_amount DECIMAL(12,2) COMMENT 'Based on quantity or bunches',
    payment_type ENUM('Per Bunch', 'Per Kg', 'Daily Rate') DEFAULT 'Per Bunch',
    notes TEXT,
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (harvest_id) REFERENCES harvest_realizations(harvest_id) ON DELETE CASCADE,
    INDEX idx_harvest (harvest_id),
    INDEX idx_harvester (harvester_name),
    INDEX idx_harvest_date (harvest_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Harvest Quality Control Table
CREATE TABLE IF NOT EXISTS harvest_quality_control (
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
    FOREIGN KEY (harvest_id) REFERENCES harvest_realizations(harvest_id) ON DELETE CASCADE,
    INDEX idx_harvest (harvest_id),
    INDEX idx_inspection_date (inspection_date),
    INDEX idx_quality_grade (quality_grade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert sample data
INSERT INTO harvest_plans (plan_number, block_id, plan_date, planned_start_date, planned_end_date,
                          estimated_quantity_kg, estimated_bunches, harvesting_round, 
                          assigned_team, supervisor, status, created_by)
VALUES 
('HP-2024-0001', 1, '2024-01-01', '2024-01-05', '2024-01-07', 5000, 250, 'Round 1', 
 'Team A', 'John Supervisor', 'Completed', 'admin'),
('HP-2024-0002', 2, '2024-01-08', '2024-01-10', '2024-01-12', 4500, 225, 'Round 1', 
 'Team B', 'Jane Supervisor', 'In Progress', 'admin');

INSERT INTO harvest_realizations (harvest_number, harvest_plan_id, block_id, harvest_date,
                                 actual_quantity_kg, actual_bunches, loose_fruits_kg,
                                 average_bunch_weight, harvesting_round, harvester_count,
                                 supervisor, quality_grade, ripeness_level, weather_condition,
                                 transport_vehicle, delivery_destination, status, created_by)
VALUES 
('HV-2024-0001', 1, 1, '2024-01-05', 5200, 260, 150, 20.0, 'Round 1', 8,
 'John Supervisor', 'Grade A', 'Ripe', 'Sunny', 'Truck-01', 'Main Mill', 'Delivered', 'admin'),
('HV-2024-0002', 1, 1, '2024-01-06', 4800, 240, 120, 20.0, 'Round 1', 8,
 'John Supervisor', 'Grade A', 'Ripe', 'Cloudy', 'Truck-02', 'Main Mill', 'Delivered', 'admin');

SELECT 'SUCCESS: All harvest tables created!' AS Status;

-- Made with Bob
