<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';

$db = getDB();
$page_title = __('cb_txn_title');
$username   = $_SESSION['username'] ?? 'admin';

// ── Helper: generate reference number ────────────────────────────────────────
function generate_cb_reference(PDO $db, string $type, string $date): string {
    $prefix     = ($type === 'receipt' ? 'CR' : 'CP') . '-' . date('Ym', strtotime($date));
    $stmt       = $db->prepare("SELECT reference_number FROM cash_transactions WHERE reference_number LIKE ? ORDER BY reference_number DESC LIMIT 1");
    $stmt->execute(["{$prefix}-%"]);
    $last       = $stmt->fetchColumn();
    $seq        = $last ? (intval(substr($last, -4)) + 1) : 1;
    return sprintf('%s-%04d', $prefix, $seq);
}

// ── Helper: create balanced journal entry for a cash transaction ──────────────
function create_cash_journal_entry(PDO $db, array $ct, string $username): int {
    // Determine DR / CR sides
    // Receipt  → DR Bank account GL  / CR Contra GL (income / AR)
    // Payment  → DR Contra GL (expense / AP) / CR Bank account GL
    $bank_gl_id    = $ct['bank_gl_id'];
    $contra_gl_id  = $ct['contra_gl_account_id'];
    $amount_idr    = round($ct['amount_foreign'] * $ct['exchange_rate'], 2);

    if ($ct['transaction_type'] === 'receipt') {
        $debit_gl  = $bank_gl_id;
        $credit_gl = $contra_gl_id;
    } else {
        $debit_gl  = $contra_gl_id;
        $credit_gl = $bank_gl_id;
    }

    // JE prefix same convention as journal_entries.php
    $year_month = date('Ym', strtotime($ct['transaction_date']));
    $je_prefix  = 'CB-' . $year_month;
    $stmt       = $db->prepare("SELECT reference_number FROM journal_entries WHERE reference_number LIKE ? ORDER BY reference_number DESC LIMIT 1");
    $stmt->execute(["{$je_prefix}-%"]);
    $last       = $stmt->fetchColumn();
    $seq        = $last ? (intval(substr($last, -4)) + 1) : 1;
    $je_ref     = sprintf('%s-%04d', $je_prefix, $seq);

    $db->prepare("
        INSERT INTO journal_entries
            (entry_date, entry_type, reference_number, description,
             company_id, business_unit_id, division_id,
             currency_code, exchange_rate,
             total_debit, total_credit,
             status, posted_date, posted_by, created_by)
        VALUES (?,?,?,?, ?,?,?, ?,?, ?,?, 'posted', NOW(),?,?)
    ")->execute([
        $ct['transaction_date'],
        'other',
        $je_ref,
        $ct['description'],
        $ct['company_id'],
        $ct['business_unit_id'] ?: null,
        $ct['division_id']      ?: null,
        $ct['currency_code'],
        $ct['exchange_rate'],
        $amount_idr,
        $amount_idr,
        $username,
        $username,
    ]);
    $je_id = (int) $db->lastInsertId();

    // Line 1 – Debit
    $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, line_number, gl_account_id, debit_amount, credit_amount, description, company_id, business_unit_id, division_id) VALUES (?,1,?,?,0,?,?,?,?)")
       ->execute([$je_id, $debit_gl, $amount_idr, $ct['description'], $ct['company_id'], $ct['business_unit_id'] ?: null, $ct['division_id'] ?: null]);

    // Line 2 – Credit
    $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, line_number, gl_account_id, debit_amount, credit_amount, description, company_id, business_unit_id, division_id) VALUES (?,2,?,0,?,?,?,?,?)")
       ->execute([$je_id, $credit_gl, $amount_idr, $ct['description'], $ct['company_id'], $ct['business_unit_id'] ?: null, $ct['division_id'] ?: null]);

    return $je_id;
}

