<?php
// Version check - if you see this error, clear browser cache
// Last updated: 2026-06-09 23:48
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// ─────────────────────────────────────────────────────────────────────────────
// Cascade rollup helpers — all read directly from blocks to avoid stale chains
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Update a single division's plantation totals directly from blocks.
 * Reads blocks → planting_years → division (no intermediate stale columns).
 */
function update_division_plantation($db, $division_id) {
    $db->prepare("
        UPDATE divisions d SET
            d.total_area_ha = COALESCE((
                SELECT SUM(b.area)
                FROM blocks b
                INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
                WHERE py.division_id = ? AND b.operation_type = 'Plantation'
            ), 0),
            d.total_plants = COALESCE((
                SELECT SUM(b.total_plants)
                FROM blocks b
                INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
                WHERE py.division_id = ? AND b.operation_type = 'Plantation'
            ), 0),
            d.total_blocks = COALESCE((
                SELECT COUNT(*)
                FROM blocks b
                INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
                WHERE py.division_id = ?
            ), 0)
        WHERE d.division_id = ?
    ")->execute([$division_id, $division_id, $division_id, $division_id]);
}

/**
 * Update a single division's forestry totals directly from blocks.
 */
function update_division_forestry($db, $division_id) {
    $db->prepare("
        UPDATE divisions d SET
            d.forestry_area_ha = COALESCE((
                SELECT SUM(b.area) FROM blocks b
                WHERE b.division_id = ? AND b.operation_type = 'Forestry'
            ), 0),
            d.total_volume_m3 = COALESCE((
                SELECT SUM(b.volume_m3) FROM blocks b
                WHERE b.division_id = ? AND b.operation_type = 'Forestry'
            ), 0),
            d.total_carbon_stock_ton = COALESCE((
                SELECT SUM(b.carbon_stock_ton) FROM blocks b
                WHERE b.division_id = ? AND b.operation_type = 'Forestry'
            ), 0),
            d.forestry_blocks = COALESCE((
                SELECT COUNT(*) FROM blocks b
                WHERE b.division_id = ? AND b.operation_type = 'Forestry'
            ), 0)
        WHERE d.division_id = ?
    ")->execute([$division_id, $division_id, $division_id, $division_id, $division_id]);
}

/**
 * Recursively propagate forestry totals up through parent divisions.
 * Each parent sums its direct children (which have already been updated bottom-up).
 */
function update_parent_divisions_forestry($db, $division_id) {
    $parent_id = $db->prepare("SELECT parent_division_id FROM divisions WHERE division_id = ?");
    $parent_id->execute([$division_id]);
    $parent_division_id = $parent_id->fetchColumn();

    if ($parent_division_id) {
        $db->prepare("
            UPDATE divisions d SET
                d.forestry_area_ha      = COALESCE((SELECT SUM(c.forestry_area_ha)      FROM divisions c WHERE c.parent_division_id = ?), 0),
                d.total_volume_m3       = COALESCE((SELECT SUM(c.total_volume_m3)       FROM divisions c WHERE c.parent_division_id = ?), 0),
                d.total_carbon_stock_ton= COALESCE((SELECT SUM(c.total_carbon_stock_ton) FROM divisions c WHERE c.parent_division_id = ?), 0),
                d.forestry_blocks       = COALESCE((SELECT SUM(c.forestry_blocks)        FROM divisions c WHERE c.parent_division_id = ?), 0)
            WHERE d.division_id = ?
        ")->execute([$parent_division_id, $parent_division_id, $parent_division_id, $parent_division_id, $parent_division_id]);

        update_parent_divisions_forestry($db, $parent_division_id);
    }
}

/**
 * Recursively propagate forestry totals up through parent business units.
 */
function update_parent_business_units_forestry($db, $business_unit_id) {
    $p = $db->prepare("SELECT parent_unit_id FROM business_units WHERE business_unit_id = ?");
    $p->execute([$business_unit_id]);
    $parent_unit_id = $p->fetchColumn();

    if ($parent_unit_id) {
        $db->prepare("
            UPDATE business_units bu SET
                bu.forestry_area_ha       = COALESCE((SELECT SUM(c.forestry_area_ha)       FROM business_units c WHERE c.parent_unit_id = ?), 0),
                bu.total_volume_m3        = COALESCE((SELECT SUM(c.total_volume_m3)        FROM business_units c WHERE c.parent_unit_id = ?), 0),
                bu.total_carbon_stock_ton = COALESCE((SELECT SUM(c.total_carbon_stock_ton) FROM business_units c WHERE c.parent_unit_id = ?), 0),
                bu.forestry_blocks        = COALESCE((SELECT SUM(c.forestry_blocks)        FROM business_units c WHERE c.parent_unit_id = ?), 0)
            WHERE bu.business_unit_id = ?
        ")->execute([$parent_unit_id, $parent_unit_id, $parent_unit_id, $parent_unit_id, $parent_unit_id]);

        update_parent_business_units_forestry($db, $parent_unit_id);
    }
}

/**
 * Roll up business_unit and company totals from divisions → blocks (no intermediate columns).
 * Plantation: sums ALL divisions under the BU (not just top-level).
 * Forestry: sums top-level divisions (which have already been rolled up from children).
 */
function update_business_unit_and_company_totals($db, $division_id, $is_forestry = false) {
    $bu_stmt = $db->prepare("SELECT business_unit_id FROM divisions WHERE division_id = ?");
    $bu_stmt->execute([$division_id]);
    $business_unit_id = $bu_stmt->fetchColumn();
    if (!$business_unit_id) return;

    $comp_stmt = $db->prepare("SELECT company_id FROM business_units WHERE business_unit_id = ?");
    $comp_stmt->execute([$business_unit_id]);
    $company_id = $comp_stmt->fetchColumn();

    if ($is_forestry) {
        // BU forestry: sum top-level divisions (children already rolled up)
        $db->prepare("
            UPDATE business_units bu SET
                bu.forestry_area_ha       = COALESCE((SELECT SUM(d.forestry_area_ha)       FROM divisions d WHERE d.business_unit_id = ? AND d.parent_division_id IS NULL), 0),
                bu.total_volume_m3        = COALESCE((SELECT SUM(d.total_volume_m3)        FROM divisions d WHERE d.business_unit_id = ? AND d.parent_division_id IS NULL), 0),
                bu.total_carbon_stock_ton = COALESCE((SELECT SUM(d.total_carbon_stock_ton) FROM divisions d WHERE d.business_unit_id = ? AND d.parent_division_id IS NULL), 0),
                bu.forestry_blocks        = COALESCE((SELECT SUM(d.forestry_blocks)        FROM divisions d WHERE d.business_unit_id = ? AND d.parent_division_id IS NULL), 0)
            WHERE bu.business_unit_id = ?
        ")->execute([$business_unit_id, $business_unit_id, $business_unit_id, $business_unit_id, $business_unit_id]);

        update_parent_business_units_forestry($db, $business_unit_id);

        if ($company_id) {
            $db->prepare("
                UPDATE companies c SET
                    c.forestry_area_ha       = COALESCE((SELECT SUM(bu.forestry_area_ha)       FROM business_units bu WHERE bu.company_id = ? AND bu.parent_unit_id IS NULL), 0),
                    c.total_volume_m3        = COALESCE((SELECT SUM(bu.total_volume_m3)        FROM business_units bu WHERE bu.company_id = ? AND bu.parent_unit_id IS NULL), 0),
                    c.total_carbon_stock_ton = COALESCE((SELECT SUM(bu.total_carbon_stock_ton) FROM business_units bu WHERE bu.company_id = ? AND bu.parent_unit_id IS NULL), 0),
                    c.forestry_blocks        = COALESCE((SELECT SUM(bu.forestry_blocks)        FROM business_units bu WHERE bu.company_id = ? AND bu.parent_unit_id IS NULL), 0)
                WHERE c.company_id = ?
            ")->execute([$company_id, $company_id, $company_id, $company_id, $company_id]);
        }
    } else {
        // BU plantation: sum ALL divisions (child divisions hold real blocks too)
        $db->prepare("
            UPDATE business_units bu SET
                bu.total_area_ha = COALESCE((
                    SELECT SUM(b.area)
                    FROM blocks b
                    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
                    INNER JOIN divisions d ON py.division_id = d.division_id
                    WHERE d.business_unit_id = ? AND b.operation_type = 'Plantation'
                ), 0),
                bu.total_plants  = COALESCE((
                    SELECT SUM(b.total_plants)
                    FROM blocks b
                    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
                    INNER JOIN divisions d ON py.division_id = d.division_id
                    WHERE d.business_unit_id = ? AND b.operation_type = 'Plantation'
                ), 0)
            WHERE bu.business_unit_id = ?
        ")->execute([$business_unit_id, $business_unit_id, $business_unit_id]);

        if ($company_id) {
            $db->prepare("
                UPDATE companies c SET
                    c.total_area_ha = COALESCE((
                        SELECT SUM(b.area)
                        FROM blocks b
                        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
                        INNER JOIN divisions d ON py.division_id = d.division_id
                        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
                        WHERE bu.company_id = ? AND b.operation_type = 'Plantation'
                    ), 0),
                    c.total_plants  = COALESCE((
                        SELECT SUM(b.total_plants)
                        FROM blocks b
                        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
                        INNER JOIN divisions d ON py.division_id = d.division_id
                        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
                        WHERE bu.company_id = ? AND b.operation_type = 'Plantation'
                    ), 0)
                WHERE c.company_id = ?
            ")->execute([$company_id, $company_id, $company_id]);
        }
    }
}

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            // Calculate plant age if planting date is provided
            $plant_age = 0;
            if (post('planting_date')) {
                $plant_age = calculate_plant_age(post('planting_date'));
            }
            
            $stmt = $db->prepare("
                INSERT INTO blocks (operation_type, ownership_type, company_id, business_unit_id, planting_year_id, division_id, block_code, block_name, area, planted_area,
                                  plant_density, normal_plants, abnormal_plants, dead_plants, total_plants,
                                  planting_date, plant_age, topography,
                                  soil_type, soil_ph, elevation_m, status, harvest_status,
                                  tree_species, stand_density, average_dbh, volume_m3, carbon_stock_ton,
                                  age_class, establishment_year, forest_type,
                                  latitude, longitude, geojson, notes, created_by)
                VALUES (:operation_type, :ownership_type, :company_id, :business_unit_id, :planting_year_id, :division_id, :block_code, :block_name, :area, :planted_area,
                        :plant_density, :normal_plants, :abnormal_plants, :dead_plants, :total_plants,
                        :planting_date, :plant_age, :topography,
                        :soil_type, :soil_ph, :elevation_m, :status, :harvest_status,
                        :tree_species, :stand_density, :average_dbh, :volume_m3, :carbon_stock_ton,
                        :age_class, :establishment_year, :forest_type,
                        :latitude, :longitude, :geojson, :notes, 'admin')
            ");
            
            $stmt->execute([
                ':operation_type'  => post('operation_type', 'Plantation'),
                ':ownership_type'  => post('ownership_type', 'inti'),
                ':company_id'      => post('company_id') ?: null,
                ':business_unit_id' => post('business_unit_id') ?: null,
                ':planting_year_id' => post('operation_type') == 'Forestry' ? null : post('planting_year_id'),
                ':division_id' => post('division_id'),
                ':block_code' => post('block_code'),
                ':block_name' => post('block_name'),
                ':area' => post('area'),
                ':planted_area' => post('planted_area') ?: null,
                ':plant_density' => post('plant_density') ?: null,
                ':normal_plants' => post('normal_plants') ?: null,
                ':abnormal_plants' => post('abnormal_plants') ?: null,
                ':dead_plants' => post('dead_plants') ?: null,
                ':total_plants' => post('total_plants') ?: null,
                ':planting_date' => post('planting_date') ?: null,
                ':plant_age' => $plant_age,
                ':topography' => post('topography', 'Flat'),
                ':soil_type' => post('soil_type') ?: null,
                ':soil_ph' => post('soil_ph') ?: null,
                ':elevation_m' => post('elevation_m') ?: null,
                ':status' => post('status', 'TBM'),
                ':harvest_status' => post('harvest_status', 'Not Ready'),
                ':tree_species' => post('tree_species') ?: null,
                ':stand_density' => post('stand_density') ?: null,
                ':average_dbh' => post('average_dbh') ?: null,
                ':volume_m3' => post('volume_m3') ?: null,
                ':carbon_stock_ton' => post('carbon_stock_ton') ?: null,
                ':age_class' => post('age_class') ?: null,
                ':establishment_year' => post('establishment_year') ?: null,
                ':forest_type' => post('forest_type') ?: null,
                ':latitude' => post('latitude') ?: null,
                ':longitude' => post('longitude') ?: null,
                ':geojson' => post('geojson') ?: null,
                ':notes' => post('notes') ?: null
            ]);

            // Save plant varieties (block_plant_varieties)
            $new_block_id = $db->lastInsertId();
            $variety_ids = isset($_POST['variety_ids']) ? array_filter(array_map('intval', (array)$_POST['variety_ids'])) : [];
            if ($new_block_id && !empty($variety_ids)) {
                $ins_v = $db->prepare("INSERT IGNORE INTO block_plant_varieties (block_id, variety_id) VALUES (?, ?)");
                foreach ($variety_ids as $vid) {
                    $ins_v->execute([$new_block_id, $vid]);
                }
            }
            
            // Cascade rollup after add
            if (post('operation_type') == 'Plantation' && post('planting_year_id')) {
                $py_id = post('planting_year_id');
                // 1. planting_year actual totals
                $db->prepare("
                    UPDATE planting_years py SET
                        py.actual_area   = COALESCE((SELECT SUM(b.area)         FROM blocks b WHERE b.planting_year_id = ?), 0),
                        py.actual_plants = COALESCE((SELECT SUM(b.total_plants) FROM blocks b WHERE b.planting_year_id = ?), 0)
                    WHERE py.planting_year_id = ?
                ")->execute([$py_id, $py_id, $py_id]);
                // 2. division → BU → company
                $div_stmt = $db->prepare("SELECT division_id FROM planting_years WHERE planting_year_id = ?");
                $div_stmt->execute([$py_id]);
                $division_id = $div_stmt->fetchColumn();
                if ($division_id) {
                    update_division_plantation($db, $division_id);
                    update_business_unit_and_company_totals($db, $division_id, false);
                }
            } elseif (post('operation_type') == 'Forestry') {
                $division_id = post('division_id');
                update_division_forestry($db, $division_id);
                update_parent_divisions_forestry($db, $division_id);
                update_business_unit_and_company_totals($db, $division_id, true);
            }
            
            set_message('success', 'Block added successfully!');
            redirect('blocks.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding block: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            // Get old block data before updating
            $old_block_stmt = $db->prepare("SELECT planting_year_id, operation_type FROM blocks WHERE block_id = :id");
            $old_block_stmt->execute([':id' => post('block_id')]);
            $old_block_data = $old_block_stmt->fetch();
            
            // Temporarily disable triggers to avoid recursion error
            $db->exec("SET @DISABLE_TRIGGERS = 1");
            
            // Calculate plant age if planting date is provided
            $plant_age = 0;
            if (post('planting_date')) {
                $plant_age = calculate_plant_age(post('planting_date'));
            }
            
            $stmt = $db->prepare("
                UPDATE blocks
                SET operation_type = :operation_type,
                    ownership_type = :ownership_type,
                    company_id = :company_id,
                    business_unit_id = :business_unit_id,
                    planting_year_id = :planting_year_id, division_id = :division_id,
                    block_code = :block_code, block_name = :block_name,
                    area = :area, planted_area = :planted_area, plant_density = :plant_density,
                    normal_plants = :normal_plants, abnormal_plants = :abnormal_plants,
                    dead_plants = :dead_plants, total_plants = :total_plants,
                    planting_date = :planting_date, plant_age = :plant_age,
                    topography = :topography, soil_type = :soil_type, soil_ph = :soil_ph,
                    elevation_m = :elevation_m, status = :status, harvest_status = :harvest_status,
                    tree_species = :tree_species, stand_density = :stand_density,
                    average_dbh = :average_dbh, volume_m3 = :volume_m3,
                    carbon_stock_ton = :carbon_stock_ton, age_class = :age_class,
                    establishment_year = :establishment_year, forest_type = :forest_type,
                    latitude = :latitude, longitude = :longitude, geojson = :geojson,
                    notes = :notes, updated_by = 'admin'
                WHERE block_id = :id
            ");
            
            $stmt->execute([
                ':id'              => post('block_id'),
                ':operation_type'  => post('operation_type', 'Plantation'),
                ':ownership_type'  => post('ownership_type', 'inti'),
                ':company_id'      => post('company_id') ?: null,
                ':business_unit_id' => post('business_unit_id') ?: null,
                ':planting_year_id' => post('operation_type') == 'Forestry' ? null : (post('planting_year_id') ?: null),
                ':division_id' => post('division_id'),
                ':block_code' => post('block_code'),
                ':block_name' => post('block_name'),
                ':area' => post('area'),
                ':planted_area' => post('planted_area') ?: null,
                ':plant_density' => post('plant_density') ?: null,
                ':normal_plants' => post('normal_plants') ?: null,
                ':abnormal_plants' => post('abnormal_plants') ?: null,
                ':dead_plants' => post('dead_plants') ?: null,
                ':total_plants' => post('total_plants') ?: null,
                ':planting_date' => post('planting_date') ?: null,
                ':plant_age' => $plant_age,
                ':topography' => post('topography'),
                ':soil_type' => post('soil_type') ?: null,
                ':soil_ph' => post('soil_ph') ?: null,
                ':elevation_m' => post('elevation_m') ?: null,
                ':status' => post('status'),
                ':harvest_status' => post('harvest_status'),
                ':tree_species' => post('tree_species') ?: null,
                ':stand_density' => post('stand_density') ?: null,
                ':average_dbh' => post('average_dbh') ?: null,
                ':volume_m3' => post('volume_m3') ?: null,
                ':carbon_stock_ton' => post('carbon_stock_ton') ?: null,
                ':age_class' => post('age_class') ?: null,
                ':establishment_year' => post('establishment_year') ?: null,
                ':forest_type' => post('forest_type') ?: null,
                ':latitude' => post('latitude') ?: null,
                ':longitude' => post('longitude') ?: null,
                ':geojson' => post('geojson') ?: null,
                ':notes' => post('notes') ?: null
            ]);
            
            // Re-enable triggers
            $db->exec("SET @DISABLE_TRIGGERS = NULL");
            
            // Cascade rollup after edit
            if (post('operation_type') == 'Plantation') {
                // Update new planting year
                if (post('planting_year_id')) {
                    $py_id = post('planting_year_id');
                    $db->prepare("
                        UPDATE planting_years py SET
                            py.actual_area   = COALESCE((SELECT SUM(b.area)         FROM blocks b WHERE b.planting_year_id = ?), 0),
                            py.actual_plants = COALESCE((SELECT SUM(b.total_plants) FROM blocks b WHERE b.planting_year_id = ?), 0)
                        WHERE py.planting_year_id = ?
                    ")->execute([$py_id, $py_id, $py_id]);
                    $div_stmt = $db->prepare("SELECT division_id FROM planting_years WHERE planting_year_id = ?");
                    $div_stmt->execute([$py_id]);
                    $division_id = $div_stmt->fetchColumn();
                    if ($division_id) {
                        update_division_plantation($db, $division_id);
                        update_business_unit_and_company_totals($db, $division_id, false);
                    }
                }
                // Also update old planting year if it changed
                if ($old_block_data && $old_block_data['planting_year_id'] &&
                    $old_block_data['planting_year_id'] != post('planting_year_id')) {
                    $old_py_id = $old_block_data['planting_year_id'];
                    $db->prepare("
                        UPDATE planting_years py SET
                            py.actual_area   = COALESCE((SELECT SUM(b.area)         FROM blocks b WHERE b.planting_year_id = ?), 0),
                            py.actual_plants = COALESCE((SELECT SUM(b.total_plants) FROM blocks b WHERE b.planting_year_id = ?), 0)
                        WHERE py.planting_year_id = ?
                    ")->execute([$old_py_id, $old_py_id, $old_py_id]);
                    $old_div_stmt = $db->prepare("SELECT division_id FROM planting_years WHERE planting_year_id = ?");
                    $old_div_stmt->execute([$old_py_id]);
                    $old_division_id = $old_div_stmt->fetchColumn();
                    if ($old_division_id) {
                        update_division_plantation($db, $old_division_id);
                        update_business_unit_and_company_totals($db, $old_division_id, false);
                    }
                }
            } elseif (post('operation_type') == 'Forestry') {
                $division_id = post('division_id');
                update_division_forestry($db, $division_id);
                update_parent_divisions_forestry($db, $division_id);
                update_business_unit_and_company_totals($db, $division_id, true);
                // If division changed, also update old division
                if ($old_block_data && isset($old_block_data['division_id']) &&
                    $old_block_data['division_id'] != $division_id) {
                    $old_division_id = $old_block_data['division_id'];
                    update_division_forestry($db, $old_division_id);
                    update_parent_divisions_forestry($db, $old_division_id);
                    update_business_unit_and_company_totals($db, $old_division_id, true);
                }
            }
            
            // Save plant varieties — replace all existing entries
            $edit_bid = post('block_id');
            $variety_ids = isset($_POST['variety_ids']) ? array_filter(array_map('intval', (array)$_POST['variety_ids'])) : [];
            $db->prepare("DELETE FROM block_plant_varieties WHERE block_id = ?")->execute([$edit_bid]);
            if (!empty($variety_ids)) {
                $ins_v = $db->prepare("INSERT IGNORE INTO block_plant_varieties (block_id, variety_id) VALUES (?, ?)");
                foreach ($variety_ids as $vid) {
                    $ins_v->execute([$edit_bid, $vid]);
                }
            }

            set_message('success', 'Block updated successfully!');
            redirect('blocks.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating block: ' . $e->getMessage());
        } catch (Exception $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            // Get block info before deleting
            $block_stmt = $db->prepare("SELECT planting_year_id, division_id, operation_type FROM blocks WHERE block_id = :id");
            $block_stmt->execute([':id' => post('block_id')]);
            $deleted_block = $block_stmt->fetch();
            
            $stmt = $db->prepare("DELETE FROM blocks WHERE block_id = :id");
            $stmt->execute([':id' => post('block_id')]);
            
            // Cascade rollup after delete
            if ($deleted_block && $deleted_block['operation_type'] == 'Plantation' && $deleted_block['planting_year_id']) {
                $py_id = $deleted_block['planting_year_id'];
                $db->prepare("
                    UPDATE planting_years py SET
                        py.actual_area   = COALESCE((SELECT SUM(b.area)         FROM blocks b WHERE b.planting_year_id = ?), 0),
                        py.actual_plants = COALESCE((SELECT SUM(b.total_plants) FROM blocks b WHERE b.planting_year_id = ?), 0)
                    WHERE py.planting_year_id = ?
                ")->execute([$py_id, $py_id, $py_id]);
                $div_stmt = $db->prepare("SELECT division_id FROM planting_years WHERE planting_year_id = ?");
                $div_stmt->execute([$py_id]);
                $division_id = $div_stmt->fetchColumn();
                if ($division_id) {
                    update_division_plantation($db, $division_id);
                    update_business_unit_and_company_totals($db, $division_id, false);
                }
            } elseif ($deleted_block && $deleted_block['operation_type'] == 'Forestry' && $deleted_block['division_id']) {
                $division_id = $deleted_block['division_id'];
                update_division_forestry($db, $division_id);
                update_parent_divisions_forestry($db, $division_id);
                update_business_unit_and_company_totals($db, $division_id, true);
            }
            
            set_message('success', 'Block deleted successfully!');
            redirect('blocks.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting block: ' . $e->getMessage());
        }
    }
}

// Get block for editing (before header)
$edit_block = null;
$edit_block_variety_ids = [];
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM blocks WHERE block_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_block = $stmt->fetch();

    // Fetch currently assigned varieties for this block
    if ($edit_block) {
        $ev_stmt = $db->prepare("SELECT variety_id FROM block_plant_varieties WHERE block_id = ?");
        $ev_stmt->execute([$edit_block['block_id']]);
        $edit_block_variety_ids = $ev_stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Now include header after form processing
$page_title = "Blocks Management";
require_once 'includes/header.php';
?>
<style>
.btn-agro {
    background-color: #3a618c;
    border-color: #3a618c;
    color: #fff;
}
.btn-agro:hover, .btn-agro:focus {
    background-color: #4d7aaa;
    border-color: #4d7aaa;
    color: #fff;
}
.page-header {
    border-bottom-color: #3a618c !important;
}
</style>
<?php

// ─────────────────────────────────────────────────────────────────────────────
// User scope: restrict data to the company / BU / division assigned to the user.
// Admins see everything; other roles are scoped to their assigned org unit.
// ─────────────────────────────────────────────────────────────────────────────
$is_admin        = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'Admin']);
$scope_company_id   = (!$is_admin && !empty($_SESSION['company_id']))       ? (int)$_SESSION['company_id']       : null;
$scope_bu_id        = (!$is_admin && !empty($_SESSION['business_unit_id'])) ? (int)$_SESSION['business_unit_id'] : null;
$scope_division_id  = (!$is_admin && !empty($_SESSION['division_id']))      ? (int)$_SESSION['division_id']      : null;

// Fetch plant varieties for dropdown
$varieties_stmt = $db->query("
    SELECT variety_id, variety_code, variety_name, category
    FROM plant_varieties
    WHERE status = 'Active'
    ORDER BY category, variety_name
");
$plant_varieties_list = $varieties_stmt->fetchAll();

// Fetch planting years for dropdown (scoped)
$py_scope_where = '1=1';
$py_scope_params = [];
if ($scope_division_id) {
    $py_scope_where = 'd.division_id = :scope_div';
    $py_scope_params[':scope_div'] = $scope_division_id;
} elseif ($scope_bu_id) {
    $py_scope_where = 'bu.business_unit_id = :scope_bu';
    $py_scope_params[':scope_bu'] = $scope_bu_id;
} elseif ($scope_company_id) {
    $py_scope_where = 'c.company_id = :scope_co';
    $py_scope_params[':scope_co'] = $scope_company_id;
}
$planting_years_stmt = $db->prepare("
    SELECT py.planting_year_id, py.year,
           d.division_code, d.division_name,
           bu.unit_code, bu.unit_name,
           c.company_name
    FROM planting_years py
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE $py_scope_where
    ORDER BY py.year DESC, c.company_name, bu.unit_name, d.division_code
");
$planting_years_stmt->execute($py_scope_params);
$planting_years = $planting_years_stmt->fetchAll();

// Fetch companies for dropdown (scoped)
if ($scope_company_id) {
    $companies_stmt = $db->prepare("SELECT company_id, company_name FROM companies WHERE company_id = :cid AND status = 'Active' ORDER BY company_name");
    $companies_stmt->execute([':cid' => $scope_company_id]);
} else {
    $companies_stmt = $db->query("SELECT company_id, company_name FROM companies WHERE status = 'Active' ORDER BY company_name");
}
$companies_list = $companies_stmt->fetchAll();

// Fetch business units for dropdown (scoped)
if ($scope_bu_id) {
    $bu_list_stmt = $db->prepare("SELECT bu.business_unit_id, bu.unit_name, bu.company_id FROM business_units bu WHERE bu.business_unit_id = :buid AND bu.status = 'Active' ORDER BY bu.unit_name");
    $bu_list_stmt->execute([':buid' => $scope_bu_id]);
} elseif ($scope_company_id) {
    $bu_list_stmt = $db->prepare("SELECT bu.business_unit_id, bu.unit_name, bu.company_id FROM business_units bu WHERE bu.company_id = :cid AND bu.status = 'Active' ORDER BY bu.unit_name");
    $bu_list_stmt->execute([':cid' => $scope_company_id]);
} else {
    $bu_list_stmt = $db->query("SELECT bu.business_unit_id, bu.unit_name, bu.company_id FROM business_units bu WHERE bu.status = 'Active' ORDER BY bu.unit_name");
}
$bu_list = $bu_list_stmt->fetchAll();

// Fetch divisions for dropdown (scoped)
$div_scope_where = '1=1';
$div_scope_params = [];
if ($scope_division_id) {
    $div_scope_where = 'd.division_id = :scope_div';
    $div_scope_params[':scope_div'] = $scope_division_id;
} elseif ($scope_bu_id) {
    $div_scope_where = 'bu.business_unit_id = :scope_bu';
    $div_scope_params[':scope_bu'] = $scope_bu_id;
} elseif ($scope_company_id) {
    $div_scope_where = 'c.company_id = :scope_co';
    $div_scope_params[':scope_co'] = $scope_company_id;
}
$divisions_stmt = $db->prepare("
    SELECT d.division_id, d.division_code, d.division_name,
           bu.business_unit_id, bu.unit_name,
           c.company_id, c.company_name
    FROM divisions d
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE $div_scope_where
    ORDER BY c.company_name, bu.unit_name, d.division_code
");
$divisions_stmt->execute($div_scope_params);
$divisions = $divisions_stmt->fetchAll();

// Fetch blocks with statistics
$search               = get('search', '');
$planting_year_filter = get('planting_year_id', '');
$status_filter        = get('status', '');
$harvest_status_filter = get('harvest_status', '');
$topography_filter    = get('topography', '');
$ownership_filter     = get('ownership_type', '');

$sql = "SELECT b.*,
        py.year as planting_year,
        d.division_code, d.division_name,
        bu.unit_code, bu.unit_name,
        c.company_name, c.company_code,
        COALESCE(vas.planted_area, 0)            AS comp_planted_area,
        COALESCE(vas.total_non_planted_area, 0)  AS comp_non_planted_area
        FROM blocks b
        LEFT JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON b.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        LEFT JOIN v_block_areal_statement vas ON b.block_id = vas.block_id
        WHERE 1=1";

// Apply user scope to blocks list
if ($scope_division_id) {
    $sql .= " AND d.division_id = :scope_division_id";
} elseif ($scope_bu_id) {
    $sql .= " AND bu.business_unit_id = :scope_bu_id";
} elseif ($scope_company_id) {
    $sql .= " AND c.company_id = :scope_company_id";
}

if ($search) {
    $sql .= " AND (b.block_code LIKE :search1 OR b.block_name LIKE :search2)";
}
if ($planting_year_filter) {
    $sql .= " AND b.planting_year_id = :planting_year_id";
}
if ($status_filter) {
    $sql .= " AND b.status = :status";
}
if ($harvest_status_filter) {
    $sql .= " AND b.harvest_status = :harvest_status";
}
if ($topography_filter) {
    $sql .= " AND b.topography = :topography";
}
if ($ownership_filter) {
    $sql .= " AND b.ownership_type = :ownership_type";
}

$sql .= " ORDER BY py.year DESC, c.company_name, bu.unit_name, d.division_code, b.block_code";

$stmt = $db->prepare($sql);
// Bind scope parameters
if ($scope_division_id) {
    $stmt->bindValue(':scope_division_id', $scope_division_id, PDO::PARAM_INT);
} elseif ($scope_bu_id) {
    $stmt->bindValue(':scope_bu_id', $scope_bu_id, PDO::PARAM_INT);
} elseif ($scope_company_id) {
    $stmt->bindValue(':scope_company_id', $scope_company_id, PDO::PARAM_INT);
}
if ($search) {
    $stmt->bindValue(':search1', "%$search%");
    $stmt->bindValue(':search2', "%$search%");
}
if ($planting_year_filter) {
    $stmt->bindValue(':planting_year_id', $planting_year_filter);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
if ($harvest_status_filter) {
    $stmt->bindValue(':harvest_status', $harvest_status_filter);
}
if ($topography_filter) {
    $stmt->bindValue(':topography', $topography_filter);
}
if ($ownership_filter) {
    $stmt->bindValue(':ownership_type', $ownership_filter);
}
$stmt->execute();
$blocks = $stmt->fetchAll();

// Calculate summary statistics
$total_blocks         = count($blocks);
$total_area           = array_sum(array_column($blocks, 'area'));
$total_planted_area   = array_sum(array_column($blocks, 'comp_planted_area'));
$total_unplanted_area = array_sum(array_column($blocks, 'comp_non_planted_area'));
$total_plants         = array_sum(array_column($blocks, 'total_plants'));
$tm_blocks            = count(array_filter($blocks, function($b) { return $b['status'] == 'TM'; }));
$tbm_blocks           = count(array_filter($blocks, function($b) { return $b['status'] == 'TBM'; }));
$inti_blocks          = count(array_filter($blocks, function($b) { return $b['ownership_type'] == 'inti'; }));
$plasma_blocks        = count(array_filter($blocks, function($b) { return $b['ownership_type'] == 'plasma'; }));
$inti_area            = array_sum(array_map(function($b) { return $b['ownership_type'] == 'inti'   ? (float)$b['area'] : 0; }, $blocks));
$plasma_area          = array_sum(array_map(function($b) { return $b['ownership_type'] == 'plasma' ? (float)$b['area'] : 0; }, $blocks));
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color: #3a618c;"><i class="bi bi-grid"></i> Blocks Management</h1>
            <p class="text-muted">Manage individual planting blocks and track their status</p>
        </div>
        <div class="col-auto">
            <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-house"></i> Dashboard
            </a>
            <a href="blocks_map.php" class="btn btn-success me-2">
                <i class="bi bi-map"></i> View Map
            </a>
            <button type="button" class="btn btn-agro" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New Block
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card stat-card" style="background-color: #fff; border-left: 4px solid #3a618c;">
            <div class="card-body">
                <h3 style="color: #3a618c;"><?php echo $total_blocks; ?></h3>
                <p class="text-muted"><i class="bi bi-grid"></i> Total Blocks</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card" style="background-color: #fff; border-left: 4px solid #3a618c;">
            <div class="card-body">
                <h3 style="color: #3a618c;"><?php echo format_number($total_area); ?></h3>
                <p class="text-muted"><i class="bi bi-map"></i> Total Area (Ha)</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card" style="background-color: #fff; border-left: 4px solid #3a618c;">
            <div class="card-body">
                <h3 style="color: #3a618c;"><?php echo format_number($total_planted_area); ?></h3>
                <p class="text-muted"><i class="bi bi-flower1"></i> Planted (Ha)</p>
                <small class="text-muted"><?php echo $total_area > 0 ? number_format($total_planted_area / $total_area * 100, 1) : 0; ?>% of total</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card" style="background-color: #fff; border-left: 4px solid #3a618c;">
            <div class="card-body">
                <h3 style="color: #3a618c;"><?php echo format_number($total_unplanted_area); ?></h3>
                <p class="text-muted"><i class="bi bi-slash-circle"></i> Unplanted (Ha)</p>
                <small class="text-muted"><?php echo $total_area > 0 ? number_format($total_unplanted_area / $total_area * 100, 1) : 0; ?>% of total</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card" style="background-color: #fff; border-left: 4px solid #3a618c;">
            <div class="card-body">
                <h3 style="color: #3a618c;"><?php echo format_number($total_plants, 0); ?></h3>
                <p class="text-muted"><i class="bi bi-tree"></i> Total Plants</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card" style="background-color: #fff; border-left: 4px solid #3a618c;">
            <div class="card-body">
                <h3 style="color: #3a618c;"><?php echo $tm_blocks; ?> / <?php echo $tbm_blocks; ?></h3>
                <p class="text-muted"><i class="bi bi-pie-chart"></i> TM / TBM Blocks</p>
            </div>
        </div>
    </div>
</div>

<!-- Ownership Summary -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:46px;height:46px;border-radius:50%;background:#1565c0;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-building text-white fs-5"></i>
                </div>
                <div>
                    <div style="font-size:1.5rem;font-weight:700;line-height:1.1;color:#1565c0"><?php echo $inti_blocks; ?> <small style="font-size:.85rem">blocks</small></div>
                    <div class="text-muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.5px">Inti (Core)</div>
                    <div style="font-size:.82rem"><?php echo format_number($inti_area); ?> ha &nbsp;
                        <span class="text-muted"><?php echo $total_area > 0 ? number_format($inti_area/$total_area*100,1) : 0; ?>%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:46px;height:46px;border-radius:50%;background:#6a1b9a;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-people text-white fs-5"></i>
                </div>
                <div>
                    <div style="font-size:1.5rem;font-weight:700;line-height:1.1;color:#6a1b9a"><?php echo $plasma_blocks; ?> <small style="font-size:.85rem">blocks</small></div>
                    <div class="text-muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.5px">Plasma (Smallholder)</div>
                    <div style="font-size:.82rem"><?php echo format_number($plasma_area); ?> ha &nbsp;
                        <span class="text-muted"><?php echo $total_area > 0 ? number_format($plasma_area/$total_area*100,1) : 0; ?>%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-1" style="font-size:.8rem;">
                    <span><strong>Inti</strong> <?php echo format_number($inti_area); ?> ha</span>
                    <span><strong>Plasma</strong> <?php echo format_number($plasma_area); ?> ha</span>
                </div>
                <?php
                    $pct_inti   = $total_area > 0 ? $inti_area   / $total_area * 100 : 0;
                    $pct_plasma = $total_area > 0 ? $plasma_area / $total_area * 100 : 0;
                ?>
                <div class="progress" style="height:18px;border-radius:6px;overflow:hidden;">
                    <div class="progress-bar" style="width:<?php echo $pct_inti; ?>%;background:#1565c0;font-size:.75rem;" title="Inti <?php echo number_format($pct_inti,1); ?>%">
                        <?php if ($pct_inti > 8): ?>Inti <?php echo number_format($pct_inti,1); ?>%<?php endif; ?>
                    </div>
                    <div class="progress-bar" style="width:<?php echo $pct_plasma; ?>%;background:#6a1b9a;font-size:.75rem;" title="Plasma <?php echo number_format($pct_plasma,1); ?>%">
                        <?php if ($pct_plasma > 8): ?>Plasma <?php echo number_format($pct_plasma,1); ?>%<?php endif; ?>
                    </div>
                </div>
                <div class="text-muted mt-1" style="font-size:.75rem;">Ownership composition by area (ha)</div>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search by code or name..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="planting_year_id">
                    <option value="">All Planting Years</option>
                    <?php foreach ($planting_years as $py): ?>
                        <option value="<?php echo $py['planting_year_id']; ?>" <?php echo $planting_year_filter == $py['planting_year_id'] ? 'selected' : ''; ?>>
                            <?php echo $py['year'] . ' - ' . htmlspecialchars($py['company_name'] . ' - ' . $py['division_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="TBM" <?php echo $status_filter == 'TBM' ? 'selected' : ''; ?>>TBM (Immature)</option>
                    <option value="TM" <?php echo $status_filter == 'TM' ? 'selected' : ''; ?>>TM (Mature)</option>
                    <option value="TR" <?php echo $status_filter == 'TR' ? 'selected' : ''; ?>>TR (Rejuvenation)</option>
                    <option value="Replanting" <?php echo $status_filter == 'Replanting' ? 'selected' : ''; ?>>Replanting</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="harvest_status">
                    <option value="">All Harvest Status</option>
                    <option value="Not Ready" <?php echo $harvest_status_filter == 'Not Ready' ? 'selected' : ''; ?>>Not Ready</option>
                    <option value="Ready" <?php echo $harvest_status_filter == 'Ready' ? 'selected' : ''; ?>>Ready</option>
                    <option value="Harvesting" <?php echo $harvest_status_filter == 'Harvesting' ? 'selected' : ''; ?>>Harvesting</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="ownership_type">
                    <option value="">All Ownership</option>
                    <option value="inti"   <?php echo $ownership_filter == 'inti'   ? 'selected' : ''; ?>>Inti (Core)</option>
                    <option value="plasma" <?php echo $ownership_filter == 'plasma' ? 'selected' : ''; ?>>Plasma (Smallholder)</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-agro"><i class="bi bi-search"></i> Search</button>
                <a href="blocks.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Blocks Table -->
<div class="card">
    <div class="card-header" style="background-color: #3a618c; color: white;">
        <i class="bi bi-list-ul"></i> Blocks List (<?php echo count($blocks); ?> records)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Ownership</th>
                        <th>Code</th>
                        <th>Block Name</th>
                        <th>Location</th>
                        <th>Year</th>
                        <th class="text-end">Total (Ha)</th>
                        <th class="text-end">Planted (Ha)</th>
                        <th class="text-end">Unplanted (Ha)</th>
                        <th class="text-end">Plants</th>
                        <th>Density</th>
                        <th>Age</th>
                        <th>Topography</th>
                        <th>Soil</th>
                        <th>Status</th>
                        <th>Harvest</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($blocks)): ?>
                        <tr>
                            <td colspan="17" class="text-center text-muted">No blocks found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($blocks as $block): ?>
                            <tr>
                                <td>
                                    <?php if ($block['operation_type'] == 'Forestry'): ?>
                                        <span class="badge bg-success">Forestry</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary">Plantation</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($block['ownership_type'] == 'plasma'): ?>
                                        <span class="badge" style="background:#6a1b9a;">Plasma</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#1565c0;">Inti</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($block['block_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($block['block_name']); ?></td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($block['company_code']); ?> - 
                                        <?php echo htmlspecialchars($block['unit_code']); ?><br>
                                        <?php echo htmlspecialchars($block['division_name']); ?>
                                    </small>
                                </td>
                                <td><?php echo $block['planting_year'] ? $block['planting_year'] : '<span class="text-muted">N/A</span>'; ?></td>
                                <td class="text-end"><?php echo format_number($block['area']); ?></td>
                                <?php
                                    $planted   = (float)$block['comp_planted_area'];
                                    $unplanted = (float)$block['comp_non_planted_area'];
                                    $accounted = $planted + $unplanted;
                                    $pct_p     = $block['area'] > 0 ? round($planted   / $block['area'] * 100) : 0;
                                    $pct_u     = $block['area'] > 0 ? round($unplanted / $block['area'] * 100) : 0;
                                    $no_data   = ($accounted == 0);
                                ?>
                                <td class="text-end" <?php if ($no_data): ?>title="Enter data in Block Area Components"<?php endif; ?>>
                                    <?php if ($no_data): ?>
                                        <span class="text-muted" title="No component data">—</span>
                                    <?php else: ?>
                                        <span class="text-success fw-semibold"><?php echo format_number($planted); ?></span>
                                        <br><small class="text-muted"><?php echo $pct_p; ?>%</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end" <?php if ($no_data): ?>title="Enter data in Block Area Components"<?php endif; ?>>
                                    <?php if ($no_data): ?>
                                        <span class="text-muted">—</span>
                                    <?php elseif ($unplanted > 0): ?>
                                        <span class="text-warning fw-semibold"><?php echo format_number($unplanted); ?></span>
                                        <br><small class="text-muted"><?php echo $pct_u; ?>%</small>
                                    <?php else: ?>
                                        <span class="text-muted">0.00</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo format_number($block['total_plants'], 0); ?></td>
                                <td><?php echo $block['plant_density']; ?>/ha</td>
                                <td><?php echo $block['plant_age']; ?> yrs</td>
                                <td><small><?php echo $block['topography']; ?></small></td>
                                <td>
                                    <small>
                                        <?php echo htmlspecialchars($block['soil_type']); ?>
                                        <?php if ($block['soil_ph']): ?>
                                            <br>pH: <?php echo $block['soil_ph']; ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td><?php echo get_status_badge($block['status']); ?></td>
                                <td><?php echo get_status_badge($block['harvest_status']); ?></td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $block['block_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="blocks.php" style="display:inline;" onsubmit="return confirmDelete('Delete this block?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="block_id" value="<?php echo $block['block_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<style>
#addModal .modal-dialog { max-width: 1140px; }
#addModal .form-label { font-size: 12px; font-weight: 600; margin-bottom: 2px; }
#addModal .form-control, #addModal .form-select { font-size: 12px; padding: 4px 8px; height: auto; }
#addModal .form-section { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #6c757d; background: #f8f9fa; padding: 4px 8px; border-radius: 4px; margin: 10px 0 6px; }
#addModal .mb-3 { margin-bottom: 6px !important; }
#addModal .row { --bs-gutter-x: 8px; }
</style>
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="blocks.php" id="blockForm">
                <div class="modal-header py-2">
                    <h6 class="modal-title mb-0">
                        <?php echo $edit_block ? 'Edit Block' : 'Add New Block'; ?>
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-2">
                    <input type="hidden" name="action" value="<?php echo $edit_block ? 'edit' : 'add'; ?>">
                    <?php if ($edit_block): ?>
                        <input type="hidden" name="block_id" value="<?php echo $edit_block['block_id']; ?>">
                    <?php endif; ?>
                    
                    <!-- Row 1: Operation | Ownership | Planting Year | Block Code | Block Name -->
                    <div class="form-section">Basic Information</div>
                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Operation Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="operation_type" id="operation_type" required>
                                <option value="Plantation" <?php echo ($edit_block && $edit_block['operation_type'] == 'Plantation') ? 'selected' : ''; ?>>Plantation</option>
                                <option value="Forestry"   <?php echo ($edit_block && $edit_block['operation_type'] == 'Forestry')   ? 'selected' : ''; ?>>Forestry</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Ownership Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="ownership_type" required>
                                <option value="inti"   <?php echo (!$edit_block || $edit_block['ownership_type'] == 'inti')   ? 'selected' : ''; ?>>Inti (Core)</option>
                                <option value="plasma" <?php echo ($edit_block && $edit_block['ownership_type'] == 'plasma') ? 'selected' : ''; ?>>Plasma (Smallholder)</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Planting Year</label>
                            <select class="form-select" name="planting_year_id" id="planting_year_id">
                                <option value="">— Select —</option>
                                <?php foreach ($planting_years as $py): ?>
                                    <option value="<?php echo $py['planting_year_id']; ?>"
                                        <?php echo ($edit_block && $edit_block['planting_year_id'] == $py['planting_year_id']) ? 'selected' : ''; ?>>
                                        <?php echo $py['year'] . ' — ' . htmlspecialchars($py['unit_name'] . ' / ' . $py['division_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Block Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="block_code" required
                                   value="<?php echo $edit_block ? htmlspecialchars($edit_block['block_code']) : ''; ?>" placeholder="BLK-01">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Block Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="block_name" required
                                   value="<?php echo $edit_block ? htmlspecialchars($edit_block['block_name']) : ''; ?>" placeholder="Block A-01">
                        </div>
                    </div>

                    <!-- Row 2: Company | Business Unit | Division -->
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Company <span class="text-danger">*</span></label>
                            <select class="form-select" name="company_id" id="block_company_id" required>
                                <option value="">— Select —</option>
                                <?php foreach ($companies_list as $comp): ?>
                                    <option value="<?php echo $comp['company_id']; ?>" data-id="<?php echo $comp['company_id']; ?>"
                                        <?php echo ($edit_block && $edit_block['company_id'] == $comp['company_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($comp['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Business Unit <span class="text-danger">*</span></label>
                            <select class="form-select" name="business_unit_id" id="block_business_unit_id" required>
                                <option value="">— Select —</option>
                                <?php foreach ($bu_list as $bu): ?>
                                    <option value="<?php echo $bu['business_unit_id']; ?>" data-company="<?php echo $bu['company_id']; ?>"
                                        <?php echo ($edit_block && $edit_block['business_unit_id'] == $bu['business_unit_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($bu['unit_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Division <span class="text-danger">*</span></label>
                            <select class="form-select" name="division_id" id="block_division_id" required>
                                <option value="">— Select —</option>
                                <?php foreach ($divisions as $div): ?>
                                    <option value="<?php echo $div['division_id']; ?>" data-company="<?php echo $div['company_id']; ?>" data-bu="<?php echo $div['business_unit_id']; ?>"
                                        <?php echo ($edit_block && $edit_block['division_id'] == $div['division_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($div['division_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Row 3: Area | Planted | Density | Status | Harvest Status -->
                    <div class="form-section">Area &amp; Status</div>
                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Area (Ha) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="area" required
                                   value="<?php echo $edit_block ? $edit_block['area'] : ''; ?>" placeholder="0.00">
                        </div>
                        <div class="col-md-2 mb-3" style="display:none;">
                            <label class="form-label">Planted Area (Ha)</label>
                            <input type="number" step="0.01" class="form-control" name="planted_area"
                                   value="<?php echo $edit_block ? $edit_block['planted_area'] : ''; ?>" placeholder="0.00">
                        </div>
                        <div class="col-md-2 mb-3" style="display:none;">
                            <label class="form-label">Density (per Ha)</label>
                            <input type="number" class="form-control" name="plant_density" readonly
                                   value="<?php echo $edit_block ? $edit_block['plant_density'] : '0'; ?>" style="background-color:#e9ecef;">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Block Status</label>
                            <select class="form-select" name="status">
                                <option value="TBM"        <?php echo ($edit_block && $edit_block['status'] == 'TBM')        ? 'selected' : ''; ?>>TBM (Immature)</option>
                                <option value="TM"         <?php echo ($edit_block && $edit_block['status'] == 'TM')         ? 'selected' : ''; ?>>TM (Mature)</option>
                                <option value="TR"         <?php echo ($edit_block && $edit_block['status'] == 'TR')         ? 'selected' : ''; ?>>TR (Rejuvenation)</option>
                                <option value="Replanting" <?php echo ($edit_block && $edit_block['status'] == 'Replanting') ? 'selected' : ''; ?>>Replanting</option>
                                <option value="HL"         <?php echo ($edit_block && $edit_block['status'] == 'HL')         ? 'selected' : ''; ?>>HL</option>
                                <option value="HP"         <?php echo ($edit_block && $edit_block['status'] == 'HP')         ? 'selected' : ''; ?>>HP</option>
                                <option value="HPT"        <?php echo ($edit_block && $edit_block['status'] == 'HPT')        ? 'selected' : ''; ?>>HPT</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Harvest Status</label>
                            <select class="form-select" name="harvest_status">
                                <option value="Not Ready"  <?php echo ($edit_block && $edit_block['harvest_status'] == 'Not Ready')  ? 'selected' : ''; ?>>Not Ready</option>
                                <option value="Ready"      <?php echo ($edit_block && $edit_block['harvest_status'] == 'Ready')      ? 'selected' : ''; ?>>Ready</option>
                                <option value="Harvesting" <?php echo ($edit_block && $edit_block['harvest_status'] == 'Harvesting') ? 'selected' : ''; ?>>Harvesting</option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 4: Planting Date | Age | Normal | Abnormal | Dead | Total -->
                    <div class="form-section">Planting &amp; Plant Health</div>
                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Planting Date</label>
                            <input type="date" class="form-control" name="planting_date"
                                   value="<?php echo $edit_block ? $edit_block['planting_date'] : ''; ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Plant Age (Yrs)</label>
                            <input type="number" class="form-control" name="plant_age" readonly
                                   value="<?php echo $edit_block ? $edit_block['plant_age'] : '0'; ?>" style="background-color:#e9ecef;">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Normal Plants</label>
                            <input type="number" class="form-control" name="normal_plants"
                                   value="<?php echo $edit_block ? $edit_block['normal_plants'] : '0'; ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Abnormal Plants</label>
                            <input type="number" class="form-control" name="abnormal_plants"
                                   value="<?php echo $edit_block ? $edit_block['abnormal_plants'] : '0'; ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Dead Plants</label>
                            <input type="number" class="form-control" name="dead_plants"
                                   value="<?php echo $edit_block ? $edit_block['dead_plants'] : '0'; ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Total Plants</label>
                            <input type="number" class="form-control" name="total_plants" readonly
                                   value="<?php echo $edit_block ? $edit_block['total_plants'] : '0'; ?>" style="background-color:#e9ecef;">
                        </div>
                    </div>

                    <!-- Row 5: Land + GPS -->
                    <div class="form-section">Land &amp; GPS</div>
                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Topography</label>
                            <select class="form-select" name="topography">
                                <option value="Flat"       <?php echo ($edit_block && $edit_block['topography'] == 'Flat')       ? 'selected' : ''; ?>>Flat</option>
                                <option value="Undulating" <?php echo ($edit_block && $edit_block['topography'] == 'Undulating') ? 'selected' : ''; ?>>Undulating</option>
                                <option value="Hilly"      <?php echo ($edit_block && $edit_block['topography'] == 'Hilly')      ? 'selected' : ''; ?>>Hilly</option>
                                <option value="Steep"      <?php echo ($edit_block && $edit_block['topography'] == 'Steep')      ? 'selected' : ''; ?>>Steep</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Soil Type</label>
                            <input type="text" class="form-control" name="soil_type"
                                   value="<?php echo $edit_block ? htmlspecialchars($edit_block['soil_type']) : ''; ?>" placeholder="e.g., Peat">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Soil pH</label>
                            <input type="number" step="0.1" class="form-control" name="soil_ph"
                                   value="<?php echo $edit_block ? $edit_block['soil_ph'] : ''; ?>" placeholder="5.5">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Elevation (m)</label>
                            <input type="number" class="form-control" name="elevation_m"
                                   value="<?php echo $edit_block ? $edit_block['elevation_m'] : ''; ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Latitude</label>
                            <input type="number" step="0.00000001" class="form-control" name="latitude"
                                   value="<?php echo $edit_block ? $edit_block['latitude'] : ''; ?>" placeholder="-0.123456">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Longitude</label>
                            <input type="number" step="0.00000001" class="form-control" name="longitude"
                                   value="<?php echo $edit_block ? $edit_block['longitude'] : ''; ?>" placeholder="101.123456">
                        </div>
                    </div>

                    <!-- Row 6: Plantation / Forestry fields -->
                    <div class="form-section">Plantation Information</div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Plant Varieties</label>
                            <select class="form-select" name="variety_ids[]" id="variety_ids" multiple size="4" style="height:auto;">
                                <?php foreach ($plant_varieties_list as $v): ?>
                                <option value="<?php echo $v['variety_id']; ?>"
                                    <?php echo in_array($v['variety_id'], $edit_block_variety_ids) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars('[' . $v['variety_code'] . '] ' . $v['variety_name'] . ' (' . $v['category'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Hold Ctrl / Cmd to select multiple</small>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Stand Density (trees/ha)</label>
                            <input type="number" class="form-control" name="stand_density"
                                   value="<?php echo $edit_block ? $edit_block['stand_density'] : ''; ?>" placeholder="400">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Avg DBH (cm)</label>
                            <input type="number" step="0.01" class="form-control" name="average_dbh"
                                   value="<?php echo $edit_block ? $edit_block['average_dbh'] : ''; ?>" placeholder="35.5">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Volume (m³)</label>
                            <input type="number" step="0.01" class="form-control" name="volume_m3"
                                   value="<?php echo $edit_block ? $edit_block['volume_m3'] : ''; ?>" placeholder="850.00">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Carbon Stock (tons)</label>
                            <input type="number" step="0.01" class="form-control" name="carbon_stock_ton"
                                   value="<?php echo $edit_block ? $edit_block['carbon_stock_ton'] : ''; ?>" placeholder="425.00">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Age Class</label>
                            <select class="form-select" name="age_class">
                                <option value="">— Select —</option>
                                <option value="Young"  <?php echo ($edit_block && $edit_block['age_class'] == 'Young')  ? 'selected' : ''; ?>>Young (0-10 yrs)</option>
                                <option value="Middle" <?php echo ($edit_block && $edit_block['age_class'] == 'Middle') ? 'selected' : ''; ?>>Middle (11-20 yrs)</option>
                                <option value="Mature" <?php echo ($edit_block && $edit_block['age_class'] == 'Mature') ? 'selected' : ''; ?>>Mature (21-40 yrs)</option>
                                <option value="Old"    <?php echo ($edit_block && $edit_block['age_class'] == 'Old')    ? 'selected' : ''; ?>>Old (40+ yrs)</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Est. Year</label>
                            <input type="number" class="form-control" name="establishment_year"
                                   min="1900" max="<?php echo date('Y'); ?>"
                                   value="<?php echo $edit_block ? $edit_block['establishment_year'] : ''; ?>" placeholder="1995">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Forest Type</label>
                            <select class="form-select" name="forest_type">
                                <option value="">— Select —</option>
                                <option value="Production"   <?php echo ($edit_block && $edit_block['forest_type'] == 'Production')   ? 'selected' : ''; ?>>Production</option>
                                <option value="Protection"   <?php echo ($edit_block && $edit_block['forest_type'] == 'Protection')   ? 'selected' : ''; ?>>Protection</option>
                                <option value="Conservation" <?php echo ($edit_block && $edit_block['forest_type'] == 'Conservation') ? 'selected' : ''; ?>>Conservation</option>
                                <option value="Mixed"        <?php echo ($edit_block && $edit_block['forest_type'] == 'Mixed')        ? 'selected' : ''; ?>>Mixed</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">GeoJSON</label>
                            <textarea class="form-control" name="geojson" rows="1"><?php echo $edit_block ? htmlspecialchars($edit_block['geojson']) : ''; ?></textarea>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"><?php echo $edit_block ? htmlspecialchars($edit_block['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_block ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Confirm delete
function confirmDelete(message) {
    return confirm(message);
}

// Function to setup auto-calculation
function setupCalculations() {
    const areaInput = document.querySelector('input[name="area"]');
    const densityInput = document.querySelector('input[name="plant_density"]');
    const normalPlantsInput = document.querySelector('input[name="normal_plants"]');
    const abnormalPlantsInput = document.querySelector('input[name="abnormal_plants"]');
    const totalPlantsInput = document.querySelector('input[name="total_plants"]');
    
    function calculateTotalAndDensity() {
        if (!areaInput || !densityInput || !normalPlantsInput || !abnormalPlantsInput || !totalPlantsInput) {
            return;
        }
        
        // Calculate total plants = normal + abnormal (dead plants not included)
        const normalPlants = parseInt(normalPlantsInput.value) || 0;
        const abnormalPlants = parseInt(abnormalPlantsInput.value) || 0;
        const totalPlants = normalPlants + abnormalPlants;
        totalPlantsInput.value = totalPlants;
        
        // Calculate density = total plants / area
        const area = parseFloat(areaInput.value) || 0;
        if (area > 0 && totalPlants > 0) {
            const density = Math.round(totalPlants / area);
            densityInput.value = density;
        } else {
            densityInput.value = 0;
        }
    }
    
    if (areaInput && densityInput && normalPlantsInput && abnormalPlantsInput && totalPlantsInput) {
        // Remove existing listeners to avoid duplicates
        areaInput.removeEventListener('input', calculateTotalAndDensity);
        normalPlantsInput.removeEventListener('input', calculateTotalAndDensity);
        abnormalPlantsInput.removeEventListener('input', calculateTotalAndDensity);
        
        // Add new listeners
        areaInput.addEventListener('input', calculateTotalAndDensity);
        normalPlantsInput.addEventListener('input', calculateTotalAndDensity);
        abnormalPlantsInput.addEventListener('input', calculateTotalAndDensity);
        
        // Calculate immediately
        calculateTotalAndDensity();
    }
}

// Setup on page load
document.addEventListener('DOMContentLoaded', function() {
    setupCalculations();
    setupBlockCascade();
});

// Setup when modal is shown (for both add and edit)
document.getElementById('addModal')?.addEventListener('shown.bs.modal', function() {
    setupCalculations();
});

// Cascading Company -> Business Unit -> Division
function setupBlockCascade() {
    const companySelect = document.getElementById('block_company_id');
    const buSelect      = document.getElementById('block_business_unit_id');
    const divSelect     = document.getElementById('block_division_id');
    if (!companySelect || !buSelect || !divSelect) return;

    const allBU  = Array.from(buSelect.options).slice(1);
    const allDiv = Array.from(divSelect.options).slice(1);

    function filterBU() {
        const cid = companySelect.value;
        const prevBU = buSelect.value;
        buSelect.innerHTML = '<option value="">Select Business Unit</option>';
        allBU.forEach(function(opt) {
            if (!cid || opt.dataset.company === cid) {
                buSelect.appendChild(opt.cloneNode(true));
            }
        });
        buSelect.value = prevBU; // restore before cascading
        filterDiv();
    }

    function filterDiv() {
        const bid = buSelect.value;
        const prevDiv = divSelect.value;
        divSelect.innerHTML = '<option value="">Select Division</option>';
        allDiv.forEach(function(opt) {
            if (!bid || opt.dataset.bu === bid) {
                divSelect.appendChild(opt.cloneNode(true));
            }
        });
        divSelect.value = prevDiv; // restore after rebuild
    }

    companySelect.addEventListener('change', filterBU);
    buSelect.addEventListener('change', filterDiv);

    // Apply filters on initial load (for edit mode)
    filterBU();
}
</script>

<?php if ($edit_block): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>