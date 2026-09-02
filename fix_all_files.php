<?php
/**
 * Batch File Fixer
 * This script will help identify and fix all corrupted PHP files
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>PHP Files Checker & Fixer</h1>";
echo "<p>This will check all PHP files and identify which ones might be corrupted.</p>";
echo "<hr>";

// List of main PHP files to check
$files_to_check = [
    'index.php',
    'companies.php',
    'business_units.php',
    'divisions.php',
    'blocks.php',
    'planting_years.php',
    'plant_varieties.php',
    'workers.php',
    'activities.php',
    'harvest_plans.php',
    'harvest_realizations.php',
    'harvest_productivity.php',
    'harvest_quality.php',
    'ffb_delivery.php',
    'mill_processing.php',
    'mill_production.php',
    'mill_quality.php',
    'inventory_cpo.php',
    'inventory_kernel.php',
    'inventory_materials.php',
    'journal_entries.php',
    'gl_accounts.php',
    'financial_reports.php',
    'block_costing.php',
    'budget.php',
    'sales.php',
    'reports.php',
    'analytics.php',
    'dashboard_kpi.php'
];

$working_files = [];
$broken_files = [];
$missing_files = [];

echo "<h3>Checking Files...</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #f0f0f0;'>";
echo "<th>File</th><th>Status</th><th>Size</th><th>Test Result</th><th>Action</th>";
echo "</tr>";

foreach ($files_to_check as $file) {
    echo "<tr>";
    echo "<td><strong>$file</strong></td>";
    
    if (!file_exists($file)) {
        echo "<td style='color: orange;'>⚠ Missing</td>";
        echo "<td>-</td>";
        echo "<td>-</td>";
        echo "<td>Upload from local</td>";
        $missing_files[] = $file;
    } else {
        $size = filesize($file);
        $size_kb = round($size / 1024, 2);
        
        echo "<td style='color: green;'>✓ Exists</td>";
        echo "<td>{$size_kb} KB</td>";
        
        // Try to include the file in a test
        ob_start();
        $error = null;
        try {
            // Just check if it's valid PHP
            $content = file_get_contents($file);
            if (substr($content, 0, 5) !== '<?php') {
                $error = "Not a valid PHP file";
            }
            
            // Check for BOM
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                $error = "Has BOM (Byte Order Mark)";
            }
            
            // Try to parse it
            $tokens = @token_get_all($content);
            if ($tokens === false) {
                $error = "Parse error";
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
        ob_end_clean();
        
        if ($error) {
            echo "<td style='color: red;'>✗ $error</td>";
            echo "<td><a href='?fix=$file' style='color: blue;'>Create Fix</a></td>";
            $broken_files[] = $file;
        } else {
            echo "<td style='color: green;'>✓ OK</td>";
            echo "<td>-</td>";
            $working_files[] = $file;
        }
    }
    
    echo "</tr>";
}

echo "</table>";

echo "<hr>";
echo "<h3>Summary:</h3>";
echo "<ul>";
echo "<li><strong style='color: green;'>Working files:</strong> " . count($working_files) . "</li>";
echo "<li><strong style='color: red;'>Broken files:</strong> " . count($broken_files) . "</li>";
echo "<li><strong style='color: orange;'>Missing files:</strong> " . count($missing_files) . "</li>";
echo "</ul>";

if (!empty($broken_files)) {
    echo "<div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107;'>";
    echo "<h4>Broken Files Detected:</h4>";
    echo "<p>These files exist but have issues (corruption, BOM, encoding problems):</p>";
    echo "<ul>";
    foreach ($broken_files as $file) {
        echo "<li><strong>$file</strong> - Re-upload using Binary mode in FTP</li>";
    }
    echo "</ul>";
    echo "</div>";
}

if (!empty($missing_files)) {
    echo "<div style='background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; margin-top: 15px;'>";
    echo "<h4>Missing Files:</h4>";
    echo "<p>These files need to be uploaded:</p>";
    echo "<ul>";
    foreach ($missing_files as $file) {
        echo "<li><strong>$file</strong></li>";
    }
    echo "</ul>";
    echo "</div>";
}

// Handle fix request
if (isset($_GET['fix']) && in_array($_GET['fix'], $broken_files)) {
    $file_to_fix = $_GET['fix'];
    echo "<hr>";
    echo "<div style='background: #d1ecf1; padding: 15px; border-left: 4px solid #0c5460;'>";
    echo "<h4>Fix Instructions for: $file_to_fix</h4>";
    echo "<ol>";
    echo "<li>On your local computer, go to: <code>c:\\xampp\\htdocs\\agro\\$file_to_fix</code></li>";
    echo "<li>Open your FTP client (FileZilla)</li>";
    echo "<li>Set transfer mode to <strong>Binary</strong> (not ASCII)</li>";
    echo "<li>Delete the file from server: <code>/public_html/agro/$file_to_fix</code></li>";
    echo "<li>Upload fresh copy from local</li>";
    echo "<li>Verify file size matches</li>";
    echo "<li>Refresh this page to re-check</li>";
    echo "</ol>";
    echo "</div>";
}

echo "<hr>";
echo "<h3>Quick Fix for blocks.php:</h3>";
echo "<p>Since blocks.php has the same issue as divisions.php, you can:</p>";
echo "<ol>";
echo "<li>Delete <code>blocks.php</code> from server</li>";
echo "<li>Re-upload from local using <strong>Binary mode</strong></li>";
echo "<li>Or create a test version like we did with divisions</li>";
echo "</ol>";

echo "<hr>";
echo "<p><a href='fix_all_files.php'>↻ Refresh Check</a> | <a href='index.php'>← Back to Dashboard</a></p>";
?>

// Powered by IBM Bob
