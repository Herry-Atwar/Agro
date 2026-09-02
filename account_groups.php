<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';

$db = getDB();

// ── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO financial_account_groups
                    (group_code, group_name, report_type, report_section,
                     parent_group_id, display_order, is_total_line,
                     calculation_formula, is_active, description)
                VALUES
                    (:group_code, :group_name, :report_type, :report_section,
                     :parent_group_id, :display_order, :is_total_line,
                     :calculation_formula, :is_active, :description)
            ");
            $stmt->execute([
                ':group_code'           => trim($_POST['group_code']),
                ':group_name'           => trim($_POST['group_name']),
                ':report_type'          => $_POST['report_type'],
                ':report_section'       => trim($_POST['report_section']),
                ':parent_group_id'      => $_POST['parent_group_id'] ?: null,
                ':display_order'        => (int)($_POST['display_order'] ?? 0),
                ':is_total_line'        => isset($_POST['is_total_line']) ? 1 : 0,
                ':calculation_formula'  => trim($_POST['calculation_formula'] ?? '') ?: null,
                ':is_active'            => isset($_POST['is_active']) ? 1 : 0,
                ':description'          => trim($_POST['description'] ?? '') ?: null,
            ]);
            set_message('success', __('ag_msg_created'));
            redirect('account_groups.php');
        } catch (PDOException $e) {
            set_message('error', __('ag_err_create') . $e->getMessage());
        }
    }

    elseif ($action === 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE financial_account_groups SET
                    group_code          = :group_code,
                    group_name          = :group_name,
                    report_type         = :report_type,
                    report_section      = :report_section,
                    parent_group_id     = :parent_group_id,
                    display_order       = :display_order,
                    is_total_line       = :is_total_line,
                    calculation_formula = :calculation_formula,
                    is_active           = :is_active,
                    description         = :description
                WHERE id = :id
            ");
            $stmt->execute([
                ':id'                   => (int)$_POST['group_id'],
                ':group_code'           => trim($_POST['group_code']),
                ':group_name'           => trim($_POST['group_name']),
                ':report_type'          => $_POST['report_type'],
                ':report_section'       => trim($_POST['report_section']),
                ':parent_group_id'      => $_POST['parent_group_id'] ?: null,
                ':display_order'        => (int)($_POST['display_order'] ?? 0),
                ':is_total_line'        => isset($_POST['is_total_line']) ? 1 : 0,
                ':calculation_formula'  => trim($_POST['calculation_formula'] ?? '') ?: null,
                ':is_active'            => isset($_POST['is_active']) ? 1 : 0,
                ':description'          => trim($_POST['description'] ?? '') ?: null,
            ]);
            set_message('success', __('ag_msg_updated'));
            redirect('account_groups.php');
        } catch (PDOException $e) {
            set_message('error', __('ag_err_update') . $e->getMessage());
        }
    }

    elseif ($action === 'delete') {
        $id = (int)$_POST['group_id'];
        // Guard: mapped GL accounts
        $mapped = $db->prepare("SELECT COUNT(*) FROM general_ledger_accounts WHERE financial_group_id = :id");
        $mapped->execute([':id' => $id]);
        if ($mapped->fetchColumn() > 0) {
            set_message('error', __('ag_err_mapped_gl'));
        } else {
            // Guard: child groups
            $children = $db->prepare("SELECT COUNT(*) FROM financial_account_groups WHERE parent_group_id = :id");
            $children->execute([':id' => $id]);
            if ($children->fetchColumn() > 0) {
                set_message('error', __('ag_err_has_children'));
            } else {
                $db->prepare("DELETE FROM financial_account_groups WHERE id = :id")->execute([':id' => $id]);
                set_message('success', __('ag_msg_deleted'));
            }
        }
        redirect('account_groups.php');
    }

    elseif ($action === 'save_mapping') {
        // Bulk mapping: array of account_id => group_id (may be empty string to unmap)
        $mappings = $_POST['mapping'] ?? [];
        $stmt = $db->prepare("UPDATE general_ledger_accounts SET financial_group_id = :gid WHERE id = :aid");
        foreach ($mappings as $account_id => $group_id) {
            $stmt->execute([
                ':gid' => $group_id ?: null,
                ':aid' => (int)$account_id,
            ]);
        }
        set_message('success', __('ag_msg_mapped'));
        redirect('account_groups.php?tab=mapping');
    }
}

