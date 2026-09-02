<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';

$db = getDB();
$page_title = __('cb_accounts_title');

// ── POST handlers ─────────────────────────────────────────────────────────────
if (is_post()) {
    $action = post('action');

    if ($action === 'add') {
        try {
            $db->prepare("
                INSERT INTO bank_accounts
                    (company_id, business_unit_id, gl_account_id,
                     account_code, account_name, bank_name, account_number, branch,
                     currency_code, account_type, opening_balance, opening_date,
                     status, notes, created_by)
                VALUES (?,?,?, ?,?,?,?,?, ?,?,?,?, ?,?,?)
            ")->execute([
                post('company_id'), post('business_unit_id') ?: null, post('gl_account_id'),
                strtoupper(trim(post('account_code'))), post('account_name'),
                post('bank_name'), post('account_number'), post('branch'),
                post('currency_code') ?: 'IDR', post('account_type'),
                post('opening_balance') ?: 0, post('opening_date') ?: null,
                post('status') ?: 'active', post('notes'),
                $_SESSION['username'] ?? 'admin',
            ]);
            set_message('success', __('cb_acc_msg_added'));
        } catch (PDOException $e) {
            set_message('error', __('cb_acc_err_add') . $e->getMessage());
        }
        redirect('cash_bank_accounts.php');
    }

    if ($action === 'edit') {
        try {
            $db->prepare("
                UPDATE bank_accounts SET
                    company_id=?, business_unit_id=?, gl_account_id=?,
                    account_code=?, account_name=?, bank_name=?, account_number=?, branch=?,
                    currency_code=?, account_type=?, opening_balance=?, opening_date=?,
                    status=?, notes=?, updated_by=?
                WHERE id=?
            ")->execute([
                post('company_id'), post('business_unit_id') ?: null, post('gl_account_id'),
                strtoupper(trim(post('account_code'))), post('account_name'),
                post('bank_name'), post('account_number'), post('branch'),
                post('currency_code') ?: 'IDR', post('account_type'),
                post('opening_balance') ?: 0, post('opening_date') ?: null,
                post('status') ?: 'active', post('notes'),
                $_SESSION['username'] ?? 'admin',
                intval(post('id')),
            ]);
            set_message('success', __('cb_acc_msg_updated'));
        } catch (PDOException $e) {
            set_message('error', __('cb_acc_err_update') . $e->getMessage());
        }
        redirect('cash_bank_accounts.php');
    }

    if ($action === 'delete') {
        try {
            // Safety: only allow delete if no transactions reference it
            $cnt = $db->prepare("SELECT COUNT(*) FROM cash_transactions WHERE bank_account_id=?");
            $cnt->execute([intval(post('id'))]);
            if ($cnt->fetchColumn() > 0) {
                set_message('error', __('cb_acc_err_delete_used'));
            } else {
                $db->prepare("DELETE FROM bank_accounts WHERE id=?")->execute([intval(post('id'))]);
                set_message('success', __('cb_acc_msg_deleted'));
            }
        } catch (PDOException $e) {
            set_message('error', __('cb_acc_err_delete') . $e->getMessage());
        }
        redirect('cash_bank_accounts.php');
    }
}

// ── JSON for edit modal ────────────────────────────────────────────────────────
if (isset($_GET['json_account'])) {
    $row = $db->prepare("SELECT * FROM bank_accounts WHERE id=?");
    $row->execute([intval($_GET['json_account'])]);
    header('Content-Type: application/json');
    echo json_encode($row->fetch(PDO::FETCH_ASSOC) ?: []);
    exit;
}

