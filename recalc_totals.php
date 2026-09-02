<?php
/**
 * recalc_totals.php — One-time resync of all rolled-up totals
 * Run this once to fix stale data already in the database.
 * Safe to run multiple times (idempotent).
 * Access restricted to admin only.
 */
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_role('admin');

$db = getDB();
$log = [];

// ── 1. planting_years: actual_area / actual_plants ────────────────────────────
$db->exec("
    UPDATE planting_years py SET
        py.actual_area   = COALESCE((SELECT SUM(b.area)         FROM blocks b WHERE b.planting_year_id = py.planting_year_id), 0),
        py.actual_plants = COALESCE((SELECT SUM(b.total_plants) FROM blocks b WHERE b.planting_year_id = py.planting_year_id), 0)
");
$log[] = 'planting_years: updated actual_area + actual_plants';

// ── 2. divisions: plantation totals (direct from blocks, no intermediate) ─────
$db->exec("
    UPDATE divisions d SET
        d.total_area_ha = COALESCE((
            SELECT SUM(b.area)
            FROM blocks b
            INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
            WHERE py.division_id = d.division_id AND b.operation_type = 'Plantation'
        ), 0),
        d.total_plants = COALESCE((
            SELECT SUM(b.total_plants)
            FROM blocks b
            INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
            WHERE py.division_id = d.division_id AND b.operation_type = 'Plantation'
        ), 0),
        d.total_blocks = COALESCE((
            SELECT COUNT(*)
            FROM blocks b
            INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
            WHERE py.division_id = d.division_id
        ), 0)
");
$log[] = 'divisions: updated total_area_ha + total_plants + total_blocks (plantation)';

// ── 3. divisions: forestry totals (direct from blocks) ───────────────────────
$db->exec("
    UPDATE divisions d SET
        d.forestry_area_ha      = COALESCE((SELECT SUM(b.area)             FROM blocks b WHERE b.division_id = d.division_id AND b.operation_type = 'Forestry'), 0),
        d.total_volume_m3       = COALESCE((SELECT SUM(b.volume_m3)        FROM blocks b WHERE b.division_id = d.division_id AND b.operation_type = 'Forestry'), 0),
        d.total_carbon_stock_ton= COALESCE((SELECT SUM(b.carbon_stock_ton) FROM blocks b WHERE b.division_id = d.division_id AND b.operation_type = 'Forestry'), 0),
        d.forestry_blocks       = COALESCE((SELECT COUNT(*)                FROM blocks b WHERE b.division_id = d.division_id AND b.operation_type = 'Forestry'), 0)
");
$log[] = 'divisions: updated forestry_area_ha + volume + carbon + forestry_blocks';

// ── 4. divisions: roll forestry up to parent divisions (bottom-up) ────────────
// Run multiple passes until no more changes (handles arbitrary depth)
$pass = 0;
do {
    $rows = $db->exec("
        UPDATE divisions parent
        INNER JOIN (
            SELECT parent_division_id,
                   SUM(forestry_area_ha) as fa, SUM(total_volume_m3) as tv,
                   SUM(total_carbon_stock_ton) as tc, SUM(forestry_blocks) as fb
            FROM divisions
            WHERE parent_division_id IS NOT NULL
            GROUP BY parent_division_id
        ) agg ON parent.division_id = agg.parent_division_id
        SET
            parent.forestry_area_ha       = COALESCE(agg.fa, 0),
            parent.total_volume_m3        = COALESCE(agg.tv, 0),
            parent.total_carbon_stock_ton = COALESCE(agg.tc, 0),
            parent.forestry_blocks        = COALESCE(agg.fb, 0)
    ");
    $pass++;
} while ($rows > 0 && $pass < 10);
$log[] = "division parent forestry rollup: $pass pass(es)";

// ── 5. business_units: plantation totals (direct from blocks) ─────────────────
$db->exec("
    UPDATE business_units bu SET
        bu.total_area_ha = COALESCE((
            SELECT SUM(b.area)
            FROM blocks b
            INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
            INNER JOIN divisions d ON py.division_id = d.division_id
            WHERE d.business_unit_id = bu.business_unit_id AND b.operation_type = 'Plantation'
        ), 0),
        bu.total_plants  = COALESCE((
            SELECT SUM(b.total_plants)
            FROM blocks b
            INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
            INNER JOIN divisions d ON py.division_id = d.division_id
            WHERE d.business_unit_id = bu.business_unit_id AND b.operation_type = 'Plantation'
        ), 0)
");
$log[] = 'business_units: updated total_area_ha + total_plants (plantation, all divisions)';

// ── 6. business_units: forestry totals (from top-level divisions) ─────────────
$db->exec("
    UPDATE business_units bu SET
        bu.forestry_area_ha       = COALESCE((SELECT SUM(d.forestry_area_ha)       FROM divisions d WHERE d.business_unit_id = bu.business_unit_id AND d.parent_division_id IS NULL), 0),
        bu.total_volume_m3        = COALESCE((SELECT SUM(d.total_volume_m3)        FROM divisions d WHERE d.business_unit_id = bu.business_unit_id AND d.parent_division_id IS NULL), 0),
        bu.total_carbon_stock_ton = COALESCE((SELECT SUM(d.total_carbon_stock_ton) FROM divisions d WHERE d.business_unit_id = bu.business_unit_id AND d.parent_division_id IS NULL), 0),
        bu.forestry_blocks        = COALESCE((SELECT SUM(d.forestry_blocks)        FROM divisions d WHERE d.business_unit_id = bu.business_unit_id AND d.parent_division_id IS NULL), 0)
");
$log[] = 'business_units: updated forestry totals from top-level divisions';

// ── 7. companies: plantation + forestry totals (from top-level BUs) ───────────
$db->exec("
    UPDATE companies c SET
        c.total_area_ha = COALESCE((
            SELECT SUM(b.area)
            FROM blocks b
            INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
            INNER JOIN divisions d ON py.division_id = d.division_id
            INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
            WHERE bu.company_id = c.company_id AND b.operation_type = 'Plantation'
        ), 0),
        c.total_plants = COALESCE((
            SELECT SUM(b.total_plants)
            FROM blocks b
            INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
            INNER JOIN divisions d ON py.division_id = d.division_id
            INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
            WHERE bu.company_id = c.company_id AND b.operation_type = 'Plantation'
        ), 0),
        c.forestry_area_ha       = COALESCE((SELECT SUM(bu.forestry_area_ha)       FROM business_units bu WHERE bu.company_id = c.company_id AND bu.parent_unit_id IS NULL), 0),
        c.total_volume_m3        = COALESCE((SELECT SUM(bu.total_volume_m3)        FROM business_units bu WHERE bu.company_id = c.company_id AND bu.parent_unit_id IS NULL), 0),
        c.total_carbon_stock_ton = COALESCE((SELECT SUM(bu.total_carbon_stock_ton) FROM business_units bu WHERE bu.company_id = c.company_id AND bu.parent_unit_id IS NULL), 0),
        c.forestry_blocks        = COALESCE((SELECT SUM(bu.forestry_blocks)        FROM business_units bu WHERE bu.company_id = c.company_id AND bu.parent_unit_id IS NULL), 0)
");
$log[] = 'companies: updated all totals';

$page_title = "Recalculate Totals";
require_once 'includes/header.php';
?>
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-arrow-repeat"></i> Recalculate All Totals</h1>
            <p class="text-muted mb-0">Resyncs all rolled-up figures from raw block data</p>
        </div>
        <div class="col-auto">
            <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-house"></i> Dashboard</a>
        </div>
    </div>
</div>
<div class="card">
    <div class="card-header bg-success text-white"><i class="bi bi-check-circle"></i> Recalculation Complete</div>
    <div class="card-body">
        <ul class="list-group list-group-flush">
            <?php foreach ($log as $line): ?>
                <li class="list-group-item"><i class="bi bi-check text-success me-2"></i><?php echo htmlspecialchars($line); ?></li>
            <?php endforeach; ?>
        </ul>
        <div class="mt-3">
            <a href="companies.php" class="btn btn-primary me-2"><i class="bi bi-building"></i> View Companies</a>
            <a href="business_units.php" class="btn btn-outline-primary me-2"><i class="bi bi-diagram-3"></i> Business Units</a>
            <a href="divisions.php" class="btn btn-outline-primary me-2"><i class="bi bi-grid-3x3"></i> Divisions</a>
            <a href="planting_years.php" class="btn btn-outline-primary"><i class="bi bi-calendar-event"></i> Planting Years</a>
        </div>
    </div>
</div>
<?php require_once 'includes/footer.php'; ?>