// ── Data for editing ─────────────────────────────────────────────────────────
$edit_group = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $s = $db->prepare("SELECT * FROM financial_account_groups WHERE id = :id");
    $s->execute([':id' => (int)$_GET['id']]);
    $edit_group = $s->fetch();
}

$active_tab = $_GET['tab'] ?? 'groups';

// ── Fetch all groups ─────────────────────────────────────────────────────────
$search      = trim($_GET['search']      ?? '');
$filter_type = trim($_GET['report_type'] ?? '');
$filter_status = $_GET['status'] ?? '';

$sql = "SELECT g.*,
               p.group_code AS parent_code, p.group_name AS parent_name,
               (SELECT COUNT(*) FROM general_ledger_accounts WHERE financial_group_id = g.id) AS account_count,
               (SELECT COUNT(*) FROM financial_account_groups WHERE parent_group_id = g.id) AS child_count
        FROM financial_account_groups g
        LEFT JOIN financial_account_groups p ON g.parent_group_id = p.id
        WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (g.group_code LIKE :search1 OR g.group_name LIKE :search2)";
    $params[':search1'] = "%$search%";
    $params[':search2'] = "%$search%";
}
if ($filter_type) {
    $sql .= " AND g.report_type = :report_type";
    $params[':report_type'] = $filter_type;
}
if ($filter_status !== '') {
    $sql .= " AND g.is_active = :is_active";
    $params[':is_active'] = (int)$filter_status;
}

$sql .= " ORDER BY g.report_type, g.display_order";
$s = $db->prepare($sql);
$s->execute($params);
$groups = $s->fetchAll();

