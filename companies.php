<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');

    if ($action == 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO companies (company_code, company_name, legal_name, tax_id, address, city, province,
                                     postal_code, country, phone, email, website, established_date, status, notes, created_by)
                VALUES (:code, :name, :legal_name, :tax_id, :address, :city, :province, :postal_code, :country,
                        :phone, :email, :website, :established_date, :status, :notes, 'admin')
            ");
            $stmt->execute([
                ':code'             => post('company_code'),
                ':name'             => post('company_name'),
                ':legal_name'       => post('legal_name'),
                ':tax_id'           => post('tax_id'),
                ':address'          => post('address'),
                ':city'             => post('city'),
                ':province'         => post('province'),
                ':postal_code'      => post('postal_code'),
                ':country'          => post('country', 'Indonesia'),
                ':phone'            => post('phone'),
                ':email'            => post('email'),
                ':website'          => post('website'),
                ':established_date' => post('established_date'),
                ':status'           => post('status', 'Active'),
                ':notes'            => post('notes')
            ]);
            set_message('success', __('co_msg_added'));
            redirect('companies.php');
        } catch (PDOException $e) {
            set_message('error', __('co_err_add') . $e->getMessage());
        }
    }

    elseif ($action == 'edit') {
        try {
            $company_id = post('company_id');
            if (empty($company_id)) {
                set_message('error', __('co_err_no_id'));
                redirect('companies.php');
            }
            $stmt = $db->prepare("
                UPDATE companies
                SET company_code = :code, company_name = :name, legal_name = :legal_name, tax_id = :tax_id,
                    address = :address, city = :city, province = :province, postal_code = :postal_code,
                    country = :country, phone = :phone, email = :email, website = :website,
                    established_date = :established_date, status = :status, notes = :notes, updated_by = 'admin'
                WHERE company_id = :id
            ");
            $result = $stmt->execute([
                ':id'               => $company_id,
                ':code'             => post('company_code'),
                ':name'             => post('company_name'),
                ':legal_name'       => post('legal_name') ?: null,
                ':tax_id'           => post('tax_id') ?: null,
                ':address'          => post('address') ?: null,
                ':city'             => post('city') ?: null,
                ':province'         => post('province') ?: null,
                ':postal_code'      => post('postal_code') ?: null,
                ':country'          => post('country') ?: 'Indonesia',
                ':phone'            => post('phone') ?: null,
                ':email'            => post('email') ?: null,
                ':website'          => post('website') ?: null,
                ':established_date' => post('established_date') ?: null,
                ':status'           => post('status') ?: 'Active',
                ':notes'            => post('notes') ?: null
            ]);
            set_message('success', $result ? __('co_msg_updated') : __('co_err_update'));
            redirect('companies.php');
        } catch (PDOException $e) {
            set_message('error', __('co_err_update') . $e->getMessage());
        }
    }

    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM companies WHERE company_id = :id");
            $stmt->execute([':id' => post('company_id')]);
            set_message('success', __('co_msg_deleted'));
            redirect('companies.php');
        } catch (PDOException $e) {
            set_message('error', __('co_err_delete') . $e->getMessage());
        }
    }
}

// Get company for editing (before header)
$edit_company = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM companies WHERE company_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_company = $stmt->fetch();
}

$page_title = __('co_page_title');
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
$scope_company_id = $_SESSION['company_id'] ?? null;

// Fetch all companies with statistics
$search        = get('search', '');
$status_filter = get('status', '');

