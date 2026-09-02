<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';

$db       = getDB();
$page_title = __('rec_title');
$username   = $_SESSION['username'] ?? 'admin';

// ── POST handlers ─────────────────────────────────────────────────────────────
if (is_post()) {
    $action = post('action');

    // ── Import statement lines (manual entry, one line at a time) ─────────────
    if ($action === 'import_line') {
        try {
            $db->prepare("
                INSERT INTO bank_statement_lines
                    (bank_account_id, statement_date, value_date, description,
                     debit_amount, credit_amount, running_balance,
                     currency_code, import_batch, notes, created_by)
                VALUES (?,?,?,?, ?,?,?, ?,?,?,?)
            ")->execute([
                intval(post('bank_account_id')),
                post('statement_date'),
                post('value_date') ?: null,
                post('description'),
                floatval(post('debit_amount')  ?: 0),
                floatval(post('credit_amount') ?: 0),
                post('running_balance') !== '' ? floatval(post('running_balance')) : null,
                post('currency_code') ?: 'IDR',
                post('import_batch') ?: ('MANUAL-' . date('Ymd')),
                post('notes') ?: null,
                $username,
            ]);
            set_message('success', __('rec_msg_line_added'));
        } catch (PDOException $e) {
            set_message('error', __('rec_err_line_add') . $e->getMessage());
        }
        redirect('bank_reconciliation.php?bank=' . intval(post('bank_account_id')) . '&from=' . post('date_from') . '&to=' . post('date_to'));
    }

    // ── Match a statement line to a system transaction ────────────────────────
    if ($action === 'match') {
        try {
            $sl_id = intval(post('statement_line_id'));
            $ct_id = intval(post('cash_transaction_id'));
            $db->prepare("UPDATE bank_statement_lines SET match_status='matched', cash_transaction_id=? WHERE id=? AND match_status='unmatched'")
               ->execute([$ct_id, $sl_id]);
            set_message('success', __('rec_msg_matched'));
        } catch (PDOException $e) {
            set_message('error', __('rec_err_match') . $e->getMessage());
        }
        redirect('bank_reconciliation.php?bank=' . intval(post('bank_account_id')) . '&from=' . post('date_from') . '&to=' . post('date_to'));
    }

    // ── Unmatch a statement line ──────────────────────────────────────────────
    if ($action === 'unmatch') {
        try {
            $db->prepare("UPDATE bank_statement_lines SET match_status='unmatched', cash_transaction_id=NULL WHERE id=?")
               ->execute([intval(post('statement_line_id'))]);
            set_message('success', __('rec_msg_unmatched'));
        } catch (PDOException $e) {
            set_message('error', __('rec_err_unmatch') . $e->getMessage());
        }
        redirect('bank_reconciliation.php?bank=' . intval(post('bank_account_id')) . '&from=' . post('date_from') . '&to=' . post('date_to'));
    }

    // ── Exclude a statement line (e.g. bank charges already in system) ────────
    if ($action === 'exclude') {
        try {
            $db->prepare("UPDATE bank_statement_lines SET match_status='excluded' WHERE id=? AND match_status='unmatched'")
               ->execute([intval(post('statement_line_id'))]);
            set_message('success', __('rec_msg_excluded'));
        } catch (PDOException $e) {
            set_message('error', __('rec_err_exclude') . $e->getMessage());
        }
        redirect('bank_reconciliation.php?bank=' . intval(post('bank_account_id')) . '&from=' . post('date_from') . '&to=' . post('date_to'));
    }

    // ── Delete a statement line (only unmatched) ──────────────────────────────
    if ($action === 'delete_line') {
        try {
            $db->prepare("DELETE FROM bank_statement_lines WHERE id=? AND match_status='unmatched'")
               ->execute([intval(post('statement_line_id'))]);
            set_message('success', __('rec_msg_line_deleted'));
        } catch (PDOException $e) {
            set_message('error', __('rec_err_line_delete') . $e->getMessage());
        }
        redirect('bank_reconciliation.php?bank=' . intval(post('bank_account_id')) . '&from=' . post('date_from') . '&to=' . post('date_to'));
    }

    // ── Auto-match: match by amount + date (±1 day) ───────────────────────────
    if ($action === 'auto_match') {
        $bank_id  = intval(post('bank_account_id'));
        $matched  = 0;
        try {
            // Fetch all unmatched statement lines for this account
            $sl_stmt = $db->prepare("
                SELECT id, statement_date, debit_amount, credit_amount
                FROM bank_statement_lines
                WHERE bank_account_id=? AND match_status='unmatched'
            ");
            $sl_stmt->execute([$bank_id]);
            $sl_rows = $sl_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($sl_rows as $sl) {
                // Statement credit = system receipt; statement debit = system payment
                $txn_type   = $sl['credit_amount'] > 0 ? 'receipt'  : 'payment';
                $stmt_amount = $sl['credit_amount'] > 0 ? $sl['credit_amount'] : $sl['debit_amount'];

                $ct = $db->prepare("
                    SELECT id FROM cash_transactions
                    WHERE bank_account_id = ?
                      AND transaction_type = ?
                      AND ABS(amount_idr - ?) < 1
                      AND ABS(DATEDIFF(transaction_date, ?)) <= 1
                      AND status = 'posted'
                      AND id NOT IN (SELECT cash_transaction_id FROM bank_statement_lines WHERE cash_transaction_id IS NOT NULL)
                    LIMIT 1
                ");
                $ct->execute([$bank_id, $txn_type, $stmt_amount, $sl['statement_date']]);
                $ct_row = $ct->fetchColumn();

                if ($ct_row) {
                    $db->prepare("UPDATE bank_statement_lines SET match_status='matched', cash_transaction_id=? WHERE id=?")
                       ->execute([$ct_row, $sl['id']]);
                    $matched++;
                }
            }
            set_message('success', sprintf(__('rec_msg_auto_matched'), $matched));
        } catch (Exception $e) {
            set_message('error', __('rec_err_auto_match') . $e->getMessage());
        }
        redirect('bank_reconciliation.php?bank=' . $bank_id . '&from=' . post('date_from') . '&to=' . post('date_to'));
    }

    // ── CSV import ────────────────────────────────────────────────────────────
    if ($action === 'csv_import') {
        $bank_id = intval(post('bank_account_id'));
        $batch   = post('import_batch') ?: ('CSV-' . date('Ymd-His'));
        $currency = post('currency_code') ?: 'IDR';
        $imported = 0;
        $errors   = [];

        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            set_message('error', __('rec_err_csv_no_file'));
        } else {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle === false) {
                set_message('error', __('rec_err_csv_open'));
            } else {
                $header = fgetcsv($handle); // skip header row
                $line_no = 1;
                $stmt = $db->prepare("
                    INSERT INTO bank_statement_lines
                        (bank_account_id, statement_date, value_date, description,
                         debit_amount, credit_amount, running_balance,
                         currency_code, import_batch, notes, created_by)
                    VALUES (?,?,?,?, ?,?,?, ?,?,?,?)
                ");
                while (($row = fgetcsv($handle)) !== false) {
                    $line_no++;
                    if (count($row) < 4) { $errors[] = "Line $line_no: too few columns"; continue; }
                    // Expected columns: date, description, debit, credit[, running_balance[, notes]]
                    $stmt_date  = trim($row[0] ?? '');
                    $desc       = trim($row[1] ?? '');
                    $debit      = floatval(str_replace([',', ' '], '', $row[2] ?? 0));
                    $credit     = floatval(str_replace([',', ' '], '', $row[3] ?? 0));
                    $running    = isset($row[4]) && $row[4] !== '' ? floatval(str_replace([',', ' '], '', $row[4])) : null;
                    $notes_val  = trim($row[5] ?? '');

                    // Basic date validation
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $stmt_date)) {
                        // Try Y/m/d or d/m/Y formats
                        $ts = strtotime($stmt_date);
                        if ($ts === false) { $errors[] = "Line $line_no: invalid date '$stmt_date'"; continue; }
                        $stmt_date = date('Y-m-d', $ts);
                    }

                    try {
                        $stmt->execute([
                            $bank_id, $stmt_date, null, $desc,
                            $debit, $credit, $running,
                            $currency, $batch, $notes_val ?: null, $username,
                        ]);
                        $imported++;
                    } catch (PDOException $e) {
                        $errors[] = "Line $line_no: " . $e->getMessage();
                    }
                }
                fclose($handle);

                if ($imported > 0) {
                    $msg = sprintf(__('rec_msg_csv_imported'), $imported, $batch);
                    if ($errors) $msg .= ' ' . sprintf(__('rec_msg_csv_errors'), count($errors));
                    set_message($errors ? 'warning' : 'success', $msg);
                } else {
                    set_message('error', __('rec_err_csv_no_rows') . (empty($errors) ? '' : ' ' . implode('; ', array_slice($errors, 0, 3))));
                }
            }
        }
        redirect('bank_reconciliation.php?bank=' . $bank_id . '&from=' . post('date_from') . '&to=' . post('date_to'));
    }

    // ── Lock reconciliation period ────────────────────────────────────────────
    if ($action === 'lock_period') {
        $bank_id = intval(post('bank_account_id'));
        $lock_from = post('date_from');
        $lock_to   = post('date_to');
        try {
            // Ensure the locks table exists (idempotent)
            $db->exec("CREATE TABLE IF NOT EXISTS bank_reconciliation_locks (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bank_account_id INT UNSIGNED NOT NULL,
                period_from  DATE NOT NULL,
                period_to    DATE NOT NULL,
                locked_by    VARCHAR(50) NOT NULL,
                locked_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                notes        VARCHAR(255) NULL,
                UNIQUE KEY uq_lock (bank_account_id, period_from, period_to)
            )");
            $db->prepare("INSERT IGNORE INTO bank_reconciliation_locks (bank_account_id, period_from, period_to, locked_by, notes) VALUES (?,?,?,?,?)")
               ->execute([$bank_id, $lock_from, $lock_to, $username, post('notes') ?: null]);
            set_message('success', __('rec_msg_period_locked'));
        } catch (Exception $e) {
            set_message('error', __('rec_err_lock') . $e->getMessage());
        }
        redirect('bank_reconciliation.php?bank=' . $bank_id . '&from=' . $lock_from . '&to=' . $lock_to);
    }

    // ── Unlock reconciliation period ──────────────────────────────────────────
    if ($action === 'unlock_period') {
        $bank_id = intval(post('bank_account_id'));
        $lock_from = post('date_from');
        $lock_to   = post('date_to');
        try {
            $db->prepare("DELETE FROM bank_reconciliation_locks WHERE bank_account_id=? AND period_from=? AND period_to=?")
               ->execute([$bank_id, $lock_from, $lock_to]);
            set_message('success', __('rec_msg_period_unlocked'));
        } catch (Exception $e) {
            set_message('error', __('rec_err_unlock') . $e->getMessage());
        }
        redirect('bank_reconciliation.php?bank=' . $bank_id . '&from=' . $lock_from . '&to=' . $lock_to);
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$f_bank = intval($_GET['bank']  ?? 0);
$f_from = trim($_GET['from']    ?? date('Y-m-01'));
$f_to   = trim($_GET['to']      ?? date('Y-m-d'));

// ── Bank accounts dropdown ────────────────────────────────────────────────────
$bank_accounts = $db->query("
    SELECT ba.id, ba.account_code, ba.account_name, ba.currency_code, ba.opening_balance,
           c.company_name
    FROM bank_accounts ba
    JOIN companies c ON ba.company_id = c.company_id
    WHERE ba.status='active'
    ORDER BY ba.account_code
")->fetchAll(PDO::FETCH_ASSOC);

$selected_bank = null;
if ($f_bank) {
    foreach ($bank_accounts as $b) { if ($b['id'] == $f_bank) { $selected_bank = $b; break; } }
}

// ── Statement lines ───────────────────────────────────────────────────────────
$stmt_lines = [];
if ($f_bank) {
    $sl = $db->prepare("
        SELECT bsl.*,
               ct.reference_number AS ct_ref,
               ct.description      AS ct_desc,
               ct.amount_idr       AS ct_amount_idr
        FROM bank_statement_lines bsl
        LEFT JOIN cash_transactions ct ON bsl.cash_transaction_id = ct.id
        WHERE bsl.bank_account_id=? AND bsl.statement_date BETWEEN ? AND ?
        ORDER BY bsl.statement_date ASC, bsl.id ASC
    ");
    $sl->execute([$f_bank, $f_from, $f_to]);
    $stmt_lines = $sl->fetchAll(PDO::FETCH_ASSOC);
}

// ── Unmatched system transactions for this bank/period ────────────────────────
$unmatched_ct = [];
if ($f_bank) {
    $uct = $db->prepare("
        SELECT ct.id, ct.reference_number, ct.transaction_date, ct.transaction_type,
               ct.description, ct.amount_idr, ct.currency_code, ct.amount_foreign
        FROM cash_transactions ct
        WHERE ct.bank_account_id=?
          AND ct.transaction_date BETWEEN ? AND ?
          AND ct.status='posted'
          AND ct.id NOT IN (
              SELECT cash_transaction_id FROM bank_statement_lines
              WHERE cash_transaction_id IS NOT NULL
          )
        ORDER BY ct.transaction_date ASC
    ");
    $uct->execute([$f_bank, $f_from, $f_to]);
    $unmatched_ct = $uct->fetchAll(PDO::FETCH_ASSOC);
}

// ── Reconciliation stats ──────────────────────────────────────────────────────
$total_sl    = count($stmt_lines);
$matched_sl  = count(array_filter($stmt_lines, fn($r) => $r['match_status'] === 'matched'));
$excluded_sl = count(array_filter($stmt_lines, fn($r) => $r['match_status'] === 'excluded'));
$unmatched_sl = $total_sl - $matched_sl - $excluded_sl;

// ── Check period lock status ──────────────────────────────────────────────────
$is_locked  = false;
$lock_info  = null;
if ($f_bank) {
    try {
        $lk = $db->prepare("SELECT * FROM bank_reconciliation_locks WHERE bank_account_id=? AND period_from=? AND period_to=? LIMIT 1");
        $lk->execute([$f_bank, $f_from, $f_to]);
        $lock_info = $lk->fetch(PDO::FETCH_ASSOC) ?: null;
        $is_locked = (bool) $lock_info;
    } catch (PDOException $e) {
        // Table may not exist yet on first visit — treat as unlocked
        $is_locked = false;
    }
}

// Statement totals
$stmt_total_credits = array_sum(array_column($stmt_lines, 'credit_amount'));
$stmt_total_debits  = array_sum(array_column($stmt_lines, 'debit_amount'));

// Book (system) totals for period
$book_totals = ['receipts' => 0, 'payments' => 0];
if ($f_bank) {
    $bt = $db->prepare("
        SELECT transaction_type, COALESCE(SUM(amount_idr),0) AS total
        FROM cash_transactions
        WHERE bank_account_id=? AND transaction_date BETWEEN ? AND ? AND status='posted'
        GROUP BY transaction_type
    ");
    $bt->execute([$f_bank, $f_from, $f_to]);
    foreach ($bt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $book_totals[$row['transaction_type'] === 'receipt' ? 'receipts' : 'payments'] = floatval($row['total']);
    }
}

$difference = ($stmt_total_credits - $stmt_total_debits) - ($book_totals['receipts'] - $book_totals['payments']);

require_once 'includes/header.php';
?>

<div class="content-wrapper">
    <!-- Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-check2-square"></i> <?php echo __('rec_title'); ?></h1>
                <p class="text-muted mb-0"><?php echo __('rec_subtitle'); ?></p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1 fw-semibold"><?php echo __('rec_select_bank'); ?></label>
                    <select name="bank" class="form-select" onchange="this.form.submit()">
                        <option value="">— <?php echo __('rec_choose_account'); ?> —</option>
                        <?php foreach ($bank_accounts as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php if($f_bank==$b['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($b['account_code'].' – '.$b['account_name'].' ('.$b['currency_code'].')'); ?>
                            — <?php echo htmlspecialchars($b['company_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('rec_date_from'); ?></label>
                    <input type="date" name="from" class="form-control" value="<?php echo $f_from; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('rec_date_to'); ?></label>
                    <input type="date" name="to" class="form-control" value="<?php echo $f_to; ?>">
                </div>
                <div class="col-md-4 d-flex gap-2 align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-search"></i> <?php echo __('search'); ?>
                    </button>
                    <a href="bank_reconciliation.php" class="btn btn-outline-secondary btn-sm px-2"><i class="bi bi-x"></i></a>
                    <?php if ($f_bank): ?>
                    <button type="button" class="btn btn-outline-success btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#importModal">
                        <i class="bi bi-plus-lg"></i> <?php echo __('rec_add_line_btn'); ?>
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#csvModal">
                        <i class="bi bi-file-earmark-arrow-up"></i> <?php echo __('rec_csv_import_btn'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$f_bank): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle"></i> <?php echo __('rec_select_prompt'); ?></div>
    <?php else: ?>

    <!-- Lock banner -->
    <?php if ($is_locked): ?>
    <div class="alert alert-warning py-2 d-flex align-items-center justify-content-between mb-3">
        <span>
            <i class="bi bi-lock-fill me-1"></i>
            <strong><?php echo __('rec_period_locked_label'); ?></strong>
            &mdash; <?php echo __('rec_locked_by'); ?> <strong><?php echo htmlspecialchars($lock_info['locked_by']); ?></strong>
            <?php echo __('rec_locked_on'); ?> <?php echo date('d M Y H:i', strtotime($lock_info['locked_at'])); ?>
        </span>
        <form method="POST" class="mb-0">
            <input type="hidden" name="action"          value="unlock_period">
            <input type="hidden" name="bank_account_id" value="<?php echo $f_bank; ?>">
            <input type="hidden" name="date_from"       value="<?php echo $f_from; ?>">
            <input type="hidden" name="date_to"         value="<?php echo $f_to; ?>">
            <button type="submit" class="btn btn-sm btn-warning"
                onclick="return confirm('<?php echo __('rec_confirm_unlock'); ?>')">
                <i class="bi bi-unlock"></i> <?php echo __('rec_unlock_btn'); ?>
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Reconciliation summary bar -->
    <div class="card mb-3" style="border-left:4px solid <?php echo $is_locked ? '#ffc107' : 'var(--primary-color)'; ?>;">
        <div class="card-body py-2">
            <div class="row g-0 text-center align-items-center">
                <div class="col-md-2 border-end">
                    <div class="small text-muted"><?php echo __('rec_stat_total'); ?></div>
                    <div class="fw-bold fs-5"><?php echo $total_sl; ?></div>
                </div>
                <div class="col-md-2 border-end">
                    <div class="small text-muted"><?php echo __('rec_stat_matched'); ?></div>
                    <div class="fw-bold fs-5 text-success"><?php echo $matched_sl; ?></div>
                </div>
                <div class="col-md-2 border-end">
                    <div class="small text-muted"><?php echo __('rec_stat_unmatched'); ?></div>
                    <div class="fw-bold fs-5 text-warning"><?php echo $unmatched_sl; ?></div>
                </div>
                <div class="col-md-2 border-end">
                    <div class="small text-muted"><?php echo __('rec_stat_excluded'); ?></div>
                    <div class="fw-bold fs-5 text-secondary"><?php echo $excluded_sl; ?></div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex justify-content-around align-items-center gap-2 flex-wrap">
                        <div>
                            <div class="small text-muted"><?php echo __('rec_stat_difference'); ?></div>
                            <div class="fw-bold fs-5 <?php echo abs($difference) < 1 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo abs($difference) < 1 ? '✓ ' . __('rec_balanced') : 'Rp ' . number_format(abs($difference),0,',','.'); ?>
                            </div>
                        </div>
                        <?php if (!$is_locked): ?>
                        <!-- Auto-match form -->
                        <form method="POST" class="mb-0">
                            <input type="hidden" name="action"        value="auto_match">
                            <input type="hidden" name="bank_account_id" value="<?php echo $f_bank; ?>">
                            <input type="hidden" name="date_from"     value="<?php echo $f_from; ?>">
                            <input type="hidden" name="date_to"       value="<?php echo $f_to; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary"
                                onclick="return confirm('<?php echo __('rec_confirm_auto_match'); ?>')"
                                title="<?php echo __('rec_auto_match_hint'); ?>">
                                <i class="bi bi-magic"></i> <?php echo __('rec_auto_match_btn'); ?>
                            </button>
                        </form>
                        <!-- Lock period form -->
                        <form method="POST" class="mb-0">
                            <input type="hidden" name="action"          value="lock_period">
                            <input type="hidden" name="bank_account_id" value="<?php echo $f_bank; ?>">
                            <input type="hidden" name="date_from"       value="<?php echo $f_from; ?>">
                            <input type="hidden" name="date_to"         value="<?php echo $f_to; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"
                                onclick="return confirm('<?php echo __('rec_confirm_lock'); ?>')"
                                title="<?php echo __('rec_lock_hint'); ?>">
                                <i class="bi bi-lock"></i> <?php echo __('rec_lock_btn'); ?>
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-lock-fill"></i> <?php echo __('rec_period_locked_badge'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- LEFT: Bank Statement Lines -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bank"></i> <?php echo __('rec_stmt_header'); ?></span>
                    <small class="text-white-50"><?php echo $total_sl; ?> <?php echo __('records'); ?></small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:560px;overflow-y:auto;">
                        <table class="table table-sm table-hover mb-0" style="font-size:0.82rem;">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th><?php echo __('rec_col_date'); ?></th>
                                    <th><?php echo __('rec_col_stmt_desc'); ?></th>
                                    <th class="text-end text-success"><?php echo __('rec_col_credit'); ?></th>
                                    <th class="text-end text-danger"><?php echo __('rec_col_debit'); ?></th>
                                    <th><?php echo __('rec_col_match_status'); ?></th>
                                    <th><?php echo __('actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($stmt_lines)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4"><?php echo __('rec_no_stmt_lines'); ?></td></tr>
                            <?php else: ?>
                            <?php foreach ($stmt_lines as $sl): ?>
                            <?php
                                $row_cls = match($sl['match_status']) {
                                    'matched'  => 'table-success',
                                    'excluded' => 'table-secondary',
                                    default    => '',
                                };
                            ?>
                            <tr class="<?php echo $row_cls; ?>">
                                <td><?php echo $sl['statement_date']; ?></td>
                                <td>
                                    <?php echo htmlspecialchars(mb_strimwidth($sl['description'] ?? '', 0, 45, '…')); ?>
                                    <?php if ($sl['match_status'] === 'matched' && $sl['ct_ref']): ?>
                                    <br><small class="text-success"><i class="bi bi-link-45deg"></i> <?php echo htmlspecialchars($sl['ct_ref']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-success fw-semibold">
                                    <?php echo $sl['credit_amount'] > 0 ? number_format($sl['credit_amount'],0,',','.') : ''; ?>
                                </td>
                                <td class="text-end text-danger fw-semibold">
                                    <?php echo $sl['debit_amount'] > 0 ? '('.number_format($sl['debit_amount'],0,',','.').')' : ''; ?>
                                </td>
                                <td>
                                    <?php
                                    $badge_map = ['matched'=>'success','excluded'=>'secondary','unmatched'=>'warning'];
                                    $badge_lbl = ['matched'=>__('rec_matched'),'excluded'=>__('rec_excluded'),'unmatched'=>__('rec_unmatched')];
                                    echo '<span class="badge bg-'.($badge_map[$sl['match_status']]??'secondary').'">'.($badge_lbl[$sl['match_status']]??$sl['match_status']).'</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php if ($is_locked): ?>
                                    <i class="bi bi-lock text-muted" title="<?php echo __('rec_period_locked_badge'); ?>"></i>
                                    <?php elseif ($sl['match_status'] === 'unmatched'): ?>
                                    <button class="btn btn-xs btn-outline-primary" style="font-size:0.72rem;padding:1px 5px;"
                                        onclick="openMatchModal(<?php echo $sl['id']; ?>, <?php echo $sl['credit_amount'] > 0 ? $sl['credit_amount'] : $sl['debit_amount']; ?>, '<?php echo $sl['statement_date']; ?>', '<?php echo $sl['credit_amount'] > 0 ? 'receipt' : 'payment'; ?>')"
                                        title="<?php echo __('rec_action_match'); ?>">
                                        <i class="bi bi-link"></i>
                                    </button>
                                    <form method="POST" class="d-inline ms-1">
                                        <input type="hidden" name="action"           value="exclude">
                                        <input type="hidden" name="statement_line_id" value="<?php echo $sl['id']; ?>">
                                        <input type="hidden" name="bank_account_id"  value="<?php echo $f_bank; ?>">
                                        <input type="hidden" name="date_from"        value="<?php echo $f_from; ?>">
                                        <input type="hidden" name="date_to"          value="<?php echo $f_to; ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-secondary" style="font-size:0.72rem;padding:1px 5px;" title="<?php echo __('rec_action_exclude'); ?>">
                                            <i class="bi bi-dash-circle"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline ms-1">
                                        <input type="hidden" name="action"           value="delete_line">
                                        <input type="hidden" name="statement_line_id" value="<?php echo $sl['id']; ?>">
                                        <input type="hidden" name="bank_account_id"  value="<?php echo $f_bank; ?>">
                                        <input type="hidden" name="date_from"        value="<?php echo $f_from; ?>">
                                        <input type="hidden" name="date_to"          value="<?php echo $f_to; ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:0.72rem;padding:1px 5px;"
                                            onclick="return confirm('<?php echo __('confirm_delete'); ?>')"
                                            title="<?php echo __('delete'); ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php elseif ($sl['match_status'] === 'matched'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action"           value="unmatch">
                                        <input type="hidden" name="statement_line_id" value="<?php echo $sl['id']; ?>">
                                        <input type="hidden" name="bank_account_id"  value="<?php echo $f_bank; ?>">
                                        <input type="hidden" name="date_from"        value="<?php echo $f_from; ?>">
                                        <input type="hidden" name="date_to"          value="<?php echo $f_to; ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-warning" style="font-size:0.72rem;padding:1px 5px;" title="<?php echo __('rec_action_unmatch'); ?>">
                                            <i class="bi bi-link-45deg"></i> <?php echo __('rec_action_unmatch'); ?>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <!-- Totals -->
                            <tr class="table-light fw-bold border-top border-2">
                                <td colspan="2" class="text-end"><?php echo __('rec_totals'); ?></td>
                                <td class="text-end text-success">Rp <?php echo number_format($stmt_total_credits,0,',','.'); ?></td>
                                <td class="text-end text-danger">(Rp <?php echo number_format($stmt_total_debits,0,',','.'); ?>)</td>
                                <td colspan="2"></td>
                            </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Unmatched System Transactions -->
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-hourglass-split"></i> <?php echo __('rec_unmatched_ct_header'); ?></span>
                    <small class="text-white-50"><?php echo count($unmatched_ct); ?></small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:560px;overflow-y:auto;">
                        <table class="table table-sm table-hover mb-0" style="font-size:0.82rem;">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th><?php echo __('rec_col_date'); ?></th>
                                    <th><?php echo __('rec_col_ct_ref'); ?></th>
                                    <th><?php echo __('rec_col_type'); ?></th>
                                    <th class="text-end"><?php echo __('rec_col_amount'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($unmatched_ct)): ?>
                            <tr><td colspan="4" class="text-center text-success py-3">
                                <i class="bi bi-check-circle-fill"></i> <?php echo __('rec_all_matched'); ?>
                            </td></tr>
                            <?php else: ?>
                            <?php foreach ($unmatched_ct as $ct): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($ct['transaction_date'])); ?></td>
                                <td>
                                    <code style="font-size:0.77rem;"><?php echo htmlspecialchars($ct['reference_number']); ?></code>
                                    <br><small class="text-muted"><?php echo htmlspecialchars(mb_strimwidth($ct['description'],0,35,'…')); ?></small>
                                </td>
                                <td>
                                    <?php if ($ct['transaction_type'] === 'receipt'): ?>
                                    <span class="badge bg-success" style="font-size:0.7rem;"><i class="bi bi-arrow-down-short"></i></span>
                                    <?php else: ?>
                                    <span class="badge bg-danger" style="font-size:0.7rem;"><i class="bi bi-arrow-up-short"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-semibold <?php echo $ct['transaction_type']==='receipt'?'text-success':'text-danger'; ?>">
                                    <?php echo $ct['currency_code'] !== 'IDR' ? '<small class="badge bg-secondary me-1">'.$ct['currency_code'].'</small>' : ''; ?>
                                    Rp <?php echo number_format($ct['amount_idr'],0,',','.'); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-transparent text-muted small">
                    <i class="bi bi-info-circle"></i> <?php echo __('rec_unmatched_ct_hint'); ?>
                </div>
            </div>
        </div>
    </div><!-- /row -->
    <?php endif; ?>
</div>

<!-- ── Import Statement Line Modal ───────────────────────────────────────────-->
<?php if ($f_bank): ?>
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> <?php echo __('rec_import_modal_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action"         value="import_line">
                <input type="hidden" name="bank_account_id" value="<?php echo $f_bank; ?>">
                <input type="hidden" name="date_from"      value="<?php echo $f_from; ?>">
                <input type="hidden" name="date_to"        value="<?php echo $f_to; ?>">
                <div class="modal-body">
                    <div class="alert alert-info py-2 small">
                        <strong><?php echo htmlspecialchars($selected_bank['account_name'] ?? ''); ?></strong>
                        (<?php echo htmlspecialchars($selected_bank['currency_code'] ?? ''); ?>)
                        &mdash; <?php echo __('rec_import_hint'); ?>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php echo __('rec_field_stmt_date'); ?> *</label>
                            <input type="date" name="statement_date" class="form-control" value="<?php echo $f_to; ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('rec_field_value_date'); ?></label>
                            <input type="date" name="value_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('rec_field_currency'); ?></label>
                            <input type="text" name="currency_code" class="form-control text-uppercase" value="<?php echo htmlspecialchars($selected_bank['currency_code'] ?? 'IDR'); ?>" maxlength="3">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?php echo __('rec_field_description'); ?> *</label>
                            <input type="text" name="description" class="form-control" required maxlength="500">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php echo __('rec_field_credit'); ?></label>
                            <input type="number" name="credit_amount" id="iCredit" class="form-control" step="0.01" value="0" min="0" oninput="if(+this.value>0) document.getElementById('iDebit').value=0;">
                            <div class="form-text text-success"><?php echo __('rec_field_credit_hint'); ?></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php echo __('rec_field_debit'); ?></label>
                            <input type="number" name="debit_amount" id="iDebit" class="form-control" step="0.01" value="0" min="0" oninput="if(+this.value>0) document.getElementById('iCredit').value=0;">
                            <div class="form-text text-danger"><?php echo __('rec_field_debit_hint'); ?></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('rec_field_running_bal'); ?></label>
                            <input type="number" name="running_balance" class="form-control" step="0.01">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('rec_field_batch'); ?></label>
                            <input type="text" name="import_batch" class="form-control" value="MANUAL-<?php echo date('Ymd'); ?>" maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('rec_field_notes'); ?></label>
                            <input type="text" name="notes" class="form-control" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> <?php echo __('rec_import_save_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── CSV Import Modal ───────────────────────────────────────────────────────-->
<div class="modal fade" id="csvModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-arrow-up"></i> <?php echo __('rec_csv_modal_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action"          value="csv_import">
                <input type="hidden" name="bank_account_id" value="<?php echo $f_bank; ?>">
                <input type="hidden" name="date_from"       value="<?php echo $f_from; ?>">
                <input type="hidden" name="date_to"         value="<?php echo $f_to; ?>">
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <strong><?php echo htmlspecialchars($selected_bank['account_name'] ?? ''); ?></strong>
                        (<?php echo htmlspecialchars($selected_bank['currency_code'] ?? ''); ?>)
                    </div>
                    <div class="alert alert-secondary py-2 small">
                        <i class="bi bi-info-circle"></i> <?php echo __('rec_csv_format_hint'); ?>
                        <code class="d-block mt-1">date, description, debit, credit[, running_balance[, notes]]</code>
                        <small class="text-muted"><?php echo __('rec_csv_date_hint'); ?></small>
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?php echo __('rec_csv_file'); ?> *</label>
                            <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('rec_field_batch'); ?></label>
                            <input type="text" name="import_batch" class="form-control" value="CSV-<?php echo date('Ymd'); ?>" maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('rec_field_currency'); ?></label>
                            <input type="text" name="currency_code" class="form-control text-uppercase" value="<?php echo htmlspecialchars($selected_bank['currency_code'] ?? 'IDR'); ?>" maxlength="3">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> <?php echo __('rec_csv_upload_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Manual Match Modal ────────────────────────────────────────────────────-->
<div class="modal fade" id="matchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-link-45deg"></i> <?php echo __('rec_match_modal_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="matchForm">
                <input type="hidden" name="action"           value="match">
                <input type="hidden" name="statement_line_id" id="mSlId"   value="">
                <input type="hidden" name="bank_account_id"  value="<?php echo $f_bank; ?>">
                <input type="hidden" name="date_from"        value="<?php echo $f_from; ?>">
                <input type="hidden" name="date_to"          value="<?php echo $f_to; ?>">
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3" id="mSlInfo"></div>
                    <label class="form-label fw-semibold"><?php echo __('rec_match_select_ct'); ?></label>
                    <select name="cash_transaction_id" class="form-select" required id="mCtSelect">
                        <option value="">— <?php echo __('select'); ?> —</option>
                        <?php foreach ($unmatched_ct as $ct): ?>
                        <option value="<?php echo $ct['id']; ?>"
                            data-type="<?php echo $ct['transaction_type']; ?>"
                            data-amount="<?php echo round($ct['amount_idr']); ?>"
                            data-date="<?php echo $ct['transaction_date']; ?>">
                            <?php echo htmlspecialchars($ct['reference_number'].' | '.date('d/m/Y', strtotime($ct['transaction_date'])).' | '.ucfirst($ct['transaction_type']).' | Rp '.number_format($ct['amount_idr'],0,',','.').' | '.mb_strimwidth($ct['description'],0,40,'…')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-link"></i> <?php echo __('rec_match_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openMatchModal(slId, amount, date, type) {
    document.getElementById('mSlId').value = slId;
    document.getElementById('mSlInfo').innerHTML =
        '<?php echo __('rec_match_stmt_line'); ?>: <strong>' + (type==='receipt'?'<?php echo __('rec_col_credit'); ?>':'<?php echo __('rec_col_debit'); ?>') + '</strong> — '
        + 'Rp ' + amount.toLocaleString('id-ID') + ' (' + date + ')';
    // Pre-filter the transaction select to matching type
    const sel = document.getElementById('mCtSelect');
    Array.from(sel.options).forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (opt.dataset.type === type) ? '' : 'none';
    });
    sel.value = '';
    new bootstrap.Modal(document.getElementById('matchModal')).show();
}
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
