<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';

$db       = getDB();
$page_title = __('pc_title');
$username   = $_SESSION['username'] ?? 'admin';

// ── POST handlers ─────────────────────────────────────────────────────────────
if (is_post()) {
    $action = post('action');

    // ── Create fund ───────────────────────────────────────────────────────────
    if ($action === 'add_fund') {
        try {
            $db->prepare("
                INSERT INTO petty_cash_funds
                    (fund_code, fund_name, bank_account_id, company_id, division_id,
                     custodian_name, custodian_user, approved_limit, currency_code, status, notes, created_by)
                VALUES (?,?,?,?,?, ?,?,?,?,'active',?,?)
            ")->execute([
                strtoupper(trim(post('fund_code'))),
                post('fund_name'),
                intval(post('bank_account_id')),
                intval(post('company_id')),
                post('division_id') ?: null,
                post('custodian_name') ?: null,
                post('custodian_user') ?: null,
                floatval(post('approved_limit')),
                post('currency_code') ?: 'IDR',
                post('notes') ?: null,
                $username,
            ]);
            set_message('success', __('pc_msg_fund_added'));
        } catch (PDOException $e) {
            set_message('error', __('pc_err_fund_add') . $e->getMessage());
        }
        redirect('petty_cash.php');
    }

    // ── Replenish fund (top-up from bank account) ─────────────────────────────
    if ($action === 'replenish') {
        try {
            $db->beginTransaction();
            $fund_id = intval(post('fund_id'));
            $amount  = floatval(post('amount'));
            $date    = post('replenish_date');

            // Fetch fund details
            $fund = $db->prepare("SELECT pcf.*, ba.gl_account_id AS bank_gl_id, ba.currency_code AS bank_currency FROM petty_cash_funds pcf JOIN bank_accounts ba ON pcf.bank_account_id = ba.id WHERE pcf.id=?");
            $fund->execute([$fund_id]);
            $f = $fund->fetch(PDO::FETCH_ASSOC);
            if (!$f) throw new Exception('Fund not found.');

            // Get petty cash GL account (type=cash linked GL)
            $pc_gl = $db->prepare("SELECT ba.gl_account_id FROM petty_cash_funds pcf JOIN bank_accounts ba ON pcf.bank_account_id=ba.id WHERE pcf.id=?");
            $pc_gl->execute([$fund_id]);
            $pc_gl_id = $pc_gl->fetchColumn();

            // Generate reference
            $ym   = date('Ym', strtotime($date));
            $ref  = 'PC-REP-' . $ym;
            $last = $db->prepare("SELECT reference_number FROM journal_entries WHERE reference_number LIKE ? ORDER BY reference_number DESC LIMIT 1");
            $last->execute([$ref . '-%']);
            $seq  = ($r = $last->fetchColumn()) ? (intval(substr($r, -4)) + 1) : 1;
            $je_ref = sprintf('%s-%04d', $ref, $seq);

            // Journal: Dr Petty Cash GL / Cr Bank GL
            $db->prepare("INSERT INTO journal_entries (entry_date,entry_type,reference_number,description,company_id,total_debit,total_credit,status,posted_date,posted_by,created_by) VALUES (?,?,?,?,?,?,?,'posted',NOW(),?,?)")
               ->execute([$date,'other',$je_ref,'Petty cash replenishment: '.post('notes'),$f['company_id'],$amount,$amount,$username,$username]);
            $je_id = (int)$db->lastInsertId();

            $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id,line_number,gl_account_id,debit_amount,credit_amount,description,company_id) VALUES (?,1,?,?,0,?,?)")
               ->execute([$je_id,$pc_gl_id,$amount,'Petty cash replenishment',$f['company_id']]);
            $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id,line_number,gl_account_id,debit_amount,credit_amount,description,company_id) VALUES (?,2,?,0,?,?,?)")
               ->execute([$je_id,$f['bank_gl_id'],$amount,'Petty cash replenishment',$f['company_id']]);

            // Update fund balance
            $db->prepare("UPDATE petty_cash_funds SET current_balance = current_balance + ? WHERE id=?")->execute([$amount, $fund_id]);

            $db->commit();
            set_message('success', sprintf(__('pc_msg_replenished'), number_format($amount,0,',','.')));
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', __('pc_err_replenish') . $e->getMessage());
        }
        redirect('petty_cash.php?fund=' . intval(post('fund_id')));
    }

    // ── Add expense ───────────────────────────────────────────────────────────
    if ($action === 'add_expense') {
        try {
            $fund_id = intval(post('fund_id'));
            $db->prepare("
                INSERT INTO petty_cash_expenses
                    (fund_id, expense_date, description, gl_account_id, cf_subcategory_id,
                     currency_code, amount_foreign, exchange_rate,
                     receipt_number, expense_type, notes, status, created_by)
                VALUES (?,?,?,?,?, ?,?,?, ?,?,?,'draft',?)
            ")->execute([
                $fund_id,
                post('expense_date'),
                post('description'),
                intval(post('gl_account_id')),
                post('cf_subcategory_id') ?: null,
                post('currency_code') ?: 'IDR',
                floatval(post('amount_foreign')),
                floatval(post('exchange_rate') ?: 1),
                post('receipt_number') ?: null,
                post('expense_type')   ?: null,
                post('notes')          ?: null,
                $username,
            ]);
            // Deduct from fund balance (draft deduction — shows pending)
            $db->prepare("UPDATE petty_cash_funds SET current_balance = current_balance - (? * ?) WHERE id=?")
               ->execute([floatval(post('amount_foreign')), floatval(post('exchange_rate') ?: 1), $fund_id]);

            set_message('success', __('pc_msg_expense_added'));
        } catch (PDOException $e) {
            set_message('error', __('pc_err_expense_add') . $e->getMessage());
        }
        redirect('petty_cash.php?fund=' . intval(post('fund_id')));
    }

    // ── Post expense (creates journal entry) ──────────────────────────────────
    if ($action === 'post_expense') {
        $exp_id = intval(post('id'));
        try {
            $db->beginTransaction();
            $exp = $db->prepare("SELECT pce.*, pcf.company_id, pcf.bank_account_id, ba.gl_account_id AS bank_gl_id FROM petty_cash_expenses pce JOIN petty_cash_funds pcf ON pce.fund_id=pcf.id JOIN bank_accounts ba ON pcf.bank_account_id=ba.id WHERE pce.id=? AND pce.status='draft'");
            $exp->execute([$exp_id]);
            $e = $exp->fetch(PDO::FETCH_ASSOC);
            if (!$e) throw new Exception('Expense not found or already posted.');

            $amount_idr = round($e['amount_foreign'] * $e['exchange_rate'], 2);

            // Generate JE reference
            $ym   = date('Ym', strtotime($e['expense_date']));
            $ref  = 'PC-EXP-' . $ym;
            $last = $db->prepare("SELECT reference_number FROM journal_entries WHERE reference_number LIKE ? ORDER BY reference_number DESC LIMIT 1");
            $last->execute([$ref . '-%']);
            $seq  = ($r = $last->fetchColumn()) ? (intval(substr($r, -4)) + 1) : 1;
            $je_ref = sprintf('%s-%04d', $ref, $seq);

            // Journal: Dr Expense GL / Cr Petty Cash GL (bank_gl_id)
            $db->prepare("INSERT INTO journal_entries (entry_date,entry_type,reference_number,description,company_id,currency_code,exchange_rate,total_debit,total_credit,status,posted_date,posted_by,created_by) VALUES (?,?,?,?,?,?,?,?,?,'posted',NOW(),?,?)")
               ->execute([$e['expense_date'],'other',$je_ref,$e['description'],$e['company_id'],$e['currency_code'],$e['exchange_rate'],$amount_idr,$amount_idr,$username,$username]);
            $je_id = (int)$db->lastInsertId();

            // Dr Expense
            $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id,line_number,gl_account_id,debit_amount,credit_amount,description,company_id) VALUES (?,1,?,?,0,?,?)")
               ->execute([$je_id,$e['gl_account_id'],$amount_idr,$e['description'],$e['company_id']]);
            // Cr Petty Cash
            $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id,line_number,gl_account_id,debit_amount,credit_amount,description,company_id) VALUES (?,2,?,0,?,?,?)")
               ->execute([$je_id,$e['bank_gl_id'],$amount_idr,$e['description'],$e['company_id']]);

            $db->prepare("UPDATE petty_cash_expenses SET status='posted', journal_entry_id=?, posted_by=?, posted_at=NOW() WHERE id=?")
               ->execute([$je_id, $username, $exp_id]);

            $db->commit();
            set_message('success', __('pc_msg_expense_posted'));
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', __('pc_err_expense_post') . $e->getMessage());
        }
        redirect('petty_cash.php?fund=' . intval(post('fund_id')));
    }

    // ── Delete draft expense ──────────────────────────────────────────────────
    if ($action === 'delete_expense') {
        $exp_id  = intval(post('id'));
        $fund_id = intval(post('fund_id'));
        try {
            $db->beginTransaction();
            // Restore balance
            $exp = $db->prepare("SELECT amount_foreign * exchange_rate AS idr FROM petty_cash_expenses WHERE id=? AND status='draft'");
            $exp->execute([$exp_id]);
            $amt = $exp->fetchColumn();
            $db->prepare("DELETE FROM petty_cash_expenses WHERE id=? AND status='draft'")->execute([$exp_id]);
            if ($amt) $db->prepare("UPDATE petty_cash_funds SET current_balance=current_balance+? WHERE id=?")->execute([$amt,$fund_id]);
            $db->commit();
            set_message('success', __('pc_msg_expense_deleted'));
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', __('pc_err_expense_delete') . $e->getMessage());
        }
        redirect('petty_cash.php?fund=' . $fund_id);
    }
}

