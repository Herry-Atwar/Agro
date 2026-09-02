<?php
// Clear any output buffers and disable caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

$db = getDB();

// ── Scope: resolve the narrowest assignment for the current user ─────────────
$is_admin       = has_role('Admin');
$sess_company   = isset($_SESSION['company_id'])       ? (int)$_SESSION['company_id']       : null;
$sess_bu        = isset($_SESSION['business_unit_id']) ? (int)$_SESSION['business_unit_id'] : null;
$sess_division  = isset($_SESSION['division_id'])      ? (int)$_SESSION['division_id']      : null;

// Build WHERE conditions for scoped access
$where_clauses = ["b.geojson IS NOT NULL", "b.geojson != ''"];
$where_params  = [];

if (!$is_admin) {
    if ($sess_division) {
        // Most specific: scoped to a single division
        $where_clauses[] = "b.division_id = :scope_division_id";
        $where_params[':scope_division_id'] = $sess_division;
    } elseif ($sess_bu) {
        // Mid level: scoped to a business unit (all its divisions)
        $where_clauses[] = "d.business_unit_id = :scope_bu_id";
        $where_params[':scope_bu_id'] = $sess_bu;
    } elseif ($sess_company) {
        // Broadest non-admin scope: all blocks under the user's company
        $where_clauses[] = "c.company_id = :scope_company_id";
        $where_params[':scope_company_id'] = $sess_company;
    }
    // If none set (edge case), the user sees nothing — clauses keep it safe
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch blocks visible to this user
$sql = "SELECT b.*,
        py.year as planting_year,
        d.division_code, d.division_name,
        bu.unit_code, bu.unit_name,
        bu.business_unit_id,
        c.company_id, c.company_code, c.company_name
        FROM blocks b
        LEFT JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        LEFT JOIN divisions d ON b.division_id = d.division_id
        LEFT JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        LEFT JOIN companies c ON bu.company_id = c.company_id
        WHERE $where_sql
        ORDER BY c.company_name, bu.unit_name, d.division_name, b.block_code";

$stmt = $db->prepare($sql);
$stmt->execute($where_params);
$blocks = $stmt->fetchAll();

// Debug: Log the count
error_log("blocks_map.php: Fetched " . count($blocks) . " blocks with GeoJSON (user=" . ($_SESSION['user_id'] ?? '?') . ", admin=" . ($is_admin ? 'yes' : 'no') . ")");

// Get unique companies for the filter dropdown — restricted to what the user can see
if ($is_admin) {
    $companies_stmt = $db->query("SELECT DISTINCT c.company_id, c.company_name FROM companies c ORDER BY c.company_name");
} else {
    $companies_stmt = $db->prepare("SELECT DISTINCT c.company_id, c.company_name
        FROM blocks b
        LEFT JOIN divisions d ON b.division_id = d.division_id
        LEFT JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        LEFT JOIN companies c ON bu.company_id = c.company_id
        WHERE $where_sql
        ORDER BY c.company_name");
    $companies_stmt->execute($where_params);
}
$companies = $companies_stmt->fetchAll();

require_once 'includes/header.php';
?>

<style>
#map {
    height: 600px;
    width: 100%;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.legend {
    background: white;
    padding: 10px;
    border-radius: 5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
.legend-item {
    margin: 5px 0;
}
.legend-color {
    display: inline-block;
    width: 20px;
    height: 20px;
    margin-right: 5px;
    border: 1px solid #333;
    vertical-align: middle;
}
.map-controls {
    margin-bottom: 20px;
}
</style>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<!-- SmartMap — World POI/boundary overlay from World app -->
<script>
window.SMART_MAP_API = '<?php echo rtrim($_SERVER["APP_BASE"] ?? "/world/public", "/"); ?>/smartmap/api/layers';
window.SMART_MAP_COUNTRY_ID = 360;
</script>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-map"></i> Blocks Map Visualization</h1>
            <p class="text-muted">Interactive map showing all blocks with GeoJSON data</p>
        </div>
        <div class="col-auto">
            <a href="blocks.php" class="btn btn-secondary">
                <i class="bi bi-list"></i> Back to Blocks List
            </a>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo count($blocks); ?></h3>
                <p><i class="bi bi-map"></i> Blocks with GeoJSON</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number(array_sum(array_column($blocks, 'area'))); ?></h3>
                <p><i class="bi bi-rulers"></i> Total Area (Ha)</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3 id="plantation-count">0</h3>
                <p><i class="bi bi-tree"></i> Plantation Blocks</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3 id="forestry-count">0</h3>
                <p><i class="bi bi-tree-fill"></i> Forestry Blocks</p>
            </div>
        </div>
    </div>
</div>

<!-- Map Controls -->
<div class="card map-controls">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Filter by Company</label>
                <select class="form-select" id="companyFilter">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?php echo $company['company_id']; ?>">
                            <?php echo htmlspecialchars($company['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Filter by Operation Type</label>
                <select class="form-select" id="operationFilter">
                    <option value="">All Types</option>
                    <option value="Plantation">Plantation</option>
                    <option value="Forestry">Forestry</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Filter by Status</label>
                <select class="form-select" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="TBM">TBM (Belum Menghasilkan)</option>
                    <option value="TM">TM (Menghasilkan)</option>
                    <option value="TR">TR (Rusak)</option>
                    <option value="TTM">TTM (Tidak Menghasilkan)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Search Block</label>
                <input type="text" class="form-control" id="blockSearch" placeholder="Search by block code or name...">
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary w-100" onclick="applyFilters()">
                    <i class="bi bi-funnel"></i> Filter
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Map Container -->
<div class="card">
    <div class="card-body">
        <div id="map"></div>
    </div>
</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// Blocks data from PHP
const blocksData = <?php echo json_encode($blocks); ?>;

// Debug: Log blocks data
console.log('Total blocks loaded:', blocksData.length);
console.log('Blocks data:', blocksData);

// Initialize map
const map = L.map('map').setView([-2.5, 118.0], 5); // Center on Indonesia

// Add OpenStreetMap tile layer
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19
}).addTo(map);

// Add satellite layer option
const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    attribution: 'Tiles © Esri',
    maxZoom: 19
});

// Layer control
const baseMaps = {
    "Street Map": L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }),
    "Satellite": satellite
};