// ── POST: create ──────────────────────────────────────────────────────────────
if (is_post()) {
    $action = post('action');

    if ($action === 'create') {
        try {
            $db->beginTransaction();

            $txn_type  = post('transaction_type');
            $txn_date  = post('transaction_date');
            $ref       = generate_cb_reference($db, $txn_type, $txn_date);

            // Fetch bank GL account id
            $ba        = $db->prepare("SELECT gl_account_id FROM bank_accounts WHERE id=?");
            $ba->execute([intval(post('bank_account_id'))]);
            $bank_gl   = $ba->fetchColumn();
            if (!$bank_gl) throw new Exception('Bank account not found.');

            $db->prepare("
                INSERT INTO cash_transactions
                    (reference_number, transaction_type, transaction_date,
                     bank_account_id, company_id, business_unit_id, division_id,
                     currency_code, amount_foreign, exchange_rate, rate_date, rate_source,
                     cf_category, cf_subcategory_id,
                     payee_payer_name, customer_id, sale_id,
                     description, notes, attachment_path,
                     contra_gl_account_id,
                     status, created_by)
                VALUES (?,?,?, ?,?,?,?, ?,?,?,?,?, ?,?, ?,?,?, ?,?,?, ?, 'draft',?)
            ")->execute([
                $ref, $txn_type, $txn_date,
                intval(post('bank_account_id')), intval(post('company_id')),
                post('business_unit_id') ?: null, post('division_id') ?: null,
                post('currency_code') ?: 'IDR',
                floatval(post('amount_foreign')),
                floatval(post('exchange_rate') ?: 1),
                post('rate_date')   ?: null,
                post('rate_source') ?: null,
                post('cf_category'),
                post('cf_subcategory_id') ?: null,
                post('payee_payer_name') ?: null,
                post('customer_id')      ?: null,
                post('sale_id')          ?: null,
                post('description'),
                post('notes')            ?: null,
                post('attachment_path')  ?: null,
                intval(post('contra_gl_account_id')),
                $username,
            ]);
            $ct_id = (int) $db->lastInsertId();
            $db->commit();
            set_message('success', sprintf(__('cb_txn_msg_created'), $ref));
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', __('cb_txn_err_create') . $e->getMessage());
        }
        redirect('cash_transactions.php');
    }

    if ($action === 'post') {
        $ct_id = intval(post('id'));
        try {
            $db->beginTransaction();
            $ct = $db->prepare("SELECT ct.*, ba.gl_account_id AS bank_gl_id FROM cash_transactions ct JOIN bank_accounts ba ON ct.bank_account_id=ba.id WHERE ct.id=? AND ct.status='draft'");
            $ct->execute([$ct_id]);
            $row = $ct->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception(__('cb_txn_err_not_draft'));

            $je_id = create_cash_journal_entry($db, $row, $username);

            $db->prepare("UPDATE cash_transactions SET status='posted', journal_entry_id=?, posted_by=?, posted_at=NOW() WHERE id=?")
               ->execute([$je_id, $username, $ct_id]);

            $db->commit();
            set_message('success', __('cb_txn_msg_posted'));
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', __('cb_txn_err_post') . $e->getMessage());
        }
        redirect('cash_transactions.php');
    }

    if ($action === 'cancel') {
        $ct_id = intval(post('id'));
        try {
            $db->prepare("UPDATE cash_transactions SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), cancel_reason=? WHERE id=? AND status='draft'")
               ->execute([$username, post('cancel_reason'), $ct_id]);
            set_message('success', __('cb_txn_msg_cancelled'));
        } catch (PDOException $e) {
            set_message('error', __('cb_txn_err_cancel') . $e->getMessage());
        }
        redirect('cash_transactions.php');
    }
}

