<?php
/**
 * DEBUG VERSION of divisions.php
 * This will show exactly where the code fails
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<!-- DEBUG START -->\n";
echo "Step 1: Script started<br>\n";
flush();

// Step 2: Include config
echo "Step 2: Including config/database.php...<br>\n";
flush();
try {
    require_once 'config/database.php';
    echo "✓ Config loaded successfully<br>\n";
    flush();
} catch (Exception $e) {
    die("✗ ERROR loading config: " . $e->getMessage());
}

// Step 3: Include functions
echo "Step 3: Including includes/functions.php...<br>\n";
flush();
try {
    require_once 'includes/functions.php';
    echo "✓ Functions loaded successfully<br>\n";
    flush();
} catch (Exception $e) {
    die("✗ ERROR loading functions: " . $e->getMessage());
}

// Step 4: Get database connection
echo "Step 4: Getting database connection...<br>\n";
flush();
try {
    $db = getDB();
    echo "✓ Database connected<br>\n";
    flush();
} catch (Exception $e) {
    die("✗ ERROR connecting to database: " . $e->getMessage());
}

// Step 5: Check if POST
echo "Step 5: Checking for POST data...<br>\n";
flush();
if (is_post()) {
    echo "POST detected, action: " . post('action') . "<br>\n";
    flush();
} else {
    echo "No POST data<br>\n";
    flush();
}

// Step 6: Check for edit mode
echo "Step 6: Checking for edit mode...<br>\n";
flush();
$edit_division = null;
if (get('action') == 'edit' && get('id')) {
    echo "Edit mode detected, ID: " . get('id') . "<br>\n";
    flush();
    try {
        $stmt = $db->prepare("SELECT * FROM divisions WHERE division_id = :id");
        $stmt->execute([':id' => get('id')]);
        $edit_division = $stmt->fetch();
        echo "✓ Division loaded for editing<br>\n";
        flush();
    } catch (PDOException $e) {
        echo "✗ ERROR loading division: " . $e->getMessage() . "<br>\n";
        flush();
    }
} else {
    echo "Not in edit mode<br>\n";
    flush();
}

// Step 7: Set page title
echo "Step 7: Setting page title...<br>\n";
flush();
$page_title = "Divisions Management";
echo "✓ Page title set<br>\n";
flush();

// Step 8: Include header
echo "Step 8: Including header...<br>\n";
flush();
try {
    require_once 'includes/header.php';
    echo "✓ Header included<br>\n";
    flush();
} catch (Exception $e) {
    die("✗ ERROR loading header: " . $e->getMessage());
}

// Step 9: Fetch business units
echo "Step 9: Fetching business units...<br>\n";
flush();
try {
    $business_units_stmt = $db->query("
        SELECT bu.business_unit_id, bu.unit_code, bu.unit_name, c.company_name
        FROM business_units bu
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE bu.status = 'Active'
        ORDER BY c.company_name, bu.unit_name
    ");
    $business_units = $business_units_stmt->fetchAll();
    echo "✓ Fetched " . count($business_units) . " business units<br>\n";
    flush();
} catch (PDOException $e) {
    die("✗ ERROR fetching business units: " . $e->getMessage());
}

// Step 10: Fetch workers
echo "Step 10: Fetching workers...<br>\n";
flush();
try {
    $workers_stmt = $db->query("
        SELECT id, employee_code, full_name, position
        FROM workers
        WHERE status = 'active'
        ORDER BY full_name
    ");
    $workers = $workers_stmt->fetchAll();
    echo "✓ Fetched " . count($workers) . " workers<br>\n";
    flush();
} catch (PDOException $e) {
    echo "⚠ WARNING fetching workers: " . $e->getMessage() . "<br>\n";
    $workers = []; // Continue with empty array
    flush();
}

// Step 11: Get filter parameters
echo "Step 11: Getting filter parameters...<br>\n";
flush();
$search = get('search', '');
$business_unit_filter = get('business_unit_id', '');
$status_filter = get('status', '');
echo "✓ Filters: search='$search', business_unit='$business_unit_filter', status='$status_filter'<br>\n";
flush();

// Step 12: Build and execute main query
echo "Step 12: Building main divisions query...<br>\n";
flush();
try {
    $sql = "SELECT d.*,
            bu.unit_code, bu.unit_name, bu.unit_type,
            c.company_name, c.company_code,
            (SELECT COUNT(DISTINCT py.planting_year_id) FROM planting_years py WHERE py.division_id = d.division_id) as total_planting_years,
            (SELECT COUNT(*) FROM blocks b WHERE b.division_id = d.division_id) as total_blocks,
            COALESCE(d.total_area, 0) + COALESCE(d.forestry_area_ha, 0) as total_area_ha,
            COALESCE(d.total_plants, 0) as total_plants,
            (SELECT COUNT(*) FROM blocks b WHERE b.division_id = d.division_id AND b.operation_type = 'Plantation' AND b.status = 'TM') as tm_blocks,
            (SELECT COUNT(*) FROM blocks b WHERE b.division_id = d.division_id AND b.operation_type = 'Plantation' AND b.status = 'TBM') as tbm_blocks,
            COALESCE(d.forestry_blocks, 0) as forestry_blocks,
            COALESCE(d.forestry_area_ha, 0) as forestry_area_ha,
            COALESCE(d.total_area, 0) as plantation_area_ha
            FROM divisions d
            INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
            INNER JOIN companies c ON bu.company_id = c.company_id
            WHERE 1=1";
    
    if ($search) {
        $sql .= " AND (d.division_code LIKE :search1 OR d.division_name LIKE :search2)";
    }
    if ($business_unit_filter) {
        $sql .= " AND d.business_unit_id = :business_unit_id";
    }
    if ($status_filter) {
        $sql .= " AND d.status = :status";
    }
    
    $sql .= " ORDER BY c.company_name, bu.unit_name, d.division_code";
    
    echo "✓ Query built<br>\n";
    flush();
    
    $stmt = $db->prepare($sql);
    if ($search) {
        $stmt->bindValue(':search1', "%$search%");
        $stmt->bindValue(':search2', "%$search%");
    }
    if ($business_unit_filter) {
        $stmt->bindValue(':business_unit_id', $business_unit_filter);
    }
    if ($status_filter) {
        $stmt->bindValue(':status', $status_filter);
    }
    
    echo "Step 13: Executing query...<br>\n";
    flush();
    
    $stmt->execute();
    $divisions = $stmt->fetchAll();
    
    echo "✓ Query executed successfully<br>\n";
    echo "✓ Found " . count($divisions) . " divisions<br>\n";
    flush();
    
} catch (PDOException $e) {
    die("✗ ERROR in main query: " . $e->getMessage() . "<br>SQL: " . $sql);
}

// Step 14: Calculate statistics
echo "Step 14: Calculating statistics...<br>\n";
flush();
try {
    $top_level_divisions = array_filter($divisions, function($div) {
        return empty($div['parent_division_id']);
    });
    
    $total_divisions = count($divisions);
    $total_planting_years = array_sum(array_column($top_level_divisions, 'total_planting_years'));
    $total_blocks = array_sum(array_column($top_level_divisions, 'total_blocks'));
    $total_area = array_sum(array_column($top_level_divisions, 'total_area_ha'));
    $total_plants = array_sum(array_column($top_level_divisions, 'total_plants'));
    
    echo "✓ Statistics calculated<br>\n";
    echo "Total divisions: $total_divisions<br>\n";
    echo "Total planting years: $total_planting_years<br>\n";
    echo "Total blocks: $total_blocks<br>\n";
    flush();
} catch (Exception $e) {
    die("✗ ERROR calculating statistics: " . $e->getMessage());
}

echo "Step 15: Rendering page content...<br>\n";
echo "<!-- DEBUG END -->\n\n";
flush();

// Now render the actual page
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-grid-3x3"></i> Divisions Management (DEBUG MODE)</h1>
            <p class="text-muted">Manage divisions (Afdeling) within business units</p>
        </div>
        <div class="col-auto">
            <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-house"></i> Dashboard
            </a>
        </div>
    </div>
</div>

<div class="alert alert-success">
    <strong>✓ Page loaded successfully!</strong><br>
    All steps completed without errors.<br>
    Found <?php echo $total_divisions; ?> divisions in database.
</div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Divisions List (<?php echo count($divisions); ?> records)
    </div>
    <div class="card-body">
        <?php if (empty($divisions)): ?>
            <p class="text-center text-muted">No divisions found</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Business Unit</th>
                        <th>Blocks</th>
                        <th>Area (Ha)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($divisions as $division): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($division['division_code']); ?></td>
                            <td><?php echo htmlspecialchars($division['division_name']); ?></td>
                            <td><?php echo htmlspecialchars($division['unit_name']); ?></td>
                            <td><?php echo $division['total_blocks']; ?></td>
                            <td><?php echo number_format($division['total_area_ha'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info mt-3">
    <strong>Debug Information:</strong><br>
    - Business Units: <?php echo count($business_units); ?><br>
    - Workers: <?php echo count($workers); ?><br>
    - Divisions: <?php echo count($divisions); ?><br>
    - Top Level Divisions: <?php echo count($top_level_divisions); ?><br>
</div>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
