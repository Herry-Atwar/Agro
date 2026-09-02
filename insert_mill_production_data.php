<?php
/**
 * Insert Mill Production Sample Data
 * This script inserts production records based on existing processing batches
 */

require_once 'config/database.php';

try {
    $db = getDB();
    
    echo "Checking mill production data...\n\n";
    
    // Check existing batches
    $batch_count = $db->query("SELECT COUNT(*) FROM mill_processing_batch")->fetchColumn();
    echo "Found $batch_count processing batches\n";
    
    // Check existing production records
    $prod_count = $db->query("SELECT COUNT(*) FROM mill_production")->fetchColumn();
    echo "Found $prod_count production records\n\n";
    
    if ($batch_count == 0) {
        echo "✗ No processing batches found. Please run insert_mill_production_sample_data.sql first.\n";
        exit(1);
    }
    
    if ($prod_count > 0) {
        echo "Production records already exist. Do you want to delete and recreate? (This will show sample data)\n";
        echo "If yes, run: DELETE FROM mill_production; in phpMyAdmin first, then run this script again.\n";
        exit(0);
    }
    
    echo "Inserting production records...\n\n";
    
    // Get sample batch IDs to verify they exist
    $sample_batches = $db->query("
        SELECT batch_id, batch_no, ffb_input_kg 
        FROM mill_processing_batch 
        ORDER BY processing_date 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Sample batches:\n";
    foreach ($sample_batches as $batch) {
        echo "- Batch ID: {$batch['batch_id']}, Batch No: {$batch['batch_no']}, FFB: {$batch['ffb_input_kg']} kg\n";
    }
    echo "\n";
    
    // Insert production records using actual batch_ids
    $sql = "
    INSERT INTO mill_production (
        batch_id,
        production_date,
        cpo_produced_kg,
        kernel_produced_kg,
        fiber_produced_kg,
        shell_produced_kg,
        empty_bunches_kg,
        oil_extraction_rate,
        kernel_extraction_rate,
        moisture_content,
        ffa_percentage,
        quality_grade,
        storage_tank,
        remarks,
        created_by
    )
    SELECT
        b.batch_id,
        b.processing_date,
        ROUND(b.ffb_input_kg * 0.20, 2) as cpo_produced_kg,
        ROUND(b.ffb_input_kg * 0.05, 2) as kernel_produced_kg,
        ROUND(b.ffb_input_kg * 0.13, 2) as fiber_produced_kg,
        ROUND(b.ffb_input_kg * 0.06, 2) as shell_produced_kg,
        ROUND(b.ffb_input_kg * 0.23, 2) as empty_bunches_kg,
        20.00 as oil_extraction_rate,
        5.00 as kernel_extraction_rate,
        CASE
            WHEN MOD(b.batch_id, 4) = 0 THEN 3.60
            WHEN MOD(b.batch_id, 4) = 1 THEN 3.45
            WHEN MOD(b.batch_id, 4) = 2 THEN 3.50
            ELSE 3.55
        END as moisture_content,
        CASE
            WHEN MOD(b.batch_id, 3) = 0 THEN 0.16
            WHEN MOD(b.batch_id, 3) = 1 THEN 0.14
            ELSE 0.15
        END as ffa_percentage,
        CASE
            WHEN MOD(b.batch_id, 5) = 0 THEN 'Premium'
            WHEN MOD(b.batch_id, 3) = 0 THEN 'Grade B'
            ELSE 'Grade A'
        END as quality_grade,
        CONCAT('TANK-00', (MOD(b.batch_id - 1, 4) + 1)) as storage_tank,
        'Sample production data' as remarks,
        'system' as created_by
    FROM mill_processing_batch b
    ORDER BY b.processing_date
    ";
    
    $affected = $db->exec($sql);
    
    echo "✓ Inserted $affected production records\n\n";
    
    // Verify the insertion
    $verification = $db->query("
        SELECT 
            COUNT(*) as total_records,
            SUM(cpo_produced_kg) as total_cpo,
            SUM(kernel_produced_kg) as total_kernel,
            MIN(production_date) as first_date,
            MAX(production_date) as last_date
        FROM mill_production
    ")->fetch(PDO::FETCH_ASSOC);
    
    echo "Verification:\n";
    echo "- Total production records: {$verification['total_records']}\n";
    echo "- Total CPO produced: " . number_format($verification['total_cpo'], 2) . " kg\n";
    echo "- Total Kernel produced: " . number_format($verification['total_kernel'], 2) . " kg\n";
    echo "- Date range: {$verification['first_date']} to {$verification['last_date']}\n\n";
    
    // Show sample records
    echo "Sample production records:\n";
    $samples = $db->query("
        SELECT 
            p.production_id,
            p.production_date,
            b.batch_no,
            p.cpo_produced_kg,
            p.kernel_produced_kg,
            p.quality_grade,
            p.storage_tank
        FROM mill_production p
        JOIN mill_processing_batch b ON p.batch_id = b.batch_id
        ORDER BY p.production_date
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($samples as $sample) {
        echo "- {$sample['production_date']} | {$sample['batch_no']} | CPO: {$sample['cpo_produced_kg']} kg | Kernel: {$sample['kernel_produced_kg']} kg | Grade: {$sample['quality_grade']} | Tank: {$sample['storage_tank']}\n";
    }
    
    echo "\n========================================\n";
    echo "✓ Mill production data inserted successfully!\n";
    echo "========================================\n";
    
} catch (PDOException $e) {
    echo "✗ Database Error: " . $e->getMessage() . "\n";
    echo "\nError Code: " . $e->getCode() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Powered by IBM Bob