$sql = "SELECT c.*,
        COALESCE(c.total_area_ha, 0) + COALESCE(c.forestry_area_ha, 0) as combined_total_area_ha,
        COALESCE(c.total_plants, 0)         as total_plants,
        COALESCE(c.forestry_area_ha, 0)     as forestry_area_ha,
        COALESCE(c.total_volume_m3, 0)      as total_volume_m3,
        COALESCE(c.total_carbon_stock_ton, 0) as total_carbon_stock_ton,
        COALESCE(c.forestry_blocks, 0)      as forestry_blocks,
        (SELECT COUNT(*) FROM business_units bu WHERE bu.company_id = c.company_id AND bu.parent_unit_id IS NULL) as total_business_units,
        (SELECT COUNT(*) FROM blocks b WHERE b.company_id = c.company_id)                                          as total_blocks,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.company_id = c.company_id AND b.status = 'TM')         as area_tm,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.company_id = c.company_id AND b.status = 'TBM')        as area_tbm,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.company_id = c.company_id AND b.status = 'HL')         as area_hl,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.company_id = c.company_id AND b.status = 'HP')         as area_hp,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.company_id = c.company_id AND b.status = 'HPT')        as area_hpt,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.company_id = c.company_id AND b.status = 'LC')         as area_lc,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.company_id = c.company_id AND b.status = 'Replanting') as area_replanting,
        (SELECT COALESCE(SUM(b.area),0) FROM blocks b WHERE b.company_id = c.company_id AND b.status NOT IN ('TM','TBM','HL','HP','HPT','LC','Replanting')) as area_other
        FROM companies c
        WHERE 1=1";

if ($scope_company_id !== null) { $sql .= " AND c.company_id = :scope_company"; }
if ($search)        { $sql .= " AND (c.company_code LIKE :search1 OR c.company_name LIKE :search2)"; }
if ($status_filter) { $sql .= " AND c.status = :status"; }
$sql .= " ORDER BY c.company_name";

$stmt = $db->prepare($sql);
if ($scope_company_id !== null) { $stmt->bindValue(':scope_company', $scope_company_id, PDO::PARAM_INT); }
if ($search)        { $stmt->bindValue(':search1', "%$search%"); $stmt->bindValue(':search2', "%$search%"); }
if ($status_filter) { $stmt->bindValue(':status', $status_filter); }
$stmt->execute();
$companies = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color: #3a618c;"><i class="bi bi-building"></i> <?= __('co_title') ?></h1>
            <p class="text-muted mb-0"><?= __('co_subtitle') ?></p>
            <?php if ($scope_company_id !== null): ?>
                <small class="text-success"><i class="bi bi-lock-fill"></i> <?= __('co_scope_notice') ?></small>
            <?php endif; ?>
        </div>
        <div class="col-auto">
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> <?= __('co_back_dashboard') ?>
            </a>
            <button type="button" class="btn btn-agro" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> <?= __('co_add_btn') ?>
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<?php
$total_companies    = count($companies);
$total_blocks_sum   = array_sum(array_column($companies, 'total_blocks'));
$total_area         = array_sum(array_map(fn($c) => ($c['total_area_ha'] ?? 0) + ($c['forestry_area_ha'] ?? 0), $companies));
$total_plants       = array_sum(array_column($companies, 'total_plants'));
$total_bus_units    = array_sum(array_column($companies, 'total_business_units'));
?>
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card stat-card" style="background-color:#fff; border-left:4px solid #3a618c;">
            <div class="card-body py-2">
                <h4 class="mb-1" style="color:#3a618c;"><?= $total_companies ?></h4>
                <p class="mb-0 small text-muted"><i class="bi bi-building"></i> <?= __('co_stat_companies') ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <a href="business_units.php" class="text-decoration-none">
            <div class="card stat-card" style="background-color:#fff; border-left:4px solid #3a618c; cursor:pointer;">
                <div class="card-body py-2">
                    <h4 class="mb-1" style="color:#3a618c;"><?= $total_bus_units ?></h4>
                    <p class="mb-0 small text-muted"><i class="bi bi-diagram-3"></i> <?= __('co_stat_business_units') ?></p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" style="background-color:#fff; border-left:4px solid #3a618c;">
            <div class="card-body py-2">
                <h4 class="mb-1" style="color:#3a618c;"><?= format_number($total_area) ?></h4>
                <p class="mb-0 small text-muted"><i class="bi bi-map"></i> <?= __('co_stat_area') ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" style="background-color:#fff; border-left:4px solid #3a618c;">
            <div class="card-body py-2">
                <h4 class="mb-1" style="color:#3a618c;"><?= format_number($total_plants, 0) ?></h4>
                <p class="mb-0 small text-muted"><i class="bi bi-tree"></i> <?= __('co_stat_plants') ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-5">
                <input type="text" class="form-control" name="search"
                       placeholder="<?= __('co_search_placeholder') ?>"
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value=""><?= __('co_all_status') ?></option>
                    <option value="Active"    <?= $status_filter == 'Active'    ? 'selected' : '' ?>><?= __('co_status_active') ?></option>
                    <option value="Inactive"  <?= $status_filter == 'Inactive'  ? 'selected' : '' ?>><?= __('co_status_inactive') ?></option>
                    <option value="Suspended" <?= $status_filter == 'Suspended' ? 'selected' : '' ?>><?= __('co_status_suspended') ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-agro"><i class="bi bi-search"></i> <?= __('co_search_btn') ?></button>
                <a href="companies.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> <?= __('co_reset_btn') ?></a>
                <button type="button" class="btn btn-success" onclick="exportTableToCSV('companies.csv')">
                    <i class="bi bi-file-earmark-excel"></i> <?= __('co_export_btn') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Companies Table -->
