<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Fetch all blocks with GeoJSON data
$sql = "SELECT b.*, 
        py.year as planting_year,
        d.division_code, d.division_name,
        bu.unit_code, bu.unit_name,
        c.company_code, c.company_name
        FROM blocks b
        LEFT JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        LEFT JOIN divisions d ON b.division_id = d.division_id
        LEFT JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        LEFT JOIN companies c ON bu.company_id = c.company_id
        WHERE b.geojson IS NOT NULL AND b.geojson != ''
        ORDER BY c.company_name, bu.unit_name, d.division_name, b.block_code";

$stmt = $db->query($sql);
$blocks = $stmt->fetchAll();

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-bug"></i> Blocks Map Debug Information</h1>
    <p class="text-muted">Debugging information for blocks with GeoJSON data</p>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5>Summary</h5>
    </div>
    <div class="card-body">
        <p><strong>Total blocks with GeoJSON:</strong> <?php echo count($blocks); ?></p>
        <p><strong>SAMPLE blocks:</strong> 
            <?php 
            $sample_count = 0;
            foreach ($blocks as $block) {
                if (strpos($block['block_code'], 'SAMPLE-') === 0) {
                    $sample_count++;
                }
            }
            echo $sample_count;
            ?>
        </p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5>All Blocks with GeoJSON</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm">
                <thead>
                    <tr>
                        <th>Block ID</th>
                        <th>Block Code</th>
                        <th>Block Name</th>
                        <th>Company</th>
                        <th>Business Unit</th>
                        <th>Division</th>
                        <th>Operation Type</th>
                        <th>Status</th>
                        <th>GeoJSON Length</th>
                        <th>GeoJSON Preview</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($blocks as $block): ?>
                    <tr>
                        <td><?php echo $block['block_id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($block['block_code']); ?></strong></td>
                        <td><?php echo htmlspecialchars($block['block_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($block['company_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($block['unit_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($block['division_name'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $block['operation_type'] == 'Plantation' ? 'success' : 'info'; ?>">
                                <?php echo $block['operation_type']; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($block['operation_type'] == 'Plantation'): ?>
                                <span class="badge bg-secondary"><?php echo $block['status']; ?></span>
                            <?php else: ?>
                                <span class="badge bg-dark">Forestry</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo strlen($block['geojson']); ?> chars</td>
                        <td>
                            <small style="font-family: monospace;">
                                <?php echo htmlspecialchars(substr($block['geojson'], 0, 50)); ?>...
                            </small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header">
        <h5>Sample Block Details</h5>
    </div>
    <div class="card-body">
        <?php foreach ($blocks as $block): ?>
            <?php if (strpos($block['block_code'], 'SAMPLE-') === 0): ?>
                <div class="mb-4 p-3 border rounded">
                    <h6><strong><?php echo htmlspecialchars($block['block_code']); ?></strong> - <?php echo htmlspecialchars($block['block_name']); ?></h6>
                    <p><strong>Operation Type:</strong> <?php echo $block['operation_type']; ?></p>
                    <p><strong>Status:</strong> <?php echo $block['status'] ?? 'N/A'; ?></p>
                    <p><strong>GeoJSON (first 200 chars):</strong></p>
                    <pre style="background: #f5f5f5; padding: 10px; border-radius: 5px; font-size: 11px; overflow-x: auto;"><?php echo htmlspecialchars(substr($block['geojson'], 0, 200)); ?>...</pre>
                    
                    <?php
                    // Try to parse GeoJSON
                    $geojson_data = json_decode($block['geojson'], true);
                    if ($geojson_data): ?>
                        <p><strong>✅ GeoJSON is valid JSON</strong></p>
                        <p><strong>Type:</strong> <?php echo $geojson_data['type'] ?? 'Unknown'; ?></p>
                        <?php if (isset($geojson_data['features']) && is_array($geojson_data['features'])): ?>
                            <p><strong>Features count:</strong> <?php echo count($geojson_data['features']); ?></p>
                            <?php if (count($geojson_data['features']) > 0): ?>
                                <p><strong>First feature geometry type:</strong> <?php echo $geojson_data['features'][0]['geometry']['type'] ?? 'Unknown'; ?></p>
                                <?php if (isset($geojson_data['features'][0]['geometry']['coordinates'])): ?>
                                    <p><strong>Coordinates:</strong> <?php echo json_encode($geojson_data['features'][0]['geometry']['coordinates']); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <p><strong>❌ GeoJSON is NOT valid JSON</strong></p>
                        <p><strong>JSON Error:</strong> <?php echo json_last_error_msg(); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<div class="mt-3">
    <a href="blocks_map.php" class="btn btn-primary">
        <i class="bi bi-map"></i> Go to Map View
    </a>
    <a href="blocks.php" class="btn btn-secondary">
        <i class="bi bi-list"></i> Go to Blocks List
    </a>
</div>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
