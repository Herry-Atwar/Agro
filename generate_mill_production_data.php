<?php
/**
 * Generate Sample Mill Production Data
 * Creates realistic mill production records with CPO, kernel, and by-products
 */

require_once 'config/database.php';

try {
    $db = getDB();
    echo "<h2>Generating Mill Production Sample Data</h2><pre>";
    
    // Check if mill_processing_batch table exists
    $tables = $db->query("SHOW TABLES LIKE 'mill_processing_batch'")->fetchAll();
    if (empty($tables)) {
        echo "⚠ mill_processing_batch table not found. Creating it first...\n\n";
        
        // Create mill_processing_batch table
        $db->exec("CREATE TABLE IF NOT EXISTS mill_processing_batch (
            batch_id INT AUTO_INCREMENT PRIMARY KEY,
            batch_no VARCHAR(50) NOT NULL UNIQUE,
            processing_date DATE NOT NULL,
            ffb_input_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('pending', 'processing', 'completed', 'cancelled') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(100),
            INDEX idx_processing_date (processing_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "✓ Created mill_processing_batch table\n";
        
        // Insert sample batches
        echo "Inserting sample batches...\n";
        $result = $db->exec("INSERT INTO mill_processing_batch (batch_no, processing_date, ffb_input_kg, status, created_by) VALUES
        ('BATCH-2024-001', CURDATE() - INTERVAL 30 DAY, 50000, 'completed', 'system'),
        ('BATCH-2024-002', CURDATE() - INTERVAL 29 DAY, 48000, 'completed', 'system'),
        ('BATCH-2024-003', CURDATE() - INTERVAL 28 DAY, 52000, 'completed', 'system'),
        ('BATCH-2024-004', CURDATE() - INTERVAL 27 DAY, 49000, 'completed', 'system'),
        ('BATCH-2024-005', CURDATE() - INTERVAL 26 DAY, 51000, 'completed', 'system'),
        ('BATCH-2024-006', CURDATE() - INTERVAL 25 DAY, 47000, 'completed', 'system'),
        ('BATCH-2024-007', CURDATE() - INTERVAL 24 DAY, 53000, 'completed', 'system'),
        ('BATCH-2024-008', CURDATE() - INTERVAL 23 DAY, 50500, 'completed', 'system'),
        ('BATCH-2024-009', CURDATE() - INTERVAL 22 DAY, 48500, 'completed', 'system'),
        ('BATCH-2024-010', CURDATE() - INTERVAL 21 DAY, 51500, 'completed', 'system'),
        ('BATCH-2024-011', CURDATE() - INTERVAL 20 DAY, 49500, 'completed', 'system'),
        ('BATCH-2024-012', CURDATE() - INTERVAL 19 DAY, 52500, 'completed', 'system'),
        ('BATCH-2024-013', CURDATE() - INTERVAL 18 DAY, 50000, 'completed', 'system'),
        ('BATCH-2024-014', CURDATE() - INTERVAL 17 DAY, 48000, 'completed', 'system'),
        ('BATCH-2024-015', CURDATE() - INTERVAL 16 DAY, 51000, 'completed', 'system'),
        ('BATCH-2024-016', CURDATE() - INTERVAL 15 DAY, 49000, 'completed', 'system'),
        ('BATCH-2024-017', CURDATE() - INTERVAL 14 DAY, 52000, 'completed', 'system'),
        ('BATCH-2024-018', CURDATE() - INTERVAL 13 DAY, 50500, 'completed', 'system'),
        ('BATCH-2024-019', CURDATE() - INTERVAL 12 DAY, 48500, 'completed', 'system'),
        ('BATCH-2024-020', CURDATE() - INTERVAL 11 DAY, 51500, 'completed', 'system'),
        ('BATCH-2024-021', CURDATE() - INTERVAL 10 DAY, 49500, 'completed', 'system'),
        ('BATCH-2024-022', CURDATE() - INTERVAL 9 DAY, 52500, 'completed', 'system'),
        ('BATCH-2024-023', CURDATE() - INTERVAL 8 DAY, 50000, 'completed', 'system'),
        ('BATCH-2024-024', CURDATE() - INTERVAL 7 DAY, 48000, 'completed', 'system'),
        ('BATCH-2024-025', CURDATE() - INTERVAL 6 DAY, 51000, 'completed', 'system'),
        ('BATCH-2024-026', CURDATE() - INTERVAL 5 DAY, 49000, 'completed', 'system'),
        ('BATCH-2024-027', CURDATE() - INTERVAL 4 DAY, 52000, 'completed', 'system'),
        ('BATCH-2024-028', CURDATE() - INTERVAL 3 DAY, 50500, 'completed', 'system'),
        ('BATCH-2024-029', CURDATE() - INTERVAL 2 DAY, 48500, 'completed', 'system'),
        ('BATCH-2024-030', CURDATE() - INTERVAL 1 DAY, 51500, 'completed', 'system')");
        echo "✓ Inserted $result sample batches\n";
        
        // Verify insertion
        $batch_count = $db->query("SELECT COUNT(*) FROM mill_processing_batch")->fetchColumn();
        echo "✓ Verified: $batch_count batches in database\n\n";
    }
    
    // Check if mill_production table exists
    $tables = $db->query("SHOW TABLES LIKE 'mill_production'")->fetchAll();
    if (empty($tables)) {
        echo "Creating mill_production table...\n";
        
        $db->exec("CREATE TABLE IF NOT EXISTS mill_production (
            production_id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            production_date DATE NOT NULL,
            cpo_produced_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
            kernel_produced_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
            fiber_produced_kg DECIMAL(12,2) DEFAULT 0,
            shell_produced_kg DECIMAL(12,2) DEFAULT 0,
            empty_bunches_kg DECIMAL(12,2) DEFAULT 0,
            oil_extraction_rate DECIMAL(5,2),
            kernel_extraction_rate DECIMAL(5,2),
            moisture_content DECIMAL(5,2),
            ffa_percentage DECIMAL(5,2),
            quality_grade VARCHAR(50),
            storage_tank VARCHAR(50),
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by VARCHAR(100),
            updated_by VARCHAR(100),
            INDEX idx_production_date (production_date),
            INDEX idx_batch_id (batch_id),
            INDEX idx_quality_grade (quality_grade)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "✓ Created mill_production table\n\n";
    }
    
    // Clear existing production data
    $existing = $db->query("SELECT COUNT(*) FROM mill_production")->fetchColumn();
    if ($existing > 0) {
        echo "Found $existing existing production records\n";
        echo "Clearing old data...\n";
        $db->exec("DELETE FROM mill_production");
        echo "✓ Cleared old production data\n\n";
    }
    
    // Generate production data with realistic variations
    echo "Generating production data...\n";
    
    $db->exec("INSERT INTO mill_production 
    (batch_id, production_date, cpo_produced_kg, kernel_produced_kg, 
     fiber_produced_kg, shell_produced_kg, empty_bunches_kg,
     oil_extraction_rate, kernel_extraction_rate, moisture_content, 
     ffa_percentage, quality_grade, storage_tank, remarks, created_by)
    SELECT 
        batch_id,
        processing_date,
        ROUND(ffb_input_kg * (0.18 + RAND() * 0.04), 2) as cpo_produced_kg,  -- 18-22% OER
        ROUND(ffb_input_kg * (0.04 + RAND() * 0.02), 2) as kernel_produced_kg,  -- 4-6% KER
        ROUND(ffb_input_kg * (0.12 + RAND() * 0.02), 2) as fiber_produced_kg,  -- 12-14% fiber
        ROUND(ffb_input_kg * (0.05 + RAND() * 0.02), 2) as shell_produced_kg,  -- 5-7% shell
        ROUND(ffb_input_kg * (0.22 + RAND() * 0.02), 2) as empty_bunches_kg,  -- 22-24% EFB
        ROUND(18 + RAND() * 4, 2) as oil_extraction_rate,  -- 18-22%
        ROUND(4 + RAND() * 2, 2) as kernel_extraction_rate,  -- 4-6%
        ROUND(0.10 + RAND() * 0.10, 2) as moisture_content,  -- 0.10-0.20%
        ROUND(3.0 + RAND() * 2.0, 2) as ffa_percentage,  -- 3.0-5.0%
        CASE
            WHEN FLOOR(RAND() * 10) = 0 THEN 'Premium'
            WHEN FLOOR(RAND() * 10) IN (1, 2, 3) THEN 'Grade A'
            WHEN FLOOR(RAND() * 10) IN (4, 5, 6) THEN 'Grade B'
            ELSE 'Grade C'
        END as quality_grade,
        CONCAT('TANK-', LPAD(FLOOR(1 + RAND() * 8), 3, '0')) as storage_tank,
        'Auto-generated sample production data' as remarks,
        'system' as created_by
    FROM mill_processing_batch
    WHERE status = 'completed'
    ORDER BY processing_date");
    
    $count = $db->query("SELECT ROW_COUNT()")->fetchColumn();
    echo "✓ Generated $count production records\n\n";
    
    // Display summary statistics
    echo "========================================\n";
    echo "PRODUCTION SUMMARY\n";
    echo "========================================\n\n";
    
    $summary = $db->query("
        SELECT 
            COUNT(*) as total_records,
            MIN(production_date) as first_date,
            MAX(production_date) as last_date,
            ROUND(SUM(cpo_produced_kg), 2) as total_cpo_kg,
            ROUND(SUM(kernel_produced_kg), 2) as total_kernel_kg,
            ROUND(AVG(oil_extraction_rate), 2) as avg_oer,
            ROUND(AVG(kernel_extraction_rate), 2) as avg_ker,
            ROUND(AVG(ffa_percentage), 2) as avg_ffa
        FROM mill_production
    ")->fetch(PDO::FETCH_ASSOC);
    
    echo "Total Records: " . $summary['total_records'] . "\n";
    echo "Date Range: " . $summary['first_date'] . " to " . $summary['last_date'] . "\n";
    echo "Total CPO: " . number_format($summary['total_cpo_kg']) . " kg (" . number_format($summary['total_cpo_kg']/1000, 2) . " MT)\n";
    echo "Total Kernel: " . number_format($summary['total_kernel_kg']) . " kg (" . number_format($summary['total_kernel_kg']/1000, 2) . " MT)\n";
    echo "Average OER: " . $summary['avg_oer'] . "%\n";
    echo "Average KER: " . $summary['avg_ker'] . "%\n";
    echo "Average FFA: " . $summary['avg_ffa'] . "%\n\n";
    
    // Quality grade distribution
    echo "Quality Grade Distribution:\n";
    $grades = $db->query("
        SELECT quality_grade, COUNT(*) as count, 
               ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM mill_production), 1) as percentage
        FROM mill_production
        GROUP BY quality_grade
        ORDER BY quality_grade
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($grades as $grade) {
        echo "  " . $grade['quality_grade'] . ": " . $grade['count'] . " (" . $grade['percentage'] . "%)\n";
    }
    
    echo "\n========================================\n";
    echo "✓✓✓ SUCCESS! Mill production data generated!\n";
    echo "========================================\n";
    echo "\nYou can now view production data in:\n";
    echo "- Mill production reports\n";
    echo "- CPO inventory (linked via production_id)\n";
    echo "- Kernel inventory (linked via production_id)\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre>";
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString();
    echo "</pre>";
}
?>

// Powered by IBM Bob