// SmartMap overlay groups — loaded from World app API
const worldPoiGroup   = L.layerGroup();
const worldBoundGroup = L.layerGroup();

const layerControl = L.control.layers(baseMaps, {
    '🌍 Public POI (World)':       worldPoiGroup,
    '🗺️ Admin Boundaries (World)': worldBoundGroup,
}).addTo(map);

// ── SmartMap: load World POI/boundary overlays ────────────────────────────────
(function loadSmartMapOverlays() {
    const apiBase   = window.SMART_MAP_API;
    const countryId = window.SMART_MAP_COUNTRY_ID || 360;
    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    fetch(apiBase + '?source=world&type=poi&country_id=' + countryId, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(fc => {
            if (!fc || !fc.features) return;
            fc.features.forEach(f => {
                if (!f.geometry || f.geometry.type !== 'Point') return;
                const [lng, lat] = f.geometry.coordinates;
                const p = f.properties || {};
                const canEdit = !!(fc.meta && fc.meta.can_edit);
                let html = '<div style="min-width:200px;"><strong>' + esc(p.name) + '</strong>';
                if (p.category_name) html += '<br><small style="color:#888;">' + esc(p.category_name) + '</small>';
                if (p.address)       html += '<br><small>📍 ' + esc(p.address) + '</small>';
                if (canEdit && p.edit_url)
                    html += '<br><a href="' + esc(p.edit_url) + '" target="_blank" style="font-size:.8em;color:#3b82f3;">✏️ Edit POI</a>';
                html += '</div>';
                L.marker([lat, lng]).bindPopup(html).addTo(worldPoiGroup);
            });
        })
        .catch(err => console.warn('[SmartMap] POI overlay error:', err));
    fetch(apiBase + '?source=world&type=boundary&country_id=' + countryId, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(fc => {
            if (!fc || !fc.features) return;
            fc.features.forEach(f => {
                if (!f.geometry) return;
                const p = f.properties || {};
                L.geoJSON(f, { style: { color: '#888', weight: 1.5, fillOpacity: 0.04, dashArray: '4 4' } })
                    .bindPopup('<strong>' + esc(p.name) + '</strong>')
                    .addTo(worldBoundGroup);
            });
        })
        .catch(err => console.warn('[SmartMap] Boundary overlay error:', err));
})();

// Store all layers for filtering
let allLayers = [];

// Color schemes
const colors = {
    'Plantation': {
        'TBM': '#FFA500', // Orange
        'TM': '#228B22',  // Green
        'TR': '#DC143C',  // Red
        'TTM': '#808080'  // Gray
    },
    'Forestry': {
        'default': '#006400' // Dark Green
    }
};

// Function to get color based on operation type and status
function getColor(operationType, status) {
    if (operationType === 'Plantation') {
        return colors.Plantation[status] || '#808080';
    } else {
        return colors.Forestry.default;
    }
}

// Function to create popup content
function createPopupContent(block) {
    let content = `
        <div style="min-width: 250px;">
            <h5><strong>${block.block_code}</strong> - ${block.block_name || 'N/A'}</h5>
            <hr>
            <table class="table table-sm table-borderless">
                <tr><td><strong>Company:</strong></td><td>${block.company_name || 'N/A'}</td></tr>
                <tr><td><strong>Business Unit:</strong></td><td>${block.unit_name || 'N/A'}</td></tr>
                <tr><td><strong>Division:</strong></td><td>${block.division_name || 'N/A'}</td></tr>
                <tr><td><strong>Operation Type:</strong></td><td><span class="badge bg-info">${block.operation_type}</span></td></tr>
    `;
    
    if (block.operation_type === 'Plantation') {
        content += `
                <tr><td><strong>Planting Year:</strong></td><td>${block.planting_year || 'N/A'}</td></tr>
                <tr><td><strong>Status:</strong></td><td><span class="badge bg-${block.status === 'TM' ? 'success' : 'warning'}">${block.status}</span></td></tr>
                <tr><td><strong>Area:</strong></td><td>${parseFloat(block.area).toFixed(2)} Ha</td></tr>
                <tr><td><strong>Total Plants:</strong></td><td>${parseInt(block.total_plants || 0).toLocaleString()}</td></tr>
                <tr><td><strong>Plant Age:</strong></td><td>${block.plant_age || 0} years</td></tr>
        `;
    } else {
        content += `
                <tr><td><strong>Tree Species:</strong></td><td>${block.tree_species || 'N/A'}</td></tr>
                <tr><td><strong>Area:</strong></td><td>${parseFloat(block.area).toFixed(2)} Ha</td></tr>
                <tr><td><strong>Volume:</strong></td><td>${parseFloat(block.volume_m3 || 0).toFixed(2)} m³</td></tr>
                <tr><td><strong>Carbon Stock:</strong></td><td>${parseFloat(block.carbon_stock_ton || 0).toFixed(2)} ton</td></tr>
                <tr><td><strong>Forest Type:</strong></td><td>${block.forest_type || 'N/A'}</td></tr>
        `;
    }
    
    content += `
            </table>
            <a href="blocks.php?action=edit&id=${block.block_id}" class="btn btn-sm btn-primary" target="_blank">
                <i class="bi bi-pencil"></i> Edit Block
            </a>
        </div>
    `;
    
    return content;
}

// Function to add blocks to map
function addBlocksToMap(blocks) {
    // Clear existing layers
    allLayers.forEach(layer => map.removeLayer(layer));
    allLayers = [];
    
    let bounds = [];
    let plantationCount = 0;
    let forestryCount = 0;
    
    blocks.forEach((block, index) => {
        console.log(`[${index + 1}/${blocks.length}] Processing block:`, block.block_code, 'Has GeoJSON:', !!block.geojson);
        
        if (!block.geojson) {
            console.log('Skipping block (no GeoJSON):', block.block_code);
            return;
        }
        
        try {
            // Decode HTML entities - handle both " and &#34; and regular quotes
            let decodedGeoJSON = block.geojson;
            
            // First pass: use textarea for standard HTML entities
            const textarea = document.createElement('textarea');
            textarea.innerHTML = decodedGeoJSON;
            decodedGeoJSON = textarea.value;
            
            // Second pass: manually replace any remaining encoded quotes
            decodedGeoJSON = decodedGeoJSON
                .replace(/"/g, '"')
                .replace(/&#34;/g, '"')
                .replace(/'/g, "'")
                .replace(/'/g, "'")
                .replace(/</g, '<')
                .replace(/>/g, '>')
                .replace(/&/g, '&');
            
            console.log('Decoded GeoJSON for', block.block_code, ':', decodedGeoJSON.substring(0, 100));
            
            const geojson = JSON.parse(decodedGeoJSON);
            
            // Validate GeoJSON structure
            if (!geojson || !geojson.type) {
                console.error('Invalid GeoJSON structure for block:', block.block_code);
                return;
            }
            
            const color = getColor(block.operation_type, block.status);
            
            console.log('Creating layer for', block.block_code, 'with color:', color);
            
            const layer = L.geoJSON(geojson, {
                style: {
                    color: color,
                    weight: 2,
                    opacity: 0.8,
                    fillOpacity: 0.4
                }
            }).bindPopup(createPopupContent(block));
            
            // Check if layer was created successfully
            if (!layer || layer.getLayers().length === 0) {
                console.error('Failed to create layer for block:', block.block_code);
                return;
            }
            
            layer.addTo(map);
            allLayers.push({layer: layer, data: block});
            
            // Get bounds
            const layerBounds = layer.getBounds();
            bounds.push(layerBounds);
            
            console.log('✓ Successfully added block to map:', block.block_code);
            
            // Count by operation type
            if (block.operation_type === 'Plantation') {
                plantationCount++;
            } else {
                forestryCount++;
            }
            
        } catch (e) {
            console.error('✗ Error parsing GeoJSON for block:', block.block_code, e);
            console.error('GeoJSON content:', block.geojson.substring(0, 200));
            console.error('Error details:', e.message);
        }
    });
    
    console.log('Total layers added to map:', allLayers.length);
    console.log('Plantation count:', plantationCount, 'Forestry count:', forestryCount);
    
    // Fit map to bounds if we have blocks
    if (allLayers.length > 0) {
        const group = new L.featureGroup(allLayers.map(l => l.layer));
        map.fitBounds(group.getBounds().pad(0.1));
    }
    
    // Update counts
    document.getElementById('plantation-count').textContent = plantationCount;
    document.getElementById('forestry-count').textContent = forestryCount;
}

// Function to apply filters
function applyFilters() {
    const companyFilter = document.getElementById('companyFilter').value;
    const operationFilter = document.getElementById('operationFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    const blockSearch = document.getElementById('blockSearch').value.toLowerCase();
    
    const filteredBlocks = blocksData.filter(block => {
        if (companyFilter && block.company_id != companyFilter) return false;
        if (operationFilter && block.operation_type !== operationFilter) return false;
        if (statusFilter && block.status !== statusFilter) return false;
        if (blockSearch) {
            const blockCode = (block.block_code || '').toLowerCase();
            const blockName = (block.block_name || '').toLowerCase();
            if (!blockCode.includes(blockSearch) && !blockName.includes(blockSearch)) {
                return false;
            }
        }
        return true;
    });
    
    addBlocksToMap(filteredBlocks);
}


// Add legend
const legend = L.control({position: 'bottomright'});
legend.onAdd = function(map) {
    const div = L.DomUtil.create('div', 'legend');
    div.innerHTML = `
        <h6><strong>Legend</strong></h6>
        <div class="legend-item">
            <span class="legend-color" style="background: ${colors.Plantation.TBM}"></span> Plantation - TBM
        </div>
        <div class="legend-item">
            <span class="legend-color" style="background: ${colors.Plantation.TM}"></span> Plantation - TM
        </div>
        <div class="legend-item">
            <span class="legend-color" style="background: ${colors.Plantation.TR}"></span> Plantation - TR
        </div>
        <div class="legend-item">
            <span class="legend-color" style="background: ${colors.Forestry.default}"></span> Forestry
        </div>
    `;
    return div;
};
legend.addTo(map);

// Pre-select company from URL param (e.g. from Q&A link: ?company_id=1)
(function applyUrlParams() {
    const params = new URLSearchParams(window.location.search);
    const cid = params.get('company_id');
    const bid = params.get('business_unit_id');
    if (cid) {
        const sel = document.getElementById('companyFilter');
        if (sel) { sel.value = cid; }
    }
    if (bid) {
        // No BU dropdown on the map page; silently ignore — company filter is enough
    }
})();

// Initial load — uses whatever the dropdowns were just set to
addBlocksToMap(blocksData.filter(b => {
    const cid = new URLSearchParams(window.location.search).get('company_id');
    return !cid || String(b.company_id) === String(cid);
}));

// Add scale
L.control.scale().addTo(map);

// Setup real-time search after DOM is ready
const blockSearch = document.getElementById('blockSearch');
let searchTimeout;

blockSearch.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300); // Debounce 300ms
});

// Also apply filters on Enter key
blockSearch.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        applyFilters();
    }
});

// Add change listeners to all filter dropdowns for real-time filtering
document.getElementById('companyFilter').addEventListener('change', applyFilters);
document.getElementById('operationFilter').addEventListener('change', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);
</script>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
