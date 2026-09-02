<?php
/**
 * Diagnostic Script for Divisions Page
 * This will help identify the issue causing blank page or 404
 */

// Enable error reporting temporarily
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>Divisions Page Diagnostic Test</h1>";
echo "<hr>";

// Test 1: Check if divisions.php file exists
echo "<h3>Test 1: File Existence</h3>";
if (file_exists('divisions.php')) {
    echo "✓ divisions.php exists<br>";
    echo "File size: " . filesize('divisions.php') . " bytes<br>";
    echo "File permissions: " . substr(sprintf('%o', fileperms('divisions.php')), -4) . "<br>";
} else {
    echo "✗ divisions.php NOT FOUND<br>";
    echo "<strong>This is the problem!</strong> The file was not uploaded correctly.<br>";
}

// Test 2: Check if config files exist
echo "<h3>Test 2: Config Files</h3>";
if (file_exists('config/database.php')) {
    echo "✓ config/database.php exists<br>";
} else {
    echo "✗ config/database.php NOT FOUND<br>";
}

if (file_exists('includes/functions.php')) {
    echo "✓ includes/functions.php exists<br>";
} else {
    echo "✗ includes/functions.php NOT FOUND<br>";
}

if (file_exists('includes/header.php')) {
    echo "✓ includes/header.php exists<br>";
} else {
    echo "✗ includes/header.php NOT FOUND<br>";
}

// Test 3: Database Connection
echo "<h3>Test 3: Database Connection</h3>";
try {
    require_once 'config/database.php';
    echo "✓ Database config loaded<br>";
    
    $db = getDB();
    echo "✓ Database connection successful<br>";
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "<br>";
    die();
}

// Test 4: Check divisions table
echo "<h3>Test 4: Divisions Table</h3>";
try {
    $stmt = $db->query("SHOW TABLES LIKE 'divisions'");
    $table_exists = $stmt->fetch();
    
    if ($table_exists) {
        echo "✓ divisions table exists<br>";
        
        // Count records
        $stmt = $db->query("SELECT COUNT(*) as count FROM divisions");
        $count = $stmt->fetch()['count'];
        echo "✓ Found $count divisions in database<br>";
        
        // Show table structure
        echo "<br><strong>Table Structure:</strong><br>";
        $stmt = $db->query("DESCRIBE divisions");
        $columns = $stmt->fetchAll();
        echo "<pre>";
        foreach ($columns as $col) {
            echo $col['Field'] . " (" . $col['Type'] . ")<br>";
        }
        echo "</pre>";
    } else {
        echo "✗ divisions table NOT FOUND<br>";
    }
} catch (PDOException $e) {
    echo "✗ Table check error: " . $e->getMessage() . "<br>";
}

// Test 5: Check business_units table
echo "<h3>Test 5: Business Units Table</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM business_units");
    $count = $stmt->fetch()['count'];
    echo "✓ Found $count business units in database<br>";
} catch (PDOException $e) {
    echo "✗ Business units table error: " . $e->getMessage() . "<br>";
}

// Test 6: Check blocks table
echo "<h3>Test 6: Blocks Table</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM blocks");
    $count = $stmt->fetch()['count'];
    echo "✓ Found $count blocks in database<br>";
} catch (PDOException $e) {
    echo "✗ Blocks table error: " . $e->getMessage() . "<br>";
}

// Test 7: Try a sample query from divisions.php
echo "<h3>Test 7: Sample Divisions Query</h3>";
try {
    $sql = "SELECT d.*, bu.unit_name as business_unit_name, bu.unit_code as business_unit_code,
            c.company_name, c.company_code
            FROM divisions d
            INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
            INNER JOIN companies c ON bu.company_id = c.company_id
            ORDER BY c.company_name, bu.unit_name, d.division_name
            LIMIT 5";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $divisions = $stmt->fetchAll();
    
    echo "✓ Query executed successfully<br>";
    echo "✓ Found " . count($divisions) . " divisions (showing first 5)<br>";
    
    if (count($divisions) > 0) {
        echo "<br><strong>Sample data (first record):</strong><br>";
        echo "<pre>";
        print_r($divisions[0]);
        echo "</pre>";
    }
} catch (PDOException $e) {
    echo "✗ Query error: " . $e->getMessage() . "<br>";
}

// Test 8: Check .htaccess
echo "<h3>Test 8: .htaccess File</h3>";
if (file_exists('.htaccess')) {
    echo "✓ .htaccess exists<br>";
    echo "File size: " . filesize('.htaccess') . " bytes<br>";
} else {
    echo "✗ .htaccess NOT FOUND<br>";
}

// Test 9: List all PHP files in root
echo "<h3>Test 9: PHP Files in Root Directory</h3>";
$files = glob('*.php');
echo "Found " . count($files) . " PHP files:<br>";
echo "<ul>";
foreach ($files as $file) {
    $highlight = ($file == 'divisions.php') ? ' <strong style="color: red;">&larr; THIS ONE</strong>' : '';
    echo "<li>$file" . $highlight . "</li>";
}
echo "</ul>";

echo "<hr>";
echo "<h3>Conclusion</h3>";

if (!file_exists('divisions.php')) {
    echo "<div style='background: #ffebee; padding: 15px; border-left: 4px solid #f44336;'>";
    echo "<strong>PROBLEM FOUND:</strong> divisions.php file is missing!<br><br>";
    echo "<strong>Solution:</strong><br>";
    echo "1. Re-upload divisions.php from your local c:\\xampp\\htdocs\\agro\\ directory<br>";
    echo "2. Make sure you're uploading to the correct directory: /public_html/agro/<br>";
    echo "3. Check file permissions after upload (should be 644)<br>";
    echo "4. Clear your browser cache and try again<br>";
    echo "</div>";
} else {
    echo "<div style='background: #e8f5e9; padding: 15px; border-left: 4px solid #4caf50;'>";
    echo "<strong>File exists!</strong> If you still see a blank page:<br><br>";
    echo "1. Check PHP error log in your hosting control panel<br>";
    echo "2. The file might have syntax errors<br>";
    echo "3. Try accessing: <a href='divisions.php'>divisions.php</a><br>";
    echo "</div>";
}

echo "<br>";
echo "<a href='index.php'>← Back to Dashboard</a> | ";
echo "<a href='business_units.php'>Business Units</a>";
?>

// Powered by IBM Bob
