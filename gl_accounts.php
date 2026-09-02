<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// ── Session company ───────────────────────────────────────────────────────────
$session_company_id = $_SESSION['company_id'] ?? null;

// ── Check if company_id column exists on general_ledger_accounts ──────────────
$_has_company_col = (bool) $db->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'general_ledger_accounts'
      AND COLUMN_NAME  = 'company_id'
")->fetchColumn();

// ── Companies for dropdowns ───────────────────────────────────────────────────
$companies_list = $_has_company_col
    ? $db->query("SELECT company_id, company_name FROM companies WHERE status='Active' ORDER BY company_name")->fetchAll()
    : [];

// ── Handle POST ───────────────────────────────────────────────────────────────
if (is_post()) {
    $action = post('action');

    // Preserve filter state for redirect-back
    $ret = array_filter([
        'search'       => post('_search', ''),
        'account_type' => post('_account_type', ''),
        'status'       => post('_status', 'active'),
        'company_id'   => post('_company_id', ''),
        'page'         => (int) post('_page', 1) > 1 ? post('_page') : null,
    ], fn($v) => $v !== '' && $v !== null);
    $back = 'gl_accounts.php' . ($ret ? '?' . http_build_query($ret) : '');

    if ($action == 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO general_ledger_accounts
                    (company_id, account_code, account_name, account_type, account_category,
                     parent_account_id, financial_group_id, sap_gl_account,
                     description, is_active, created_at, updated_at)
                VALUES
                    (:company_id, :account_code, :account_name, :account_type, :account_category,
                     :parent_account_id, :financial_group_id, :sap_gl_account,
                     :description, :is_active, NOW(), NOW())
            ");
            $stmt->execute([
                ':company_id'         => post('company_id') ?: null,
                ':account_code'       => post('account_code'),
                ':account_name'       => post('account_name'),
                ':account_type'       => post('account_type'),
                ':account_category'   => post('account_category'),
                ':parent_account_id'  => post('parent_account_id') ?: null,
                ':financial_group_id' => post('financial_group_id') ?: null,
                ':sap_gl_account'     => post('sap_gl_account'),
                ':description'        => post('description'),
                ':is_active'          => post('is_active', 1),
            ]);
            set_message('success', 'GL Account added successfully!');
            redirect($back);
        } catch (PDOException $e) {
            set_message('error', 'Error adding GL account: ' . $e->getMessage());
        }
    }

    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE general_ledger_accounts SET
                    company_id = :company_id,
                    account_code = :account_code, account_name = :account_name,
                    account_type = :account_type, account_category = :account_category,
                    parent_account_id = :parent_account_id, financial_group_id = :financial_group_id,
                    sap_gl_account = :sap_gl_account, description = :description,
                    is_active = :is_active, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':id'                 => post('account_id'),
                ':company_id'         => post('company_id') ?: null,
                ':account_code'       => post('account_code'),
                ':account_name'       => post('account_name'),
                ':account_type'       => post('account_type'),
                ':account_category'   => post('account_category'),
                ':parent_account_id'  => post('parent_account_id') ?: null,
                ':financial_group_id' => post('financial_group_id') ?: null,
                ':sap_gl_account'     => post('sap_gl_account'),
                ':description'        => post('description'),
                ':is_active'          => post('is_active', 1),
            ]);
            set_message('success', 'GL Account updated successfully!');
            redirect($back);
        } catch (PDOException $e) {
            set_message('error', 'Error updating GL account: ' . $e->getMessage());
        }
    }

    elseif ($action == 'delete') {
        try {
            $db->prepare("DELETE FROM general_ledger_accounts WHERE id = :id")
               ->execute([':id' => post('account_id')]);
            set_message('success', 'GL Account deleted successfully!');
            redirect($back);
        } catch (PDOException $e) {
            set_message('error', 'Error deleting GL account: ' . $e->getMessage());
        }
    }
}

$page_title = "General Ledger Accounts";
require_once 'includes/header.php';

