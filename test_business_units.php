<?php
/**
 * Diagnostic Script for Business Units Page
 * This will help identify the issue causing blank page
 */

// Enable error reporting temporarily
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>Business Units Diagnostic Test</h1>";
echo "<hr>";

// Test 1: Check if config files exist
echo "<h3>Test 1: Config Files</h3>";
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

// Test 2: Try to include config
echo "<h3>Test 2: Database Connection</h3>";
try {
    require_once 'config/database.php';
    echo "✓ Database config loaded<br>";
    
    $db = getDB();
    echo "✓ Database connection successful<br>";
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "<br>";
    die();
}

// Test 3: Check if business_units table exists
echo "<h3>Test 3: Business Units Table</h3>";
try {
    $stmt = $db->query("SHOW TABLES LIKE 'business_units'");
    $table_exists = $stmt->fetch();
    
    if ($table_exists) {
        echo "✓ business_units table exists<br>";
        
        // Count records
        $stmt = $db->query("SELECT COUNT(*) as count FROM business_units");
        $count = $stmt->fetch()['count'];
        echo "✓ Found $count business units in database<br>";
    } else {
        echo "✗ business_units table NOT FOUND<br>";
    }
} catch (PDOException $e) {
    echo "✗ Table check error: " . $e->getMessage() . "<br>";
}

// Test 4: Check companies table
echo "<h3>Test 4: Companies Table</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM companies");
    $count = $stmt->fetch()['count'];
    echo "✓ Found $count companies in database<br>";
} catch (PDOException $e) {
    echo "✗ Companies table error: " . $e->getMessage() . "<br>";
}

// Test 5: Check workers table
echo "<h3>Test 5: Workers Table</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM workers");
    $count = $stmt->fetch()['count'];
    echo "✓ Found $count workers in database<br>";
} catch (PDOException $e) {
    echo "✗ Workers table error: " . $e->getMessage() . "<br>";
}

// Test 6: Try to load functions.php
echo "<h3>Test 6: Functions File</h3>";
try {
    require_once 'includes/functions.php';
    echo "✓ functions.php loaded successfully<br>";
    
    // Test if key functions exist
    if (function_exists('is_post')) {
        echo "✓ is_post() function exists<br>";
    } else {
        echo "✗ is_post() function NOT FOUND<br>";
    }
    
    if (function_exists('get_status_badge')) {
        echo "✓ get_status_badge() function exists<br>";
    } else {
        echo "✗ get_status_badge() function NOT FOUND<br>";
    }
    
    if (function_exists('format_number')) {
        echo "✓ format_number() function exists<br>";
    } else {
        echo "✗ format_number() function NOT FOUND<br>";
    }
} catch (Exception $e) {
    echo "✗ Functions error: " . $e->getMessage() . "<br>";
}

// Test 7: Try the actual query from business_units.php
echo "<h3>Test 7: Business Units Query</h3>";
try {
    $sql = "SELECT bu.*, c.company_name, c.company_code,
            COALESCE(bu.total_area_ha, 0) + COALESCE(bu.forestry_area_ha, 0) as combined_total_area_ha,
            COALESCE(bu.total_plants, 0) as total_plants,
            COALESCE(bu.forestry_area_ha, 0) as forestry_area_ha,
            COALESCE(bu.total_volume_m3, 0) as total_volume_m3,
            COALESCE(bu.total_carbon_stock_ton, 0) as total_carbon_stock_ton,
            COALESCE(bu.forestry_blocks, 0) as forestry_blocks,
            (SELECT COUNT(*) FROM divisions d WHERE d.business_unit_id = bu.business_unit_id AND d.parent_division_id IS NULL) as total_divisions,
            (SELECT COUNT(*) FROM blocks b
             INNER JOIN divisions d ON b.division_id = d.division_id
             WHERE d.business_unit_id = bu.business_unit_id) as total_blocks,
            (SELECT COUNT(*) FROM blocks b
             INNER JOIN divisions d ON b.division_id = d.division_id
             WHERE d.business_unit_id = bu.business_unit_id AND b.operation_type = 'Plantation' AND b.status = 'TM') as tm_blocks,
            (SELECT COUNT(*) FROM blocks b
             INNER JOIN divisions d ON b.division_id = d.division_id
             WHERE d.business_unit_id = bu.business_unit_id AND b.operation_type = 'Plantation' AND b.status = 'TBM') as tbm_blocks
            FROM business_units bu
            INNER JOIN companies c ON bu.company_id = c.company_id
            WHERE 1=1
            ORDER BY c.company_name, bu.unit_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $business_units = $stmt->fetchAll();
    
    echo "✓ Query executed successfully<br>";
    echo "✓ Found " . count($business_units) . " business units<br>";
    
    if (count($business_units) > 0) {
        echo "<br><strong>Sample data (first record):</strong><br>";
        echo "<pre>";
        print_r($business_units[0]);
        echo "</pre>";
    }
} catch (PDOException $e) {
    echo "✗ Query error: " . $e->getMessage() . "<br>";
    echo "<br><strong>SQL State:</strong> " . $e->getCode() . "<br>";
}

// Test 8: Check if divisions table exists
echo "<h3>Test 8: Divisions Table</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM divisions");
    $count = $stmt->fetch()['count'];
    echo "✓ Found $count divisions in database<br>";
} catch (PDOException $e) {
    echo "✗ Divisions table error: " . $e->getMessage() . "<br>";
}

// Test 9: Check if blocks table exists
echo "<h3>Test 9: Blocks Table</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM blocks");
    $count = $stmt->fetch()['count'];
    echo "✓ Found $count blocks in database<br>";
} catch (PDOException $e) {
    echo "✗ Blocks table error: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<h3>Conclusion</h3>";
echo "If all tests passed above, the issue might be:<br>";
echo "1. PHP memory limit too low<br>";
echo "2. PHP execution time limit<br>";
echo "3. Missing PHP extensions (PDO, PDO_MySQL)<br>";
echo "4. File permissions issue<br>";
echo "<br>";
echo "<strong>Next Steps:</strong><br>";
echo "1. Check PHP error log at: /home/your_username/logs/php_error.log<br>";
echo "2. Check Apache error log in your hosting control panel<br>";
echo "3. Try accessing: <a href='business_units.php'>business_units.php</a><br>";
echo "<br>";
echo "<a href='index.php'>← Back to Dashboard</a>";
?>

// Powered by IBM Bob