<div class="card">
    <div class="card-header" style="background-color:#3a618c; color:white;">
        <i class="bi bi-list-ul"></i> <?= __('co_list_header') ?> (<?= count($companies) ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th><?= __('co_col_code') ?></th>
                        <th><?= __('co_col_name') ?></th>
                        <th><?= __('co_col_province') ?></th>
                        <th><?= __('co_col_bus_units') ?></th>
                        <th><?= __('co_col_blocks') ?></th>
                        <th class="text-end"><?= __('co_col_area') ?></th>
                        <th><?= __('co_col_area_status') ?></th>
                        <th><?= __('co_col_status') ?></th>
                        <th><?= __('co_col_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($companies)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted"><?= __('co_no_data') ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($companies as $company): ?>
                        <?php
                        $row_total_area = ($company['total_area_ha'] ?? 0) + ($company['forestry_area_ha'] ?? 0);
                        $area_statuses = [
                            'TM'         => ['area' => (float)$company['area_tm'],         'color' => 'success'],
                            'TBM'        => ['area' => (float)$company['area_tbm'],        'color' => 'warning'],
                            'HL'         => ['area' => (float)$company['area_hl'],         'color' => 'info'],
                            'HP'         => ['area' => (float)$company['area_hp'],         'color' => 'primary'],
                            'HPT'        => ['area' => (float)$company['area_hpt'],        'color' => 'purple'],
                            'LC'         => ['area' => (float)$company['area_lc'],         'color' => 'secondary'],
                            'Replanting' => ['area' => (float)$company['area_replanting'], 'color' => 'danger'],
                            'Other'      => ['area' => (float)$company['area_other'],      'color' => 'dark'],
                        ];
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($company['company_code']) ?></strong></td>
                            <td>
                                <?= htmlspecialchars($company['company_name']) ?>
                                <?php if (!empty($company['province'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($company['province']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($company['province']) ? htmlspecialchars($company['province']) : '-' ?></td>
                            <td><span class="badge bg-info"><?= $company['total_business_units'] ?></span></td>
                            <td><span class="badge bg-secondary"><?= format_number($company['total_blocks'], 0) ?></span></td>
                            <td class="text-end"><?= format_number($row_total_area) ?></td>
                            <td style="min-width:220px;">
                                <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($area_statuses as $label => $s):
                                    if ($s['area'] <= 0) continue;
                                    $style = $s['color'] === 'purple' ? 'background:#6f42c1;color:#fff;' : '';
                                    $cls   = $s['color'] === 'purple' ? 'badge' : 'badge bg-' . $s['color'];
                                ?>
                                    <span class="<?= $cls ?>" <?= $style ? 'style="'.$style.'"' : '' ?>
                                          title="<?= $label ?>: <?= format_number($s['area']) ?> Ha">
                                        <?= $label ?> <?= format_number($s['area'], 0) ?>
                                    </span>
                                <?php endforeach; ?>
                                </div>
                            </td>
                            <td><?= get_status_badge($company['status']) ?></td>
                            <td>
                                <a href="?action=edit&id=<?= $company['company_id'] ?>" class="btn btn-sm btn-warning" title="<?= __('edit') ?>">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="business_units.php?company_id=<?= $company['company_id'] ?>" class="btn btn-sm btn-info" title="<?= __('co_col_bus_units') ?>">
                                    <i class="bi bi-diagram-3"></i>
                                </a>
                                <form method="POST" action="companies.php" style="display:inline;"
                                      onsubmit="return confirmDelete('<?= addslashes(__('co_confirm_delete')) ?>');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="company_id" value="<?= $company['company_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="<?= __('delete') ?>">
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
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="companies.php">
                <div class="modal-header" style="background-color:<?= $edit_company ? 'olive' : '#3065b0' ?>; color:white;">
                    <h5 class="modal-title">
                        <?= $edit_company ? __('co_modal_edit_title') : __('co_modal_add_title') ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?= $edit_company ? 'edit' : 'add' ?>">
                    <?php if ($edit_company): ?>
                        <input type="hidden" name="company_id" value="<?= $edit_company['company_id'] ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= __('co_field_code') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="company_code" required
                                   value="<?= $edit_company ? htmlspecialchars($edit_company['company_code']) : '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= __('co_field_name') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="company_name" required
                                   value="<?= $edit_company ? htmlspecialchars($edit_company['company_name']) : '' ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= __('co_field_legal') ?></label>
                        <input type="text" class="form-control" name="legal_name"
                               value="<?= $edit_company ? htmlspecialchars($edit_company['legal_name']) : '' ?>">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= __('co_field_tax') ?></label>
                            <input type="text" class="form-control" name="tax_id"
                                   value="<?= $edit_company ? htmlspecialchars($edit_company['tax_id']) : '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= __('co_field_est_date') ?></label>
                            <input type="date" class="form-control" name="established_date"
                                   value="<?= $edit_company ? $edit_company['established_date'] : '' ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= __('co_field_address') ?></label>
                        <textarea class="form-control" name="address" rows="2"><?= $edit_company ? htmlspecialchars($edit_company['address']) : '' ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?= __('co_field_city') ?></label>
                            <input type="text" class="form-control" name="city"
                                   value="<?= $edit_company ? htmlspecialchars($edit_company['city']) : '' ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?= __('co_field_province') ?></label>
                            <input type="text" class="form-control" name="province"
                                   value="<?= $edit_company ? htmlspecialchars($edit_company['province']) : '' ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?= __('co_field_postal') ?></label>
                            <input type="text" class="form-control" name="postal_code"
                                   value="<?= $edit_company ? htmlspecialchars($edit_company['postal_code']) : '' ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?= __('co_field_phone') ?></label>
                            <input type="text" class="form-control" name="phone"
                                   value="<?= $edit_company ? htmlspecialchars($edit_company['phone']) : '' ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?= __('co_field_email') ?></label>
                            <input type="email" class="form-control" name="email"
                                   value="<?= $edit_company ? htmlspecialchars($edit_company['email']) : '' ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?= __('co_field_website') ?></label>
                            <input type="url" class="form-control" name="website"
                                   value="<?= $edit_company ? htmlspecialchars($edit_company['website']) : '' ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= __('co_field_country') ?></label>
                            <input type="text" class="form-control" name="country"
                                   value="<?= $edit_company ? htmlspecialchars($edit_company['country']) : 'Indonesia' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= __('co_field_status') ?></label>
                            <select class="form-select" name="status">
                                <option value="Active"    <?= ($edit_company && $edit_company['status'] == 'Active')    ? 'selected' : '' ?>><?= __('co_status_active') ?></option>
                                <option value="Inactive"  <?= ($edit_company && $edit_company['status'] == 'Inactive')  ? 'selected' : '' ?>><?= __('co_status_inactive') ?></option>
                                <option value="Suspended" <?= ($edit_company && $edit_company['status'] == 'Suspended') ? 'selected' : '' ?>><?= __('co_status_suspended') ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= __('co_field_notes') ?></label>
                        <textarea class="form-control" name="notes" rows="3"><?= $edit_company ? htmlspecialchars($edit_company['notes']) : '' ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('co_modal_cancel') ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?= $edit_company ? __('co_modal_update') : __('co_modal_save') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_company): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

<?php // Powered by IBM Bob ?>