// ── Statistics ───────────────────────────────────────────────────────────────
$stats = $db->query("
    SELECT
        COUNT(*)                                                    AS total,
        SUM(report_type = 'balance_sheet')                         AS balance_sheet,
        SUM(report_type = 'profit_loss')                           AS profit_loss,
        SUM(is_active = 1)                                         AS active
    FROM financial_account_groups
")->fetch();
$stats['mapped_accounts'] = $db->query("
    SELECT COUNT(*) FROM general_ledger_accounts WHERE financial_group_id IS NOT NULL
")->fetchColumn();

// ── Data for parent dropdown ─────────────────────────────────────────────────
$all_groups = $db->query("
    SELECT id, group_code, group_name, report_type
    FROM financial_account_groups
    WHERE is_total_line = 0
    ORDER BY report_type, display_order
")->fetchAll();

// ── Data for GL account mapping tab ─────────────────────────────────────────
$gl_accounts = $db->query("
    SELECT gla.id, gla.account_code, gla.account_name, gla.account_type, gla.financial_group_id,
           fag.group_name AS current_group_name
    FROM general_ledger_accounts gla
    LEFT JOIN financial_account_groups fag ON gla.financial_group_id = fag.id
    WHERE gla.is_active = 1
    ORDER BY gla.account_code
")->fetchAll();

$page_title = __('ag_title');
require_once 'includes/header.php';
?>

<div class="container-fluid mt-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 style="color:#166c82;"><i class="bi bi-diagram-3"></i> <?php echo __('ag_heading'); ?></h2>
            <p class="text-muted mb-0"><?php echo __('ag_subtitle'); ?></p>
        </div>
        <button class="btn btn-primary" style="background-color:#166c82;border-color:#166c82;"
                data-bs-toggle="modal" data-bs-target="#groupModal" onclick="openAddModal()">
            <i class="bi bi-plus-circle"></i> <?php echo __('ag_add_btn'); ?>
        </button>
    </div>

    <!-- Flash messages -->
    <?php display_message(); ?>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-primary">
                <div class="card-body text-center py-3">
                    <h3 class="text-primary mb-0"><?= $stats['total'] ?></h3>
                    <small class="text-muted"><?php echo __('ag_stat_total'); ?></small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-info">
                <div class="card-body text-center py-3">
                    <h3 class="text-info mb-0"><?= $stats['balance_sheet'] ?></h3>
                    <small class="text-muted"><?php echo __('ag_stat_bs'); ?></small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-warning">
                <div class="card-body text-center py-3">
                    <h3 class="text-warning mb-0"><?= $stats['profit_loss'] ?></h3>
                    <small class="text-muted"><?php echo __('ag_stat_pl'); ?></small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-success">
                <div class="card-body text-center py-3">
                    <h3 class="text-success mb-0"><?= $stats['mapped_accounts'] ?></h3>
                    <small class="text-muted"><?php echo __('ag_stat_mapped'); ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'groups' ? 'active' : '' ?>"
               href="?tab=groups">
                <i class="bi bi-diagram-3"></i> <?php echo __('ag_tab_groups'); ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'mapping' ? 'active' : '' ?>"
               href="?tab=mapping">
                <i class="bi bi-arrow-left-right"></i> <?php echo __('ag_tab_mapping'); ?>
            </a>
        </li>
    </ul>

    <?php if ($active_tab === 'groups'): ?>
    <!-- ── GROUPS TAB ───────────────────────────────────────────────────── -->

    <!-- Search / Filter -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="tab" value="groups">
                <div class="col-md-4">
                    <input type="text" class="form-control" name="search"
                           placeholder="<?php echo __('ag_search_placeholder'); ?>"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="report_type">
                        <option value=""><?php echo __('ag_all_types'); ?></option>
                        <option value="balance_sheet" <?= $filter_type === 'balance_sheet' ? 'selected' : '' ?>><?php echo __('ag_type_bs'); ?></option>
                        <option value="profit_loss"   <?= $filter_type === 'profit_loss'   ? 'selected' : '' ?>><?php echo __('ag_type_pl'); ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="status">
                        <option value=""><?php echo __('ag_all_status'); ?></option>
                        <option value="1" <?= $filter_status === '1' ? 'selected' : '' ?>><?php echo __('ag_status_active'); ?></option>
                        <option value="0" <?= $filter_status === '0' ? 'selected' : '' ?>><?php echo __('ag_status_inactive'); ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary" style="background-color:#166c82;border-color:#166c82;">
                        <i class="bi bi-search"></i> <?php echo __('ag_search_btn'); ?>
                    </button>
                    <a href="?tab=groups" class="btn btn-secondary">
                        <i class="bi bi-arrow-clockwise"></i> <?php echo __('ag_reset_btn'); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Groups Table -->
    <div class="card">
        <div class="card-header text-white" style="background-color:#166c82;">
            <h5 class="mb-0"><i class="bi bi-list-ul"></i> <?php echo __('ag_list_header'); ?> (<?= count($groups) ?>)</h5>
        </div>
        <div class="card-body p-2">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0" style="font-size:0.875rem;">
                    <thead class="table-light">
                        <tr>
                            <th style="width:10%"><?php echo __('ag_col_code'); ?></th>
                            <th style="width:22%"><?php echo __('ag_col_name'); ?></th>
                            <th style="width:12%"><?php echo __('ag_col_type'); ?></th>
                            <th style="width:12%"><?php echo __('ag_col_section'); ?></th>
                            <th style="width:14%"><?php echo __('ag_col_parent'); ?></th>
                            <th style="width:6%"><?php echo __('ag_col_order'); ?></th>
                            <th style="width:8%"><?php echo __('ag_col_accounts'); ?></th>
                            <th style="width:7%"><?php echo __('ag_col_status'); ?></th>
                            <th style="width:5%" class="text-center"><?php echo __('ag_col_edit'); ?></th>
                            <th style="width:4%" class="text-center"><?php echo __('ag_col_del'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($groups)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4"><?php echo __('ag_no_data'); ?></td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($groups as $g): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($g['group_code']) ?></code></td>
                            <td>
                                <strong><?= htmlspecialchars($g['group_name']) ?></strong>
                                <?php if ($g['is_total_line']): ?>
                                    <span class="badge bg-secondary ms-1"><?php echo __('ag_badge_total'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $g['report_type'] === 'balance_sheet' ? 'info' : 'warning' ?>">
                                    <?= $g['report_type'] === 'balance_sheet' ? __('ag_type_bs') : __('ag_type_pl') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($g['report_section']) ?></td>
                            <td>
                                <?php if ($g['parent_code']): ?>
                                    <small><?= htmlspecialchars($g['parent_code'] . ' – ' . $g['parent_name']) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$g['display_order'] ?></td>
                            <td>
                                <span class="badge bg-primary"><?= (int)$g['account_count'] ?></span>
                            </td>
                            <td>
                                <span class="badge bg-<?= $g['is_active'] ? 'success' : 'secondary' ?>">
                                    <?= $g['is_active'] ? __('ag_status_active') : __('ag_status_inactive') ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="?action=edit&id=<?= $g['id'] ?>"
                                   class="btn btn-sm btn-warning" title="<?php echo __('ag_col_edit'); ?>">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </td>
                            <td class="text-center">
                                <form method="POST" action=""
                                      onsubmit="return confirm('<?php echo addslashes(__('ag_confirm_delete')); ?>');">
                                    <input type="hidden" name="action"   value="delete">
                                    <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="<?php echo __('ag_col_del'); ?>">
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

    <?php else: ?>
    <!-- ── MAPPING TAB ──────────────────────────────────────────────────── -->

    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i>
        <?php echo __('ag_map_info'); ?>
    </div>

    <form method="POST" action="">
        <input type="hidden" name="action" value="save_mapping">

        <?php
        // Split into unmapped and mapped
        $unmapped = array_filter($gl_accounts, fn($a) => !$a['financial_group_id']);
        $mapped   = array_filter($gl_accounts, fn($a) =>  $a['financial_group_id']);
        ?>

        <?php if (!empty($unmapped)): ?>
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php echo __('ag_unmapped_header'); ?> (<?= count($unmapped) ?>)
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:15%"><?php echo __('ag_map_col_code'); ?></th>
                                <th style="width:40%"><?php echo __('ag_map_col_name'); ?></th>
                                <th style="width:15%"><?php echo __('ag_map_col_type'); ?></th>
                                <th style="width:30%"><?php echo __('ag_map_col_assign'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unmapped as $acc): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($acc['account_code']) ?></code></td>
                                <td><?= htmlspecialchars($acc['account_name']) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($acc['account_type']) ?></span></td>
                                <td>
                                    <select name="mapping[<?= $acc['id'] ?>]" class="form-select form-select-sm">
                                        <option value=""><?php echo __('ag_select_group'); ?></option>
                                        <?php foreach ($all_groups as $grp): ?>
                                        <option value="<?= $grp['id'] ?>">
                                            <?= htmlspecialchars($grp['group_code'] . ' – ' . $grp['group_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Currently mapped accounts grouped by group -->
        <?php
        // Build group → accounts index
        $by_group = [];
        foreach ($mapped as $acc) {
            $by_group[$acc['financial_group_id']][] = $acc;
        }
        ?>
        <div class="card mb-4">
            <div class="card-header text-white" style="background-color:#166c82;">
                <h5 class="mb-0"><i class="bi bi-table"></i> <?php echo __('ag_mappings_header'); ?></h5>
            </div>
            <div class="card-body">
                <?php if (empty($by_group)): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo __('ag_no_mappings'); ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($all_groups as $grp): ?>
                        <?php if (!isset($by_group[$grp['id']])) continue; ?>
                        <h6 class="border-bottom pb-2 mt-3">
                            <span class="badge bg-<?= $grp['report_type'] === 'balance_sheet' ? 'info' : 'warning' ?>">
                                <?= htmlspecialchars($grp['group_code']) ?>
                            </span>
                            <?= htmlspecialchars($grp['group_name']) ?>
                            <span class="badge bg-primary ms-1"><?= count($by_group[$grp['id']]) ?> <?php echo __('ag_accounts_badge'); ?></span>
                        </h6>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th style="width:15%"><?php echo __('ag_map_col_code'); ?></th>
                                        <th style="width:40%"><?php echo __('ag_map_col_name'); ?></th>
                                        <th style="width:15%"><?php echo __('ag_map_col_type'); ?></th>
                                        <th style="width:30%"><?php echo __('ag_map_col_group'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($by_group[$grp['id']] as $acc): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($acc['account_code']) ?></code></td>
                                        <td><?= htmlspecialchars($acc['account_name']) ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($acc['account_type']) ?></span></td>
                                        <td>
                                            <select name="mapping[<?= $acc['id'] ?>]" class="form-select form-select-sm">
                                                <option value=""><?php echo __('ag_unmap'); ?></option>
                                                <?php foreach ($all_groups as $g2): ?>
                                                <option value="<?= $g2['id'] ?>"
                                                    <?= $g2['id'] == $acc['financial_group_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($g2['group_code'] . ' – ' . $g2['group_name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex justify-content-end mb-4">
            <button type="submit" class="btn btn-primary"
                    style="background-color:#166c82;border-color:#166c82;">
                <i class="bi bi-save"></i> <?php echo __('ag_save_mappings'); ?>
            </button>
        </div>
    </form>

    <?php endif; ?>
</div>

<!-- ── Add / Edit Modal ────────────────────────────────────────────────────── -->
<div class="modal fade" id="groupModal" tabindex="-1" aria-labelledby="groupModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" id="groupForm">
                <input type="hidden" name="action"   id="formAction"  value="add">
                <input type="hidden" name="group_id" id="formGroupId" value="">

                <div class="modal-header" style="background-color:#166c82;color:#fff;">
                    <h5 class="modal-title" id="groupModalLabel"><?php echo __('ag_modal_add_title'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo __('ag_field_code'); ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="group_code" id="fGroupCode"
                                   placeholder="e.g. PL-REV-SALES" required>
                            <small class="text-muted"><?php echo __('ag_field_code_hint'); ?></small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo __('ag_field_name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="group_name" id="fGroupName"
                                   placeholder="e.g. Sales Revenue" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('ag_field_type'); ?> <span class="text-danger">*</span></label>
                            <select class="form-select" name="report_type" id="fReportType" required>
                                <option value=""><?php echo __('ag_field_type_select'); ?></option>
                                <option value="balance_sheet"><?php echo __('ag_type_bs'); ?></option>
                                <option value="profit_loss"><?php echo __('ag_type_pl'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('ag_field_section'); ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="report_section" id="fReportSection"
                                   placeholder="e.g. Revenue" list="sectionSuggestions" required>
                            <small class="text-muted"><?php echo __('ag_field_section_hint'); ?></small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('ag_field_order'); ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="display_order" id="fDisplayOrder"
                                   value="0" min="0" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo __('ag_field_parent'); ?></label>
                            <select class="form-select" name="parent_group_id" id="fParentGroupId">
                                <option value=""><?php echo __('ag_field_no_parent'); ?></option>
                                <?php foreach ($all_groups as $pg): ?>
                                <option value="<?= $pg['id'] ?>">
                                    <?= htmlspecialchars($pg['group_code'] . ' – ' . $pg['group_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo __('ag_field_formula'); ?></label>
                            <input type="text" class="form-control" name="calculation_formula" id="fFormula"
                                   placeholder="e.g. REVENUE - COGS">
                            <small class="text-muted"><?php echo __('ag_field_formula_hint'); ?></small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_total_line"
                                       id="fIsTotalLine" value="1">
                                <label class="form-check-label" for="fIsTotalLine"><?php echo __('ag_field_is_total'); ?></label>
                            </div>
                            <small class="text-muted"><?php echo __('ag_field_is_total_hint'); ?></small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active"
                                       id="fIsActive" value="1" checked>
                                <label class="form-check-label" for="fIsActive"><?php echo __('ag_field_active'); ?></label>
                            </div>
                            <small class="text-muted"><?php echo __('ag_field_active_hint'); ?></small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('ag_field_description'); ?></label>
                        <textarea class="form-control" name="description" id="fDescription" rows="2"
                                  placeholder="<?php echo __('ag_field_desc_placeholder'); ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('ag_modal_cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"
                            style="background-color:#166c82;border-color:#166c82;" id="formSubmitBtn">
                        <i class="bi bi-save"></i> <?php echo __('ag_modal_save'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Section datalists for autocomplete -->
<datalist id="sectionSuggestions">
    <option value="Assets">
    <option value="Liabilities">
    <option value="Equity">
    <option value="Revenue">
    <option value="COGS">
    <option value="Gross Profit">
    <option value="Operating Expenses">
    <option value="Operating Income">
    <option value="Other Income">
    <option value="Other Expenses">
    <option value="Tax">
    <option value="Net Income">
</datalist>

<!-- Edit mode: pre-fill modal from inline edit link -->
<?php if ($edit_group): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var data = <?= json_encode($edit_group) ?>;
    openEditModal(data);
});
</script>
<?php endif; ?>

<script>
const agAddTitle    = '<?php echo addslashes(__('ag_js_add_title')); ?>';
const agEditTitle   = '<?php echo addslashes(__('ag_js_edit_title')); ?>';
const agCreateLabel = '<i class="bi bi-save"></i> <?php echo addslashes(__('ag_modal_create')); ?>';
const agUpdateLabel = '<i class="bi bi-save"></i> <?php echo addslashes(__('ag_modal_update')); ?>';

function openAddModal() {
    document.getElementById('groupModalLabel').textContent = agAddTitle;
    document.getElementById('formAction').value   = 'add';
    document.getElementById('formGroupId').value  = '';
    document.getElementById('formSubmitBtn').innerHTML = agCreateLabel;
    document.getElementById('groupForm').reset();
    document.getElementById('fIsActive').checked = true;
}

function openEditModal(data) {
    document.getElementById('groupModalLabel').textContent = agEditTitle;
    document.getElementById('formAction').value   = 'edit';
    document.getElementById('formGroupId').value  = data.id;
    document.getElementById('formSubmitBtn').innerHTML = agUpdateLabel;

    document.getElementById('fGroupCode').value        = data.group_code        || '';
    document.getElementById('fGroupName').value        = data.group_name        || '';
    document.getElementById('fReportType').value       = data.report_type       || '';
    document.getElementById('fReportSection').value    = data.report_section    || '';
    document.getElementById('fDisplayOrder').value     = data.display_order     || 0;
    document.getElementById('fParentGroupId').value    = data.parent_group_id   || '';
    document.getElementById('fFormula').value          = data.calculation_formula || '';
    document.getElementById('fDescription').value      = data.description       || '';
    document.getElementById('fIsTotalLine').checked    = data.is_total_line == 1;
    document.getElementById('fIsActive').checked       = data.is_active     == 1;

    var modal = new bootstrap.Modal(document.getElementById('groupModal'));
    modal.show();
}
</script>

<style>
.compact-table td, .compact-table th { padding: 0.25rem 0.5rem !important; vertical-align: middle !important; }
</style>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