// ── Fetch data ─────────────────────────────────────────────────────────────────
$accounts = $db->query("
    SELECT ba.*, c.company_name, bu.unit_name AS bu_name,
           gla.account_code AS gl_code, gla.account_name AS gl_name,
           (SELECT COALESCE(
               ba.opening_balance
               + SUM(CASE ct.transaction_type WHEN 'receipt' THEN ct.amount_idr ELSE -ct.amount_idr END)
           , ba.opening_balance)
            FROM cash_transactions ct
            WHERE ct.bank_account_id = ba.id AND ct.status='posted'
           ) AS current_balance
    FROM bank_accounts ba
    JOIN companies c ON ba.company_id = c.company_id
    LEFT JOIN business_units bu ON ba.business_unit_id = bu.business_unit_id
    JOIN general_ledger_accounts gla ON ba.gl_account_id = gla.id
    ORDER BY ba.account_code
")->fetchAll(PDO::FETCH_ASSOC);

$companies   = $db->query("SELECT company_id, company_name FROM companies WHERE status='Active' ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);
$bus         = $db->query("SELECT business_unit_id, unit_name, company_id FROM business_units WHERE status='Active' ORDER BY unit_name")->fetchAll(PDO::FETCH_ASSOC);
$gl_accounts = $db->query("SELECT id, account_code, account_name FROM general_ledger_accounts WHERE account_type='asset' AND account_code LIKE '111%' AND is_active=1 ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);

$currencies = ['IDR','USD','EUR','MYR','SGD','JPY','GBP','AUD'];

require_once 'includes/header.php';
?>

<div class="content-wrapper">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-bank2"></i> <?php echo __('cb_accounts_title'); ?></h1>
                <p class="text-muted mb-0"><?php echo __('cb_accounts_subtitle'); ?></p>
            </div>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="bi bi-plus-lg"></i> <?php echo __('cb_acc_add_btn'); ?>
            </button>
        </div>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <?php
        $total    = count($accounts);
        $active   = count(array_filter($accounts, fn($a) => $a['status'] === 'active'));
        $tot_idr  = array_sum(array_column($accounts, 'current_balance'));
        ?>
        <div class="col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <h3><?php echo $total; ?></h3>
                    <p><?php echo __('cb_acc_stat_total'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <h3><?php echo $active; ?></h3>
                    <p><?php echo __('cb_acc_stat_active'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <h3 style="font-size:1.4rem;">Rp <?php echo number_format($tot_idr, 0, ',', '.'); ?></h3>
                    <p><?php echo __('cb_acc_stat_balance'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Accounts table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul"></i> <?php echo __('cb_acc_list_header'); ?></span>
            <small class="text-white-50"><?php echo $total; ?> <?php echo __('records'); ?></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th><?php echo __('cb_acc_col_code'); ?></th>
                            <th><?php echo __('cb_acc_col_name'); ?></th>
                            <th><?php echo __('cb_acc_col_bank'); ?></th>
                            <th><?php echo __('cb_acc_col_currency'); ?></th>
                            <th><?php echo __('cb_acc_col_type'); ?></th>
                            <th><?php echo __('cb_acc_col_gl'); ?></th>
                            <th><?php echo __('cb_acc_col_company'); ?></th>
                            <th class="text-end"><?php echo __('cb_acc_col_balance'); ?></th>
                            <th><?php echo __('cb_acc_col_status'); ?></th>
                            <th><?php echo __('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($accounts)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4"><?php echo __('no_data'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($accounts as $a): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($a['account_code']); ?></code></td>
                            <td><strong><?php echo htmlspecialchars($a['account_name']); ?></strong>
                                <?php if ($a['account_number']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($a['account_number']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($a['bank_name'] ?? '—'); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($a['currency_code']); ?></span></td>
                            <td>
                                <?php
                                $type_icons = ['cash'=>'bi-cash-coin','current'=>'bi-bank','payroll'=>'bi-people','savings'=>'bi-piggy-bank'];
                                $icon = $type_icons[$a['account_type']] ?? 'bi-bank';
                                ?>
                                <i class="bi <?php echo $icon; ?>"></i>
                                <?php echo ucfirst($a['account_type']); ?>
                            </td>
                            <td><small><?php echo htmlspecialchars($a['gl_code'].' '.$a['gl_name']); ?></small></td>
                            <td>
                                <small><?php echo htmlspecialchars($a['company_name']); ?></small>
                                <?php if ($a['bu_name']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($a['bu_name']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <strong class="<?php echo ($a['current_balance'] < 0) ? 'text-danger' : ''; ?>">
                                    <?php echo $a['currency_code']; ?> <?php echo number_format($a['current_balance'] ?? 0, 2, ',', '.'); ?>
                                </strong>
                            </td>
                            <td>
                                <?php if ($a['status'] === 'active'): ?>
                                <span class="badge bg-success"><?php echo __('active'); ?></span>
                                <?php else: ?>
                                <span class="badge bg-secondary"><?php echo __('inactive'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary me-1"
                                    onclick="openEditModal(<?php echo $a['id']; ?>)"
                                    title="<?php echo __('edit'); ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger"
                                    onclick="confirmDelete(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars($a['account_name'], ENT_QUOTES); ?>')"
                                    title="<?php echo __('delete'); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="accountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="accountModalTitle"><?php echo __('cb_acc_modal_add_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="accountForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id"     id="formId"     value="">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php echo __('cb_acc_field_code'); ?> *</label>
                            <input type="text" name="account_code" id="fCode" class="form-control text-uppercase" required maxlength="20" placeholder="BA-OPS-01">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold"><?php echo __('cb_acc_field_name'); ?> *</label>
                            <input type="text" name="account_name" id="fName" class="form-control" required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo __('company'); ?> *</label>
                            <select name="company_id" id="fCompany" class="form-select" required onchange="filterBU(this.value)">
                                <option value=""><?php echo __('select_company'); ?></option>
                                <?php foreach ($companies as $c): ?>
                                <option value="<?php echo $c['company_id']; ?>"><?php echo htmlspecialchars($c['company_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('business_unit'); ?></label>
                            <select name="business_unit_id" id="fBU" class="form-select">
                                <option value=""><?php echo __('select_business_unit'); ?></option>
                                <?php foreach ($bus as $b): ?>
                                <option value="<?php echo $b['business_unit_id']; ?>" data-company="<?php echo $b['company_id']; ?>">
                                    <?php echo htmlspecialchars($b['unit_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo __('cb_acc_field_gl'); ?> *</label>
                            <select name="gl_account_id" id="fGL" class="form-select" required>
                                <option value="">— Select GL Account —</option>
                                <?php foreach ($gl_accounts as $g): ?>
                                <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['account_code'].' – '.$g['account_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Cash / bank GL accounts (1111–1119)</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?php echo __('cb_acc_field_type'); ?> *</label>
                            <select name="account_type" id="fType" class="form-select" required>
                                <option value="current">Current Account</option>
                                <option value="cash">Petty Cash</option>
                                <option value="payroll">Payroll Account</option>
                                <option value="savings">Savings Account</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?php echo __('cb_acc_field_currency'); ?> *</label>
                            <select name="currency_code" id="fCurrency" class="form-select" required>
                                <?php foreach ($currencies as $cur): ?>
                                <option value="<?php echo $cur; ?>" <?php echo ($cur === 'IDR') ? 'selected' : ''; ?>><?php echo $cur; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('cb_acc_field_bank'); ?></label>
                            <input type="text" name="bank_name" id="fBankName" class="form-control" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('cb_acc_field_account_no'); ?></label>
                            <input type="text" name="account_number" id="fAccNo" class="form-control" maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('cb_acc_field_branch'); ?></label>
                            <input type="text" name="branch" id="fBranch" class="form-control" maxlength="100">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('cb_acc_field_opening_bal'); ?></label>
                            <input type="number" name="opening_balance" id="fOpenBal" class="form-control" value="0" step="0.01">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('cb_acc_field_opening_date'); ?></label>
                            <input type="date" name="opening_date" id="fOpenDate" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('status'); ?></label>
                            <select name="status" id="fStatus" class="form-select">
                                <option value="active"><?php echo __('active'); ?></option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('cb_acc_field_notes'); ?></label>
                            <textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="bi bi-save"></i> <?php echo __('save'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete form -->
<form method="POST" id="deleteForm">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id"     id="deleteId" value="">
</form>

<script>
const addTitle  = <?php echo json_encode(__('cb_acc_modal_add_title')); ?>;
const editTitle = <?php echo json_encode(__('cb_acc_modal_edit_title')); ?>;

function openAddModal() {
    document.getElementById('accountModalTitle').textContent = addTitle;
    document.getElementById('formAction').value = 'add';
    document.getElementById('accountForm').reset();
    document.getElementById('fCurrency').value = 'IDR';
    document.getElementById('fType').value     = 'current';
    document.getElementById('fStatus').value   = 'active';
    filterBU('');
    new bootstrap.Modal(document.getElementById('accountModal')).show();
}

function openEditModal(id) {
    fetch('cash_bank_accounts.php?json_account=' + id)
        .then(r => r.json())
        .then(d => {
            document.getElementById('accountModalTitle').textContent = editTitle;
            document.getElementById('formAction').value   = 'edit';
            document.getElementById('formId').value       = d.id;
            document.getElementById('fCode').value        = d.account_code;
            document.getElementById('fName').value        = d.account_name;
            document.getElementById('fCompany').value     = d.company_id;
            filterBU(d.company_id);
            document.getElementById('fBU').value          = d.business_unit_id || '';
            document.getElementById('fGL').value          = d.gl_account_id;
            document.getElementById('fType').value        = d.account_type;
            document.getElementById('fCurrency').value    = d.currency_code;
            document.getElementById('fBankName').value    = d.bank_name || '';
            document.getElementById('fAccNo').value       = d.account_number || '';
            document.getElementById('fBranch').value      = d.branch || '';
            document.getElementById('fOpenBal').value     = d.opening_balance;
            document.getElementById('fOpenDate').value    = d.opening_date || '';
            document.getElementById('fStatus').value      = d.status;
            document.getElementById('fNotes').value       = d.notes || '';
            new bootstrap.Modal(document.getElementById('accountModal')).show();
        });
}

function filterBU(companyId) {
    const sel = document.getElementById('fBU');
    Array.from(sel.options).forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (!companyId || opt.dataset.company == companyId) ? '' : 'none';
    });
    sel.value = '';
}

function confirmDelete(id, name) {
    if (confirm(<?php echo json_encode(__('confirm_delete')); ?> + '\n' + name)) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