// ── JSON: fund detail for modal ───────────────────────────────────────────────
if (isset($_GET['json_fund'])) {
    $row = $db->prepare("SELECT * FROM petty_cash_funds WHERE id=?");
    $row->execute([intval($_GET['json_fund'])]);
    header('Content-Type: application/json');
    echo json_encode($row->fetch(PDO::FETCH_ASSOC) ?: []);
    exit;
}

// ── Active fund filter ────────────────────────────────────────────────────────
$active_fund_id = intval($_GET['fund'] ?? 0);

// ── Fetch all funds ───────────────────────────────────────────────────────────
$funds = $db->query("
    SELECT pcf.*, c.company_name, d.division_name,
           ba.account_name AS bank_account_name, ba.currency_code AS bank_currency
    FROM petty_cash_funds pcf
    JOIN companies c         ON pcf.company_id     = c.company_id
    LEFT JOIN divisions d    ON pcf.division_id    = d.division_id
    JOIN bank_accounts ba    ON pcf.bank_account_id= ba.id
    ORDER BY pcf.fund_code
")->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch expenses for active fund ───────────────────────────────────────────
$expenses = [];
$active_fund = null;
if ($active_fund_id) {
    foreach ($funds as $f) { if ($f['id'] == $active_fund_id) { $active_fund = $f; break; } }
    $stmt = $db->prepare("
        SELECT pce.*, gla.account_code AS gl_code, gla.account_name AS gl_name,
               cs.code AS cf_code, cs.name AS cf_subcat_name
        FROM petty_cash_expenses pce
        JOIN general_ledger_accounts gla ON pce.gl_account_id = gla.id
        LEFT JOIN cf_subcategories cs    ON pce.cf_subcategory_id = cs.id
        WHERE pce.fund_id = ?
        ORDER BY pce.expense_date DESC, pce.id DESC
        LIMIT 200
    ");
    $stmt->execute([$active_fund_id]);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Dropdown data ─────────────────────────────────────────────────────────────
$companies    = $db->query("SELECT company_id, company_name FROM companies WHERE status='Active' ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);
$divisions    = $db->query("SELECT division_id, division_name, business_unit_id FROM divisions ORDER BY division_name")->fetchAll(PDO::FETCH_ASSOC);
$bank_accs    = $db->query("SELECT id, account_code, account_name, currency_code, company_id FROM bank_accounts WHERE account_type='cash' AND status='active' ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
$gl_expenses  = $db->query("SELECT id, account_code, account_name FROM general_ledger_accounts WHERE account_type IN ('expense','op_expense') AND is_active=1 ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
$cf_subcats   = $db->query("SELECT id, cf_category, code, name FROM cf_subcategories WHERE cf_category IN ('operating_payment','investing_payment') AND is_active=1 ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);
$currencies   = ['IDR','USD','EUR','MYR','SGD'];

require_once 'includes/header.php';
?>

<div class="content-wrapper">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-cash-coin"></i> <?php echo __('pc_title'); ?></h1>
                <p class="text-muted mb-0"><?php echo __('pc_subtitle'); ?></p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#fundModal">
                <i class="bi bi-plus-lg"></i> <?php echo __('pc_add_fund_btn'); ?>
            </button>
        </div>
    </div>

    <div class="row g-3">
        <!-- Fund list (left panel) -->
        <div class="col-md-4 col-lg-3">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-wallet2"></i> <?php echo __('pc_funds_header'); ?></div>
                <div class="list-group list-group-flush" id="fundList">
                    <?php if (empty($funds)): ?>
                    <div class="list-group-item text-muted small"><?php echo __('no_data'); ?></div>
                    <?php else: ?>
                    <?php foreach ($funds as $f): ?>
                    <?php
                        $pct = $f['approved_limit'] > 0 ? min(100, round($f['current_balance'] / $f['approved_limit'] * 100)) : 0;
                        $bar_color = $pct > 50 ? 'bg-success' : ($pct > 20 ? 'bg-warning' : 'bg-danger');
                    ?>
                    <a href="petty_cash.php?fund=<?php echo $f['id']; ?>"
                       class="list-group-item list-group-item-action <?php echo ($active_fund_id == $f['id']) ? 'active' : ''; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($f['fund_name']); ?></div>
                                <small class="<?php echo ($active_fund_id == $f['id']) ? 'text-white-50' : 'text-muted'; ?>">
                                    <?php echo htmlspecialchars($f['fund_code']); ?> &middot; <?php echo htmlspecialchars($f['company_name']); ?>
                                </small>
                            </div>
                            <span class="badge <?php echo ($f['status']==='active') ? 'bg-success' : 'bg-secondary'; ?>"><?php echo ucfirst($f['status']); ?></span>
                        </div>
                        <div class="mt-1">
                            <div class="d-flex justify-content-between" style="font-size:0.78rem;">
                                <span><?php echo $f['currency_code']; ?> <?php echo number_format($f['current_balance'],0,',','.'); ?></span>
                                <span class="<?php echo ($active_fund_id == $f['id']) ? 'text-white-50' : 'text-muted'; ?>"><?php echo $pct; ?>%</span>
                            </div>
                            <div class="progress mt-1" style="height:4px;">
                                <div class="progress-bar <?php echo $bar_color; ?>" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right panel: fund detail + expenses -->
        <div class="col-md-8 col-lg-9">
            <?php if (!$active_fund): ?>
            <div class="alert alert-info mt-3"><i class="bi bi-arrow-left-circle"></i> <?php echo __('pc_select_prompt'); ?></div>
            <?php else: ?>

            <!-- Fund summary card -->
            <div class="card mb-3" style="border-left:4px solid var(--primary-color);">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-5">
                            <h5 class="mb-1"><?php echo htmlspecialchars($active_fund['fund_name']); ?></h5>
                            <div class="text-muted small">
                                <?php echo htmlspecialchars($active_fund['fund_code']); ?> &middot;
                                <?php echo htmlspecialchars($active_fund['company_name']); ?>
                                <?php if ($active_fund['division_name']): ?> / <?php echo htmlspecialchars($active_fund['division_name']); ?><?php endif; ?>
                            </div>
                            <?php if ($active_fund['custodian_name']): ?>
                            <div class="text-muted small mt-1"><i class="bi bi-person"></i> <?php echo htmlspecialchars($active_fund['custodian_name']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-7">
                            <div class="row text-center g-0">
                                <div class="col-4 border-end">
                                    <div class="small text-muted"><?php echo __('pc_fund_limit'); ?></div>
                                    <div class="fw-bold"><?php echo $active_fund['currency_code']; ?> <?php echo number_format($active_fund['approved_limit'],0,',','.'); ?></div>
                                </div>
                                <div class="col-4 border-end">
                                    <div class="small text-muted"><?php echo __('pc_fund_balance'); ?></div>
                                    <div class="fw-bold <?php echo $active_fund['current_balance'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo $active_fund['currency_code']; ?> <?php echo number_format($active_fund['current_balance'],0,',','.'); ?>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="small text-muted"><?php echo __('pc_fund_used'); ?></div>
                                    <?php $used = $active_fund['approved_limit'] - $active_fund['current_balance']; ?>
                                    <div class="fw-bold text-warning"><?php echo $active_fund['currency_code']; ?> <?php echo number_format($used,0,',','.'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent d-flex gap-2 py-2">
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#replenishModal">
                        <i class="bi bi-arrow-repeat"></i> <?php echo __('pc_replenish_btn'); ?>
                    </button>
                    <button class="btn btn-sm btn-primary" onclick="openExpenseModal()">
                        <i class="bi bi-plus-lg"></i> <?php echo __('pc_add_expense_btn'); ?>
                    </button>
                </div>
            </div>

            <!-- Expenses table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-receipt"></i> <?php echo __('pc_expenses_header'); ?></span>
                    <small class="text-white-50"><?php echo count($expenses); ?> <?php echo __('records'); ?></small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" style="font-size:0.87rem;">
                            <thead>
                                <tr>
                                    <th><?php echo __('pc_exp_col_date'); ?></th>
                                    <th><?php echo __('pc_exp_col_description'); ?></th>
                                    <th><?php echo __('pc_exp_col_type'); ?></th>
                                    <th><?php echo __('pc_exp_col_gl'); ?></th>
                                    <th><?php echo __('pc_exp_col_cf'); ?></th>
                                    <th><?php echo __('pc_exp_col_receipt'); ?></th>
                                    <th class="text-end"><?php echo __('pc_exp_col_amount'); ?></th>
                                    <th class="text-end"><?php echo __('pc_exp_col_idr'); ?></th>
                                    <th><?php echo __('status'); ?></th>
                                    <th><?php echo __('actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($expenses)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-4"><?php echo __('no_data'); ?></td></tr>
                            <?php else: ?>
                            <?php foreach ($expenses as $e): ?>
                            <tr>
                                <td><?php echo $e['expense_date']; ?></td>
                                <td><?php echo htmlspecialchars($e['description']); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($e['expense_type'] ?? '—'); ?></small></td>
                                <td><small><?php echo htmlspecialchars($e['gl_code'].' '.$e['gl_name']); ?></small></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($e['cf_subcat_name'] ?? '—'); ?></small></td>
                                <td><small><?php echo htmlspecialchars($e['receipt_number'] ?? '—'); ?></small></td>
                                <td class="text-end">
                                    <?php if ($e['currency_code'] !== 'IDR'): ?>
                                    <span class="badge bg-secondary" style="font-size:0.7rem;"><?php echo $e['currency_code']; ?></span>
                                    <?php endif; ?>
                                    <?php echo number_format($e['amount_foreign'],2,',','.'); ?>
                                </td>
                                <td class="text-end text-danger fw-semibold">
                                    Rp <?php echo number_format($e['amount_foreign'] * $e['exchange_rate'],0,',','.'); ?>
                                </td>
                                <td>
                                    <?php $badge=['draft'=>'warning','posted'=>'success']; ?>
                                    <span class="badge bg-<?php echo $badge[$e['status']]??'secondary'; ?>">
                                        <?php echo ucfirst($e['status']); ?>
                                    </span>
                                    <?php if ($e['journal_entry_id']): ?>
                                    <br><a href="journal_entry_detail.php?id=<?php echo $e['journal_entry_id']; ?>" class="small text-muted">JE↗</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($e['status'] === 'draft'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action"  value="post_expense">
                                        <input type="hidden" name="id"      value="<?php echo $e['id']; ?>">
                                        <input type="hidden" name="fund_id" value="<?php echo $active_fund_id; ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-success" style="font-size:0.73rem;padding:1px 5px;"
                                            onclick="return confirm('<?php echo __('pc_confirm_post_expense'); ?>')"
                                            title="<?php echo __('pc_action_post'); ?>">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline ms-1">
                                        <input type="hidden" name="action"  value="delete_expense">
                                        <input type="hidden" name="id"      value="<?php echo $e['id']; ?>">
                                        <input type="hidden" name="fund_id" value="<?php echo $active_fund_id; ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:0.73rem;padding:1px 5px;"
                                            onclick="return confirm('<?php echo __('confirm_delete'); ?>')"
                                            title="<?php echo __('delete'); ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <!-- Totals -->
                            <?php
                            $t_idr = array_sum(array_map(fn($e)=>$e['amount_foreign']*$e['exchange_rate'], $expenses));
                            $t_posted = array_sum(array_map(fn($e)=>$e['status']==='posted'?$e['amount_foreign']*$e['exchange_rate']:0, $expenses));
                            ?>
                            <tr class="table-light fw-bold border-top border-2">
                                <td colspan="7" class="text-end"><?php echo __('pc_exp_total'); ?></td>
                                <td class="text-end text-danger">Rp <?php echo number_format($t_idr,0,',','.'); ?></td>
                                <td colspan="2"><small class="text-muted"><?php echo __('pc_exp_posted'); ?>: Rp <?php echo number_format($t_posted,0,',','.'); ?></small></td>
                            </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Add Fund Modal ─────────────────────────────────────────────────────────-->
<div class="modal fade" id="fundModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-wallet2"></i> <?php echo __('pc_modal_fund_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_fund">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php echo __('pc_fund_code'); ?> *</label>
                            <input type="text" name="fund_code" class="form-control text-uppercase" required maxlength="20" placeholder="PCF-DIV-01">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold"><?php echo __('pc_fund_name'); ?> *</label>
                            <input type="text" name="fund_name" class="form-control" required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo __('company'); ?> *</label>
                            <select name="company_id" class="form-select" required>
                                <option value=""><?php echo __('select_company'); ?></option>
                                <?php foreach ($companies as $c): ?>
                                <option value="<?php echo $c['company_id']; ?>"><?php echo htmlspecialchars($c['company_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('division'); ?></label>
                            <select name="division_id" class="form-select">
                                <option value=""><?php echo __('select_division'); ?></option>
                                <?php foreach ($divisions as $d): ?>
                                <option value="<?php echo $d['division_id']; ?>"><?php echo htmlspecialchars($d['division_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo __('pc_fund_bank_acc'); ?> *
                                <small class="fw-normal text-muted">(type: cash)</small>
                            </label>
                            <select name="bank_account_id" class="form-select" required>
                                <option value="">— <?php echo __('select'); ?> —</option>
                                <?php foreach ($bank_accs as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['account_code'].' – '.$b['account_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?php echo __('pc_fund_limit_lbl'); ?> *</label>
                            <input type="number" name="approved_limit" class="form-control" required step="1000" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?php echo __('pc_fund_currency'); ?></label>
                            <select name="currency_code" class="form-select">
                                <?php foreach ($currencies as $cur): ?>
                                <option value="<?php echo $cur; ?>" <?php echo ($cur==='IDR')?'selected':''; ?>><?php echo $cur; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('pc_fund_custodian'); ?></label>
                            <input type="text" name="custodian_name" class="form-control" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('pc_fund_custodian_user'); ?></label>
                            <input type="text" name="custodian_user" class="form-control" maxlength="50">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('pc_fund_notes'); ?></label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> <?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Replenish Modal ────────────────────────────────────────────────────────-->
<?php if ($active_fund): ?>
<div class="modal fade" id="replenishModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-repeat"></i> <?php echo __('pc_replenish_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action"  value="replenish">
                <input type="hidden" name="fund_id" value="<?php echo $active_fund_id; ?>">
                <div class="modal-body">
                    <div class="alert alert-info py-2 small">
                        <strong><?php echo htmlspecialchars($active_fund['fund_name']); ?></strong> —
                        <?php echo __('pc_fund_balance'); ?>:
                        <strong><?php echo $active_fund['currency_code'].' '.number_format($active_fund['current_balance'],0,',','.'); ?></strong>
                        / <?php echo __('pc_fund_limit'); ?>:
                        <strong><?php echo $active_fund['currency_code'].' '.number_format($active_fund['approved_limit'],0,',','.'); ?></strong>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo __('pc_replenish_date'); ?> *</label>
                            <input type="date" name="replenish_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo __('pc_replenish_amount'); ?> *</label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required
                                value="<?php echo max(0, $active_fund['approved_limit'] - $active_fund['current_balance']); ?>">
                            <div class="form-text"><?php echo __('pc_replenish_suggestion'); ?>: <?php echo number_format(max(0,$active_fund['approved_limit']-$active_fund['current_balance']),0,',','.'); ?></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('pc_replenish_notes'); ?></label>
                            <input type="text" name="notes" class="form-control" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-arrow-repeat"></i> <?php echo __('pc_replenish_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Add Expense Modal ──────────────────────────────────────────────────────-->
<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-receipt"></i> <?php echo __('pc_expense_modal_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action"  value="add_expense">
                <input type="hidden" name="fund_id" value="<?php echo $active_fund_id; ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php echo __('pc_exp_date'); ?> *</label>
                            <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('pc_exp_type'); ?></label>
                            <input type="text" name="expense_type" class="form-control" maxlength="100" placeholder="e.g. Stationery, Fuel">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('pc_exp_receipt_no'); ?></label>
                            <input type="text" name="receipt_number" class="form-control" maxlength="50">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?php echo __('pc_exp_description'); ?> *</label>
                            <input type="text" name="description" class="form-control" required maxlength="500">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo __('pc_exp_gl'); ?> *</label>
                            <select name="gl_account_id" class="form-select" required>
                                <option value="">— <?php echo __('select'); ?> —</option>
                                <?php foreach ($gl_expenses as $g): ?>
                                <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['account_code'].' – '.$g['account_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('pc_exp_cf_line'); ?></label>
                            <select name="cf_subcategory_id" class="form-select">
                                <option value="">— <?php echo __('select'); ?> —</option>
                                <?php foreach ($cf_subcats as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['code'].' – '.$s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Currency block -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?php echo __('pc_exp_currency'); ?></label>
                            <select name="currency_code" id="pcCurrency" class="form-select" onchange="pcFetchRate()">
                                <?php foreach ($currencies as $cur): ?>
                                <option value="<?php echo $cur; ?>" <?php echo ($cur==='IDR')?'selected':''; ?>><?php echo $cur; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?php echo __('pc_exp_amount'); ?> *</label>
                            <input type="number" name="amount_foreign" id="pcAmountForeign" class="form-control" step="0.01" min="0.01" required oninput="pcCalcIDR()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?php echo __('pc_exp_rate'); ?></label>
                            <input type="number" name="exchange_rate" id="pcRate" class="form-control" step="0.000001" value="1" oninput="pcCalcIDR()">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="w-100">
                                <label class="form-label small text-muted"><?php echo __('pc_exp_idr_equiv'); ?></label>
                                <div class="form-control bg-light fw-bold text-danger" id="pcIDRPreview">Rp 0</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('pc_exp_notes'); ?></label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-save"></i> <?php echo __('pc_exp_save_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openExpenseModal() {
    new bootstrap.Modal(document.getElementById('expenseModal')).show();
}
function pcFetchRate() {
    const cur = document.getElementById('pcCurrency').value;
    if (cur === 'IDR') { document.getElementById('pcRate').value = '1'; pcCalcIDR(); return; }
    fetch('cash_transactions.php?fx_rate=' + cur)
        .then(r => r.json())
        .then(d => { if (d.rate) { document.getElementById('pcRate').value = d.rate; } pcCalcIDR(); });
}
function pcCalcIDR() {
    const amt  = parseFloat(document.getElementById('pcAmountForeign').value) || 0;
    const rate = parseFloat(document.getElementById('pcRate').value)           || 1;
    document.getElementById('pcIDRPreview').textContent = 'Rp ' + (amt * rate).toLocaleString('id-ID', {minimumFractionDigits:0, maximumFractionDigits:0});
}
</script>

<?php require_once 'includes/footer.php'; ?>