// ── JSON: fetch latest exchange rate ──────────────────────────────────────────
if (isset($_GET['fx_rate'])) {
    $cur  = strtoupper(trim($_GET['fx_rate']));
    $stmt = $db->prepare("SELECT rate, rate_date FROM exchange_rates WHERE currency_from=? AND currency_to='IDR' ORDER BY rate_date DESC LIMIT 1");
    $stmt->execute([$cur]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: ['rate' => 1, 'rate_date' => date('Y-m-d')]);
    exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$f_type    = trim($_GET['type']       ?? '');
$f_status  = trim($_GET['status']     ?? '');
$f_bank    = intval($_GET['bank']     ?? 0);
$f_company = intval($_GET['company']  ?? 0);
$f_from    = trim($_GET['date_from']  ?? date('Y-m-01'));
$f_to      = trim($_GET['date_to']    ?? date('Y-m-d'));
$f_search  = trim($_GET['search']     ?? '');

$where  = ['1=1'];
$params = [];
if ($f_type)    { $where[] = 'ct.transaction_type=?';   $params[] = $f_type; }
if ($f_status)  { $where[] = 'ct.status=?';             $params[] = $f_status; }
if ($f_bank)    { $where[] = 'ct.bank_account_id=?';    $params[] = $f_bank; }
if ($f_company) { $where[] = 'ct.company_id=?';         $params[] = $f_company; }
if ($f_from)    { $where[] = 'ct.transaction_date>=?';  $params[] = $f_from; }
if ($f_to)      { $where[] = 'ct.transaction_date<=?';  $params[] = $f_to; }
if ($f_search)  { $where[] = '(ct.reference_number LIKE ? OR ct.description LIKE ? OR ct.payee_payer_name LIKE ?)';
                  $params[] = "%$f_search%"; $params[] = "%$f_search%"; $params[] = "%$f_search%"; }

$sql = "
    SELECT ct.*,
           ba.account_name  AS bank_account_name, ba.currency_code AS bank_currency,
           cs.name          AS cf_subcat_name,
           c.company_name,
           gla.account_code AS contra_gl_code, gla.account_name AS contra_gl_name
    FROM   cash_transactions ct
    JOIN   bank_accounts ba         ON ct.bank_account_id      = ba.id
    LEFT JOIN cf_subcategories cs   ON ct.cf_subcategory_id    = cs.id
    JOIN   companies c              ON ct.company_id           = c.company_id
    JOIN   general_ledger_accounts gla ON ct.contra_gl_account_id = gla.id
    WHERE  " . implode(' AND ', $where) . "
    ORDER  BY ct.transaction_date DESC, ct.id DESC
    LIMIT  200
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = $db->prepare("
    SELECT
        COUNT(*)                                                          AS total,
        SUM(status='draft')                                              AS drafts,
        SUM(status='posted')                                             AS posted,
        SUM(CASE WHEN status='posted' AND transaction_type='receipt' THEN amount_idr ELSE 0 END) AS total_receipts,
        SUM(CASE WHEN status='posted' AND transaction_type='payment' THEN amount_idr ELSE 0 END) AS total_payments
    FROM cash_transactions ct
    WHERE ct.transaction_date BETWEEN ? AND ?
    " . ($f_company ? "AND ct.company_id=$f_company" : '') . "
");
$stats->execute([$f_from, $f_to]);
$st = $stats->fetch(PDO::FETCH_ASSOC);

// Dropdown data
$bank_accounts   = $db->query("SELECT ba.id, ba.account_code, ba.account_name, ba.currency_code, ba.gl_account_id, ba.company_id, ba.business_unit_id FROM bank_accounts ba WHERE ba.status='active' ORDER BY ba.account_code")->fetchAll(PDO::FETCH_ASSOC);
$companies       = $db->query("SELECT company_id, company_name FROM companies WHERE status='Active' ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);
$bus             = $db->query("SELECT business_unit_id, unit_name, company_id FROM business_units WHERE status='Active' ORDER BY unit_name")->fetchAll(PDO::FETCH_ASSOC);
$divisions       = $db->query("SELECT division_id, division_name, business_unit_id FROM divisions ORDER BY division_name")->fetchAll(PDO::FETCH_ASSOC);
$gl_accounts     = $db->query("SELECT id, account_code, account_name, account_type FROM general_ledger_accounts WHERE is_active=1 ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
$cf_subcats      = $db->query("SELECT id, cf_category, code, name FROM cf_subcategories WHERE is_active=1 ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);
$currencies      = ['IDR','USD','EUR','MYR','SGD','JPY','GBP','AUD'];

require_once 'includes/header.php';
?>

<div class="content-wrapper">
    <!-- Page header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-arrow-left-right"></i> <?php echo __('cb_txn_title'); ?></h1>
                <p class="text-muted mb-0"><?php echo __('cb_txn_subtitle'); ?></p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-success" onclick="openTxnModal('receipt')">
                    <i class="bi bi-arrow-down-circle"></i> <?php echo __('cb_txn_new_receipt'); ?>
                </button>
                <button class="btn btn-danger" onclick="openTxnModal('payment')">
                    <i class="bi bi-arrow-up-circle"></i> <?php echo __('cb_txn_new_payment'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <h3><?php echo number_format($st['total']); ?></h3>
                    <p><?php echo __('cb_txn_stat_total'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100" style="border-left-color:#ffc107;">
                <div class="card-body">
                    <h3 class="text-warning"><?php echo number_format($st['drafts']); ?></h3>
                    <p><?php echo __('cb_txn_stat_draft'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100" style="border-left-color:#198754;">
                <div class="card-body">
                    <h3 style="font-size:1.1rem;color:#198754;">Rp <?php echo number_format($st['total_receipts'],0,',','.'); ?></h3>
                    <p><?php echo __('cb_txn_stat_receipts'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100" style="border-left-color:#dc3545;">
                <div class="card-body">
                    <h3 style="font-size:1.1rem;color:#dc3545;">Rp <?php echo number_format($st['total_payments'],0,',','.'); ?></h3>
                    <p><?php echo __('cb_txn_stat_payments'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('cb_txn_filter_type'); ?></label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">— <?php echo __('cb_txn_all_types'); ?> —</option>
                        <option value="receipt"  <?php if($f_type==='receipt')  echo 'selected'; ?>><?php echo __('cb_txn_type_receipt'); ?></option>
                        <option value="payment"  <?php if($f_type==='payment')  echo 'selected'; ?>><?php echo __('cb_txn_type_payment'); ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('status'); ?></label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">— <?php echo __('all_status'); ?> —</option>
                        <option value="draft"     <?php if($f_status==='draft')     echo 'selected'; ?>>Draft</option>
                        <option value="posted"    <?php if($f_status==='posted')    echo 'selected'; ?>>Posted</option>
                        <option value="cancelled" <?php if($f_status==='cancelled') echo 'selected'; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('cb_txn_filter_bank'); ?></label>
                    <select name="bank" class="form-select form-select-sm">
                        <option value="">— All Banks —</option>
                        <?php foreach ($bank_accounts as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php if($f_bank==$b['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($b['account_code'].' '.$b['account_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('cb_txn_date_from'); ?></label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo $f_from; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('cb_txn_date_to'); ?></label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo $f_to; ?>">
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="<?php echo __('cb_txn_search_ph'); ?>" value="<?php echo htmlspecialchars($f_search); ?>">
                    <button type="submit" class="btn btn-sm btn-primary px-2"><i class="bi bi-search"></i></button>
                    <a href="cash_transactions.php" class="btn btn-sm btn-outline-secondary px-2"><i class="bi bi-x"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Transactions table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table"></i> <?php echo __('cb_txn_list_header'); ?></span>
            <small class="text-white-50"><?php echo count($transactions); ?> <?php echo __('records'); ?></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:0.88rem;">
                    <thead>
                        <tr>
                            <th><?php echo __('cb_txn_col_date'); ?></th>
                            <th><?php echo __('cb_txn_col_ref'); ?></th>
                            <th><?php echo __('cb_txn_col_type'); ?></th>
                            <th><?php echo __('cb_txn_col_bank'); ?></th>
                            <th><?php echo __('cb_txn_col_description'); ?></th>
                            <th><?php echo __('cb_txn_col_cf_line'); ?></th>
                            <th class="text-end"><?php echo __('cb_txn_col_foreign'); ?></th>
                            <th class="text-center"><?php echo __('cb_txn_col_rate'); ?></th>
                            <th class="text-end"><?php echo __('cb_txn_col_idr'); ?></th>
                            <th><?php echo __('cb_txn_col_status'); ?></th>
                            <th><?php echo __('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4"><?php echo __('no_data'); ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($t['transaction_date'])); ?></td>
                            <td><code style="font-size:0.8rem;"><?php echo htmlspecialchars($t['reference_number']); ?></code></td>
                            <td>
                                <?php if ($t['transaction_type'] === 'receipt'): ?>
                                    <span class="badge bg-success"><i class="bi bi-arrow-down-circle"></i> <?php echo __('cb_txn_type_receipt'); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-arrow-up-circle"></i> <?php echo __('cb_txn_type_payment'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo htmlspecialchars($t['bank_account_name']); ?></small></td>
                            <td>
                                <?php echo htmlspecialchars($t['description']); ?>
                                <?php if ($t['payee_payer_name']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($t['payee_payer_name']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($t['cf_subcat_name'] ?? '—'); ?></small></td>
                            <td class="text-end">
                                <span class="badge bg-secondary me-1" style="font-size:0.7rem;"><?php echo $t['currency_code']; ?></span>
                                <?php echo number_format($t['amount_foreign'], 2, ',', '.'); ?>
                            </td>
                            <td class="text-center">
                                <?php if ($t['currency_code'] !== 'IDR'): ?>
                                    <small><?php echo number_format($t['exchange_rate'], 2, ',', '.'); ?></small>
                                <?php else: ?>
                                    <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold">
                                <?php $idr = $t['amount_foreign'] * $t['exchange_rate']; ?>
                                <span class="<?php echo $t['transaction_type']==='receipt' ? 'text-success' : 'text-danger'; ?>">
                                    Rp <?php echo number_format($idr, 0, ',', '.'); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $badge = ['draft'=>'warning','posted'=>'success','cancelled'=>'secondary'];
                                echo '<span class="badge bg-'.($badge[$t['status']]??'secondary').'">'.ucfirst($t['status']).'</span>';
                                if ($t['journal_entry_id']): ?>
                                    <br><a href="journal_entry_detail.php?id=<?php echo $t['journal_entry_id']; ?>" class="small text-muted">JE↗</a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($t['status'] === 'draft'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="post">
                                    <input type="hidden" name="id"     value="<?php echo $t['id']; ?>">
                                    <button type="submit" class="btn btn-xs btn-outline-success" style="font-size:0.75rem;padding:2px 6px;"
                                        onclick="return confirm('<?php echo __('cb_txn_confirm_post'); ?>')"
                                        title="<?php echo __('cb_txn_action_post'); ?>">
                                        <i class="bi bi-check-lg"></i> <?php echo __('cb_txn_action_post'); ?>
                                    </button>
                                </form>
                                <button class="btn btn-xs btn-outline-secondary ms-1" style="font-size:0.75rem;padding:2px 6px;"
                                    onclick="openCancelModal(<?php echo $t['id']; ?>)"
                                    title="<?php echo __('cancel'); ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                                <?php endif; ?>
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

<!-- ═══════════════════════════════════════════════════════════════
     NEW TRANSACTION MODAL
════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="txnModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" id="txnModalHeader">
                <h5 class="modal-title" id="txnModalTitle">New Transaction</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="txnForm">
                <input type="hidden" name="action"           value="create">
                <input type="hidden" name="transaction_type" id="fTxnType" value="receipt">
                <div class="modal-body">
                    <div class="row g-3">

                        <!-- Left column -->
                        <div class="col-md-6">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold"><?php echo __('cb_txn_field_date'); ?> *</label>
                                    <input type="date" name="transaction_date" id="fDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold"><?php echo __('company'); ?> *</label>
                                    <select name="company_id" id="fCompany" class="form-select" required onchange="filterBankByCompany()">
                                        <option value=""><?php echo __('select_company'); ?></option>
                                        <?php foreach ($companies as $c): ?>
                                        <option value="<?php echo $c['company_id']; ?>"><?php echo htmlspecialchars($c['company_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold"><?php echo __('cb_txn_field_bank'); ?> *</label>
                                    <select name="bank_account_id" id="fBank" class="form-select" required onchange="onBankChange()">
                                        <option value=""><?php echo __('select'); ?>…</option>
                                        <?php foreach ($bank_accounts as $b): ?>
                                        <option value="<?php echo $b['id']; ?>"
                                            data-currency="<?php echo $b['currency_code']; ?>"
                                            data-company="<?php echo $b['company_id']; ?>"
                                            data-bu="<?php echo $b['business_unit_id']; ?>">
                                            <?php echo htmlspecialchars($b['account_code'].' – '.$b['account_name'].' ('.$b['currency_code'].')'); ?>
                                        </option>
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
                                    <label class="form-label"><?php echo __('division'); ?></label>
                                    <select name="division_id" id="fDivision" class="form-select">
                                        <option value=""><?php echo __('select_division'); ?></option>
                                        <?php foreach ($divisions as $d): ?>
                                        <option value="<?php echo $d['division_id']; ?>" data-bu="<?php echo $d['business_unit_id']; ?>">
                                            <?php echo htmlspecialchars($d['division_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold"><?php echo __('cb_txn_field_payee'); ?></label>
                                    <input type="text" name="payee_payer_name" class="form-control" maxlength="200"
                                        placeholder="<?php echo __('cb_txn_field_payee_ph'); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold"><?php echo __('cb_txn_field_description'); ?> *</label>
                                    <textarea name="description" class="form-control" rows="2" required></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Right column -->
                        <div class="col-md-6">
                            <div class="row g-3">

                                <!-- Multi-currency block -->
                                <div class="col-12">
                                    <div class="card border-primary">
                                        <div class="card-header bg-primary text-white py-1 small fw-semibold">
                                            <i class="bi bi-currency-exchange"></i> <?php echo __('cb_txn_fx_block'); ?>
                                        </div>
                                        <div class="card-body py-2">
                                            <div class="row g-2">
                                                <div class="col-4">
                                                    <label class="form-label small fw-semibold"><?php echo __('cb_txn_field_currency'); ?> *</label>
                                                    <select name="currency_code" id="fCurrency" class="form-select form-select-sm" required onchange="onCurrencyChange()">
                                                        <?php foreach ($currencies as $cur): ?>
                                                        <option value="<?php echo $cur; ?>" <?php echo ($cur==='IDR')?'selected':''; ?>><?php echo $cur; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-8">
                                                    <label class="form-label small fw-semibold"><?php echo __('cb_txn_field_amount'); ?> *</label>
                                                    <input type="number" name="amount_foreign" id="fAmountForeign" class="form-control form-control-sm" step="0.01" min="0.01" required oninput="calcIDR()">
                                                </div>
                                                <div class="col-4">
                                                    <label class="form-label small fw-semibold"><?php echo __('cb_txn_field_rate'); ?>
                                                        <span id="fxSuggestBadge" class="badge bg-info text-dark ms-1" style="display:none;font-size:0.65rem;">suggested</span>
                                                    </label>
                                                    <input type="number" name="exchange_rate" id="fExchangeRate" class="form-control form-control-sm" step="0.000001" min="0.000001" value="1" required oninput="calcIDR()">
                                                </div>
                                                <div class="col-4">
                                                    <label class="form-label small"><?php echo __('cb_txn_field_rate_date'); ?></label>
                                                    <input type="date" name="rate_date" id="fRateDate" class="form-control form-control-sm">
                                                </div>
                                                <div class="col-4">
                                                    <label class="form-label small"><?php echo __('cb_txn_field_rate_source'); ?></label>
                                                    <input type="text" name="rate_source" id="fRateSource" class="form-control form-control-sm" maxlength="100" placeholder="e.g. BI Rate">
                                                </div>
                                                <div class="col-12">
                                                    <div class="alert alert-success py-1 mb-0 d-flex justify-content-between align-items-center" id="idrPreview">
                                                        <span><?php echo __('cb_txn_idr_equiv'); ?></span>
                                                        <strong id="idrPreviewVal">Rp 0</strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- CF Classification -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold"><?php echo __('cb_txn_field_cf_cat'); ?> *</label>
                                    <select name="cf_category" id="fCFCat" class="form-select" required onchange="filterCFSubcat()">
                                        <option value="">— <?php echo __('select'); ?> —</option>
                                        <optgroup label="Operating">
                                            <option value="operating_receipt">Operating Receipt</option>
                                            <option value="operating_payment">Operating Payment</option>
                                        </optgroup>
                                        <optgroup label="Investing">
                                            <option value="investing_receipt">Investing Receipt</option>
                                            <option value="investing_payment">Investing Payment</option>
                                        </optgroup>
                                        <optgroup label="Financing">
                                            <option value="financing_receipt">Financing Receipt</option>
                                            <option value="financing_payment">Financing Payment</option>
                                        </optgroup>
                                        <option value="inter_account">Inter-account Transfer</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold"><?php echo __('cb_txn_field_cf_subcat'); ?></label>
                                    <select name="cf_subcategory_id" id="fCFSubcat" class="form-select">
                                        <option value="">— <?php echo __('select'); ?> —</option>
                                        <?php foreach ($cf_subcats as $s): ?>
                                        <option value="<?php echo $s['id']; ?>" data-category="<?php echo $s['cf_category']; ?>">
                                            <?php echo htmlspecialchars($s['code'].' – '.$s['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold"><?php echo __('cb_txn_field_contra_gl'); ?> *</label>
                                    <select name="contra_gl_account_id" id="fContraGL" class="form-select" required>
                                        <option value="">— Select GL Account —</option>
                                        <?php foreach ($gl_accounts as $g): ?>
                                        <option value="<?php echo $g['id']; ?>" data-type="<?php echo $g['account_type']; ?>">
                                            <?php echo htmlspecialchars($g['account_code'].' – '.$g['account_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text"><?php echo __('cb_txn_field_contra_gl_hint'); ?></div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label"><?php echo __('cb_txn_field_notes'); ?></label>
                                    <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary" id="txnSubmitBtn">
                        <i class="bi bi-save"></i> <?php echo __('cb_txn_save_draft'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo __('cb_txn_cancel_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id"     id="cancelId" value="">
                <div class="modal-body">
                    <label class="form-label fw-semibold"><?php echo __('cb_txn_cancel_reason'); ?> *</label>
                    <textarea name="cancel_reason" class="form-control" rows="3" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-warning"><?php echo __('cb_txn_cancel_confirm'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── All CF subcategory options (for filtering) ────────────────────────────────
const cfSubcatOptions = <?php echo json_encode($cf_subcats); ?>;
const bankAccounts    = <?php echo json_encode($bank_accounts); ?>;

function openTxnModal(type) {
    document.getElementById('fTxnType').value = type;
    const isReceipt = type === 'receipt';
    const hdr = document.getElementById('txnModalHeader');
    hdr.className = 'modal-header ' + (isReceipt ? 'bg-success' : 'bg-danger') + ' text-white';
    document.getElementById('txnModalTitle').textContent = isReceipt
        ? <?php echo json_encode(__('cb_txn_modal_receipt_title')); ?>
        : <?php echo json_encode(__('cb_txn_modal_payment_title')); ?>;
    // Pre-select matching CF category
    const cat = document.getElementById('fCFCat');
    cat.value = isReceipt ? 'operating_receipt' : 'operating_payment';
    filterCFSubcat();
    document.getElementById('txnForm').reset();
    document.getElementById('fTxnType').value = type;
    document.getElementById('fCurrency').value = 'IDR';
    document.getElementById('fExchangeRate').value = '1';
    document.getElementById('fDate').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('idrPreviewVal').textContent = 'Rp 0';
    document.getElementById('fxSuggestBadge').style.display = 'none';
    cat.value = isReceipt ? 'operating_receipt' : 'operating_payment';
    filterCFSubcat();
    new bootstrap.Modal(document.getElementById('txnModal')).show();
}

function openCancelModal(id) {
    document.getElementById('cancelId').value = id;
    new bootstrap.Modal(document.getElementById('cancelModal')).show();
}

// When bank account changes: set currency, BU
function onBankChange() {
    const sel  = document.getElementById('fBank');
    const opt  = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    const cur  = opt.dataset.currency || 'IDR';
    document.getElementById('fCurrency').value = cur;
    if (opt.dataset.bu) document.getElementById('fBU').value = opt.dataset.bu;
    onCurrencyChange();
}

// Filter bank accounts by selected company
function filterBankByCompany() {
    const compId = document.getElementById('fCompany').value;
    const bankSel = document.getElementById('fBank');
    Array.from(bankSel.options).forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (!compId || opt.dataset.company == compId) ? '' : 'none';
    });
    bankSel.value = '';
}

// When currency changes: fetch suggested rate
function onCurrencyChange() {
    const cur = document.getElementById('fCurrency').value;
    const badge = document.getElementById('fxSuggestBadge');
    if (cur === 'IDR') {
        document.getElementById('fExchangeRate').value = '1';
        badge.style.display = 'none';
        calcIDR();
        return;
    }
    fetch('cash_transactions.php?fx_rate=' + cur)
        .then(r => r.json())
        .then(d => {
            if (d.rate) {
                document.getElementById('fExchangeRate').value = d.rate;
                document.getElementById('fRateDate').value     = d.rate_date || '';
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
            calcIDR();
        });
}

// Live IDR preview
function calcIDR() {
    const amt  = parseFloat(document.getElementById('fAmountForeign').value)  || 0;
    const rate = parseFloat(document.getElementById('fExchangeRate').value)    || 1;
    const idr  = amt * rate;
    document.getElementById('idrPreviewVal').textContent = 'Rp ' + idr.toLocaleString('id-ID', {minimumFractionDigits:0, maximumFractionDigits:0});
}

// Filter CF subcategory options based on selected category
function filterCFSubcat() {
    const cat  = document.getElementById('fCFCat').value;
    const sel  = document.getElementById('fCFSubcat');
    let   first = true;
    sel.innerHTML = '<option value="">— Select —</option>';
    cfSubcatOptions.forEach(s => {
        if (!cat || s.cf_category === cat) {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.code + ' – ' + s.name;
            sel.appendChild(opt);
            if (first) { sel.value = s.id; first = false; }
        }
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