// ── Parent accounts for dropdown ──────────────────────────────────────────────
$parent_scope = (!$_has_company_col || !$session_company_id)
    ? "WHERE is_active = 1"
    : "WHERE is_active = 1 AND (company_id = $session_company_id OR company_id IS NULL)";
$parent_accounts = $db->query("
    SELECT id, account_code, account_name
    FROM general_ledger_accounts $parent_scope ORDER BY account_code
")->fetchAll();

// ── Account groups for dropdown ───────────────────────────────────────────────
$account_groups = $db->query("
    SELECT id, group_code, group_name, report_type
    FROM financial_account_groups WHERE is_active = 1 ORDER BY report_type, display_order
")->fetchAll();

// ── Filters ───────────────────────────────────────────────────────────────────
$search         = get('search', '');
$type_filter    = get('account_type', '');
$company_filter = $_has_company_col ? get('company_id', $session_company_id ?? '') : '';
$status_filter  = get('status', 'active');
$per_page       = 50;
$page           = max(1, (int) get('page', 1));

$where = "WHERE 1=1";
if ($search)                          { $where .= " AND (gla.account_code LIKE :sc OR gla.account_name LIKE :sn OR gla.sap_gl_account LIKE :ss)"; }
if ($type_filter)                     { $where .= " AND gla.account_type = :account_type"; }
if ($company_filter && $_has_company_col) { $where .= " AND (gla.company_id = :company_id OR gla.company_id IS NULL)"; }
if ($status_filter === 'active')      { $where .= " AND gla.is_active = 1"; }
elseif ($status_filter === 'inactive'){ $where .= " AND gla.is_active = 0"; }

// Bind helper
$bind = function($stmt) use ($search, $type_filter, $company_filter, $_has_company_col) {
    if ($search)                              { $stmt->bindValue(':sc', "%$search%"); $stmt->bindValue(':sn', "%$search%"); $stmt->bindValue(':ss', "%$search%"); }
    if ($type_filter)                         { $stmt->bindValue(':account_type', $type_filter); }
    if ($company_filter && $_has_company_col) { $stmt->bindValue(':company_id', $company_filter, PDO::PARAM_INT); }
};

$count_stmt = $db->prepare("SELECT COUNT(*) FROM general_ledger_accounts gla $where");
$bind($count_stmt); $count_stmt->execute();
$total_count = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// Main SELECT — only JOIN companies if column exists
$company_join   = $_has_company_col ? "LEFT JOIN companies c ON gla.company_id = c.company_id" : "";
$company_select = $_has_company_col ? ", c.company_name" : ", NULL AS company_name";

$stmt = $db->prepare("
    SELECT gla.*,
           p.account_code AS parent_code, p.account_name AS parent_name,
           fag.group_code, fag.group_name
           $company_select
    FROM general_ledger_accounts gla
    LEFT JOIN general_ledger_accounts p    ON gla.parent_account_id  = p.id
    LEFT JOIN financial_account_groups fag ON gla.financial_group_id = fag.id
    $company_join
    $where
    ORDER BY gla.account_code
    LIMIT :limit OFFSET :offset
");
$bind($stmt);
$stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
$stmt->execute();
$accounts = $stmt->fetchAll();

// ── Pagination URL helper ─────────────────────────────────────────────────────
function gl_page_url(int $p): string {
    $params = array_filter([
        'search'       => get('search', ''),
        'account_type' => get('account_type', ''),
        'status'       => get('status', 'active'),
        'company_id'   => get('company_id', ''),
        'page'         => $p > 1 ? $p : null,
    ], fn($v) => $v !== '' && $v !== null);
    return 'gl_accounts.php' . ($params ? '?' . http_build_query($params) : '');
}

// ── Statistics ────────────────────────────────────────────────────────────────
$stats_where = ($company_filter && $_has_company_col) ? "WHERE (company_id = :cid OR company_id IS NULL)" : "";
$stats_stmt  = $db->prepare("
    SELECT COUNT(*) as total_accounts,
        SUM(account_type='asset')             as assets,
        SUM(account_type='liability')         as liabilities,
        SUM(account_type='equity')            as equity,
        SUM(account_type='revenue')           as revenue,
        SUM(account_type IN ('expense','operating_expense','cogs','other_expenses','tax','depreciation')) as expenses,
        SUM(is_active=1)                      as active_accounts
    FROM general_ledger_accounts $stats_where
");
if ($company_filter) { $stats_stmt->bindValue(':cid', $company_filter, PDO::PARAM_INT); }
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

// Type badge helper
$type_colors = [
    'asset'             => 'success',  'liability'         => 'danger',
    'equity'            => 'info',     'revenue'           => 'warning',
    'cogs'              => 'secondary','operating_expense' => 'secondary',
    'depreciation'      => 'purple',   'other_income'      => 'primary',
    'other_expenses'    => 'dark',     'tax'               => 'dark',
    'expense'           => 'secondary',
];
$type_labels = [
    'asset'             => 'Asset',       'liability'         => 'Liability',
    'equity'            => 'Equity',      'revenue'           => 'Revenue',
    'cogs'              => 'COGS',        'operating_expense' => 'OpEx',
    'depreciation'      => 'D&A',         'other_income'      => 'Oth.Inc',
    'other_expenses'    => 'Oth.Exp',     'tax'               => 'Tax',
    'expense'           => 'Expense',
];
?>

<div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="mb-0" style="color:#166c82;"><i class="bi bi-journal-text"></i> General Ledger Accounts</h5>
    <button type="button" class="btn btn-sm btn-custom-gl" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-circle"></i> Add New GL Account
    </button>
</div>

<!-- Summary Cards -->
<div class="row g-2 mb-3">
    <?php
    $stat_cards = [
        [$stats['total_accounts'], 'Total',       '#3065b0', 'border-primary'],
        [$stats['assets'],         'Assets',      'var(--bs-success)', 'border-success'],
        [$stats['liabilities'],    'Liabilities', 'var(--bs-danger)',  'border-danger'],
        [$stats['equity'],         'Equity',      'var(--bs-info)',    'border-info'],
        [$stats['revenue'],        'Revenue',     'var(--bs-warning)', 'border-warning'],
        [$stats['expenses'],       'Expenses',    'var(--bs-secondary)','border-secondary'],
        [$stats['active_accounts'],'Active',      'var(--bs-success)', 'border-success'],
    ];
    foreach ($stat_cards as [$val, $label, $color, $border]): ?>
    <div class="col">
        <div class="card <?= $border ?> text-center py-1 px-2">
            <div class="fw-bold" style="font-size:1.1rem;color:<?= $color ?>"><?= $val ?></div>
            <div class="text-muted" style="font-size:0.7rem;line-height:1.2"><?= $label ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-3">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="Search code, name, SAP GL…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="company_id">
                    <option value="">All Companies</option>
                    <?php foreach ($companies_list as $co): ?>
                    <option value="<?= $co['company_id'] ?>" <?= $company_filter == $co['company_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($co['company_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm" name="account_type">
                    <option value="">All Types</option>
                    <optgroup label="Balance Sheet">
                        <option value="asset"     <?= $type_filter=='asset'     ?'selected':'' ?>>Asset</option>
                        <option value="liability" <?= $type_filter=='liability' ?'selected':'' ?>>Liability</option>
                        <option value="equity"    <?= $type_filter=='equity'    ?'selected':'' ?>>Equity</option>
                    </optgroup>
                    <optgroup label="Profit &amp; Loss">
                        <option value="revenue"           <?= $type_filter=='revenue'           ?'selected':'' ?>>Revenue</option>
                        <option value="cogs"              <?= $type_filter=='cogs'              ?'selected':'' ?>>COGS</option>
                        <option value="operating_expense" <?= $type_filter=='operating_expense' ?'selected':'' ?>>Operating Expense</option>
                        <option value="depreciation"      <?= $type_filter=='depreciation'      ?'selected':'' ?>>Depreciation &amp; Amortization</option>
                        <option value="other_income"      <?= $type_filter=='other_income'      ?'selected':'' ?>>Other Income</option>
                        <option value="other_expenses"    <?= $type_filter=='other_expenses'    ?'selected':'' ?>>Other Expenses</option>
                        <option value="tax"               <?= $type_filter=='tax'               ?'selected':'' ?>>Tax</option>
                        <option value="expense"           <?= $type_filter=='expense'           ?'selected':'' ?>>Expense (legacy)</option>
                    </optgroup>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="status">
                    <option value="all"      <?= $status_filter==='all'      ?'selected':'' ?>>All Status</option>
                    <option value="active"   <?= $status_filter==='active'   ?'selected':'' ?>>Active</option>
                    <option value="inactive" <?= $status_filter==='inactive' ?'selected':'' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-custom-gl"><i class="bi bi-search"></i> Search</button>
                <a href="gl_accounts.php" class="btn btn-sm btn-secondary ms-1"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if ($status_filter === 'active'): ?>
<div class="alert alert-info py-1 px-3 mb-2" style="font-size:.85rem;">
    <i class="bi bi-info-circle"></i> Showing active accounts only.
    <?php $p = array_filter(['search'=>$search,'account_type'=>$type_filter,'company_id'=>$company_filter,'status'=>'all'],fn($v)=>$v!==''); ?>
    <a href="gl_accounts.php?<?= http_build_query($p) ?>" class="alert-link ms-1">Show all</a>
</div>
<?php endif; ?>

<!-- Table -->
<div class="card">
    <div class="card-header text-white d-flex justify-content-between align-items-center" style="background-color:#166c82;">
        <span><i class="bi bi-list-ul"></i> Chart of Accounts (<?= number_format($total_count) ?>)</span>
        <small class="opacity-75">Page <?= $page ?> / <?= $total_pages ?> &middot; <?= $per_page ?> per page</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th style="width:9%">Code</th>
                        <th style="width:22%">Name</th>
                        <th style="width:8%">Type</th>
                        <th style="width:9%">Group</th>
                        <th style="width:7%">SAP GL</th>
                        <th style="width:14%">Parent</th>
                        <th style="width:13%">Company</th>
                        <th style="width:7%">Status</th>
                        <th style="width:5%" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($accounts)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">No GL accounts found</td></tr>
                <?php else: foreach ($accounts as $a):
                    $color = $type_colors[$a['account_type']] ?? 'secondary';
                    $label = $type_labels[$a['account_type']] ?? ucfirst($a['account_type']);
                ?>
                    <tr>
                        <td><code><?= htmlspecialchars($a['account_code']) ?></code></td>
                        <td>
                            <?php if (empty($a['group_code'])): ?>
                                <strong><?= htmlspecialchars($a['account_name']) ?></strong>
                            <?php else: ?>
                                <?= htmlspecialchars($a['account_name']) ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-<?= $color ?>"><?= $label ?></span></td>
                        <td>
                            <?php if ($a['group_code']): ?>
                                <span class="badge bg-primary" title="<?= htmlspecialchars($a['group_name']) ?>">
                                    <?= htmlspecialchars($a['group_code']) ?>
                                </span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td><code><?= htmlspecialchars($a['sap_gl_account'] ?? '—') ?></code></td>
                        <td>
                            <?php if ($a['parent_code']): ?>
                                <small><?= htmlspecialchars($a['parent_code'] . ' – ' . $a['parent_name']) ?></small>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?= htmlspecialchars($a['company_name'] ?? '—') ?></small></td>
                        <td>
                            <span class="badge bg-<?= $a['is_active'] ? 'success' : 'secondary' ?>">
                                <?= $a['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="text-center" style="white-space:nowrap;">
                            <button type="button" class="btn btn-warning btn-xs"
                                    title="Edit" data-bs-toggle="modal" data-bs-target="#addModal"
                                    data-account='<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Delete this GL account?');">
                                <input type="hidden" name="action"       value="delete">
                                <input type="hidden" name="account_id"   value="<?= $a['id'] ?>">
                                <input type="hidden" name="_search"       value="<?= htmlspecialchars($search) ?>">
                                <input type="hidden" name="_account_type" value="<?= htmlspecialchars($type_filter) ?>">
                                <input type="hidden" name="_status"       value="<?= htmlspecialchars($status_filter) ?>">
                                <input type="hidden" name="_company_id"   value="<?= htmlspecialchars($company_filter) ?>">
                                <input type="hidden" name="_page"         value="<?= $page ?>">
                                <button type="submit" class="btn btn-danger btn-xs" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center py-2">
            <small class="text-muted">
                Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total_count)) ?>
                of <?= number_format($total_count) ?>
            </small>
            <nav><ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= gl_page_url($page - 1) ?>">&laquo;</a>
                </li>
                <?php
                $s = max(1, $page - 3); $e = min($total_pages, $s + 6); $s = max(1, $e - 6);
                if ($s > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                for ($p = $s; $p <= $e; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= gl_page_url($p) ?>"><?= $p ?></a>
                    </li>
                <?php endfor;
                if ($e < $total_pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= gl_page_url($page + 1) ?>">&raquo;</a>
                </li>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gl_accounts.php" id="glAccountForm">
                <input type="hidden" name="_search"       value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="_account_type" value="<?= htmlspecialchars($type_filter) ?>">
                <input type="hidden" name="_status"       value="<?= htmlspecialchars($status_filter) ?>">
                <input type="hidden" name="_company_id"   value="<?= htmlspecialchars($company_filter) ?>">
                <input type="hidden" name="_page"         value="<?= $page ?>">
                <input type="hidden" name="action"     id="modal_action"     value="add">
                <input type="hidden" name="account_id" id="modal_account_id" value="">

                <div class="modal-header py-2" style="background:#166c82;color:#fff;">
                    <h5 class="modal-title mb-0" id="modal_title">Add New GL Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Company</label>
                            <select class="form-select form-select-sm" name="company_id" id="modal_company_id">
                                <option value="">— None —</option>
                                <?php foreach ($companies_list as $co): ?>
                                <option value="<?= $co['company_id'] ?>"
                                    <?= ($session_company_id == $co['company_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($co['company_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Account Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="account_code"
                                   id="modal_account_code" required placeholder="e.g. 1100">
                            <div class="form-text" style="font-size:.72rem;">1xxx Asset · 2xxx Liab · 3xxx Equity · 4xxx Rev · 5xxx Exp</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">SAP GL Account</label>
                            <input type="text" class="form-control form-control-sm" name="sap_gl_account"
                                   id="modal_sap" placeholder="e.g. 100000">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-8">
                            <label class="form-label form-label-sm">Account Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="account_name"
                                   id="modal_account_name" required placeholder="e.g. Cash and Cash Equivalents">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Category</label>
                            <input type="text" class="form-control form-control-sm" name="account_category"
                                   id="modal_account_category" placeholder="e.g. Current Assets">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Account Type <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" name="account_type" id="modal_account_type" required>
                                <option value="">— Select —</option>
                                <optgroup label="Balance Sheet">
                                    <option value="asset">Asset</option>
                                    <option value="liability">Liability</option>
                                    <option value="equity">Equity</option>
                                </optgroup>
                                <optgroup label="Profit &amp; Loss">
                                    <option value="revenue">Revenue</option>
                                    <option value="cogs">COGS</option>
                                    <option value="operating_expense">Operating Expense</option>
                                    <option value="depreciation">Depreciation &amp; Amortization</option>
                                    <option value="other_income">Other Income</option>
                                    <option value="other_expenses">Other Expenses</option>
                                    <option value="tax">Tax Expense</option>
                                    <option value="expense">Expense (legacy)</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Account Group</label>
                            <select class="form-select form-select-sm" name="financial_group_id" id="modal_financial_group_id">
                                <option value="">— None —</option>
                                <?php
                                $cur_rt = '';
                                foreach ($account_groups as $grp):
                                    if ($grp['report_type'] !== $cur_rt):
                                        if ($cur_rt !== '') echo '</optgroup>';
                                        $cur_rt = $grp['report_type'];
                                        $lbl = $cur_rt === 'balance_sheet' ? 'Balance Sheet' : 'Profit & Loss';
                                        echo '<optgroup label="' . htmlspecialchars($lbl) . '">';
                                    endif;
                                ?>
                                    <option value="<?= $grp['id'] ?>">
                                        <?= htmlspecialchars($grp['group_code'] . ' – ' . $grp['group_name']) ?>
                                    </option>
                                <?php endforeach;
                                if ($cur_rt !== '') echo '</optgroup>'; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Parent Account</label>
                            <select class="form-select form-select-sm" name="parent_account_id" id="modal_parent_account_id">
                                <option value="">— None (Top Level) —</option>
                                <?php foreach ($parent_accounts as $par): ?>
                                    <option value="<?= $par['id'] ?>">
                                        <?= htmlspecialchars($par['account_code'] . ' – ' . $par['account_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label form-label-sm">Description</label>
                        <textarea class="form-control form-control-sm" name="description"
                                  id="modal_description" rows="2"></textarea>
                    </div>
                    <div class="mt-2 form-check">
                        <input type="checkbox" name="is_active" class="form-check-input"
                               id="modal_is_active" value="1" checked>
                        <label class="form-check-label form-label-sm" for="modal_is_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-custom-gl">
                        <i class="bi bi-save"></i> <span id="modal_submit_label">Save</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var addModal = document.getElementById('addModal');

// "Add" button — reset to blank
document.querySelector('[data-bs-target="#addModal"]:not([data-account])')
    .addEventListener('click', function () {
        document.getElementById('modal_title').textContent        = 'Add New GL Account';
        document.getElementById('modal_submit_label').textContent = 'Save';
        document.getElementById('modal_action').value      = 'add';
        document.getElementById('modal_account_id').value = '';
        document.getElementById('glAccountForm').reset();
        document.getElementById('modal_is_active').checked = true;
        <?php if ($session_company_id): ?>
        document.getElementById('modal_company_id').value = '<?= $session_company_id ?>';
        <?php endif; ?>
    });

// Edit buttons — populate modal from row data
addModal.addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    if (!btn || !btn.dataset.account) return;
    var a = JSON.parse(btn.dataset.account);
    document.getElementById('modal_title').textContent        = 'Edit GL Account';
    document.getElementById('modal_submit_label').textContent = 'Update';
    document.getElementById('modal_action').value      = 'edit';
    document.getElementById('modal_account_id').value = a.id;
    document.getElementById('modal_company_id').value          = a.company_id          || '';
    document.getElementById('modal_account_code').value        = a.account_code        || '';
    document.getElementById('modal_sap').value                 = a.sap_gl_account      || '';
    document.getElementById('modal_account_name').value        = a.account_name        || '';
    document.getElementById('modal_account_category').value    = a.account_category    || '';
    document.getElementById('modal_account_type').value        = a.account_type        || '';
    document.getElementById('modal_financial_group_id').value  = a.financial_group_id  || '';
    document.getElementById('modal_parent_account_id').value   = a.parent_account_id   || '';
    document.getElementById('modal_description').value         = a.description         || '';
    document.getElementById('modal_is_active').checked         = a.is_active == 1;
});
</script>

<style>
.btn-xs { padding:.1rem .35rem; font-size:.75rem; line-height:1.2; border-radius:.2rem; }
.btn-custom-gl { background-color:#166c82; border-color:#166c82; color:#fff; }
.btn-custom-gl:hover { background-color:#1a7d9a; border-color:#1a7d9a; color:#fff; }
.btn-custom-gl:focus, .btn-custom-gl:active { background-color:#145a6d; border-color:#145a6d; color:#fff; }
.form-label-sm { font-size:.875rem; margin-bottom:.2rem; }
.table .btn-xs:hover { opacity:.85; transform:scale(1.05); transition:all .15s ease; }
</style>

<?php require_once 'includes/footer.php'; ?>
