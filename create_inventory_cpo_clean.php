<?php
/**
 * This script creates a clean version of inventory_cpo_debug.php
 * by removing all debug echo statements and comments
 */

echo "<h1>Creating Clean inventory_cpo.php</h1>";
echo "<hr>";

// Read the debug file
$debug_file = 'inventory_cpo_debug.php';
$clean_file = 'inventory_cpo_clean.php';

if (!file_exists($debug_file)) {
    die("Error: $debug_file not found!");
}

echo "Reading $debug_file...<br>";
$content = file_get_contents($debug_file);

// Remove debug comments at the top
$content = preg_replace('/\/\*\*\s*\*\s*DEBUG VERSION.*?\*\/\s*/s', '', $content);

// Remove error reporting lines
$content = preg_replace('/error_reporting\(E_ALL\);?\s*/', '', $content);
$content = preg_replace('/ini_set\([\'"]display_errors[\'"],\s*1\);?\s*/', '', $content);
$content = preg_replace('/ini_set\([\'"]log_errors[\'"],\s*1\);?\s*/', '', $content);

// Remove all echo statements with "Step" or debug markers
$content = preg_replace('/echo\s+"<!--\s*DEBUG\s*START\s*-->\\\\n";?\s*/', '', $content);
$content = preg_replace('/echo\s+"<!--\s*DEBUG\s*END\s*-->\\\\n\\\\n";?\s*/', '', $content);
$content = preg_replace('/echo\s+"Step\s+\d+:.*?";?\s*/', '', $content);
$content = preg_replace('/echo\s+"✓.*?";?\s*/', '', $content);
$content = preg_replace('/echo\s+"✗.*?";?\s*/', '', $content);
$content = preg_replace('/echo\s+"⚠.*?";?\s*/', '', $content);

// Remove flush() calls
$content = preg_replace('/flush\(\);?\s*/', '', $content);

// Remove try-catch wrappers that were only for debugging
// Keep the actual require_once statements
$content = preg_replace('/try\s*\{\s*(require_once[^;]+;)\s*\}\s*catch\s*\([^)]+\)\s*\{[^}]+\}/', '$1', $content);

// Remove empty lines (more than 2 consecutive)
$content = preg_replace('/\n{3,}/', "\n\n", $content);

// Change title from DEBUG MODE to normal
$content = str_replace('(DEBUG MODE)', '', $content);
$content = str_replace('CPO Inventory Report (DEBUG MODE)', 'CPO Inventory Report', $content);

// Remove debug alert boxes
$content = preg_replace('/<div class="alert alert-success">.*?<\/div>/s', '', $content);
$content = preg_replace('/<div class="alert alert-info.*?<\/div>/s', '', $content);

// Write clean file
file_put_contents($clean_file, $content);

echo "✓ Clean file created: $clean_file<br>";
echo "File size: " . filesize($clean_file) . " bytes<br>";
echo "<hr>";
echo "<h3>Next Steps:</h3>";
echo "<ol>";
echo "<li>Upload <strong>$clean_file</strong> to your server</li>";
echo "<li>Test it at: https://inodesain.com/agro/$clean_file</li>";
echo "<li>If it works, rename it to inventory_cpo.php</li>";
echo "</ol>";
echo "<br>";
echo "<a href='$clean_file' target='_blank'>View Clean File</a>";
?>

// Powered by IBM Bob
