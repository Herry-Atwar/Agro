<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';

$db         = getDB();
$page_title = __('fxr_title');
$username   = $_SESSION['username'] ?? 'admin';

// ── POST: run revaluation / reverse ──────────────────────────────────────────
if (is_post()) {
    $action = post('action');

    // ── Reverse a prior FX Revaluation JE ────────────────────────────────────
    if ($action === 'reverse') {
        $je_id = intval(post('je_id'));
        try {
            $db->beginTransaction();

            // Fetch the original JE
            $orig = $db->prepare("SELECT * FROM journal_entries WHERE id=? AND reference_number LIKE 'FXR-%' AND status='posted'");
            $orig->execute([$je_id]);
            $orig_row = $orig->fetch(PDO::FETCH_ASSOC);
            if (!$orig_row) throw new Exception(__('fxr_err_rev_not_found'));

            // Fetch original lines
            $lines = $db->prepare("SELECT * FROM journal_entry_lines WHERE journal_entry_id=? ORDER BY line_number");
            $lines->execute([$je_id]);
            $orig_lines = $lines->fetchAll(PDO::FETCH_ASSOC);

            // Build reversal reference: REV-<original_ref>
            $rev_ref = 'REV-' . $orig_row['reference_number'];
            // Check for duplicate reversal
            $dup = $db->prepare("SELECT id FROM journal_entries WHERE reference_number=?");
            $dup->execute([$rev_ref]);
            if ($dup->fetchColumn()) throw new Exception(__('fxr_err_rev_duplicate'));

            $rev_desc = __('fxr_rev_desc_prefix') . ' ' . $orig_row['reference_number'] . ': ' . $orig_row['description'];

            $db->prepare("
                INSERT INTO journal_entries
                    (entry_date, entry_type, reference_number, description,
                     company_id, total_debit, total_credit,
                     status, posted_date, posted_by, created_by)
                VALUES (?, 'adjustment', ?, ?, ?, ?, ?, 'posted', NOW(), ?, ?)
            ")->execute([
                date('Y-m-d'),
                $rev_ref,
                $rev_desc,
                $orig_row['company_id'],
                $orig_row['total_debit'],
                $orig_row['total_credit'],
                $username,
                $username,
            ]);
            $rev_je_id = (int) $db->lastInsertId();

            // Swap debit ↔ credit on each line
            foreach ($orig_lines as $ln) {
                $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, line_number, gl_account_id, debit_amount, credit_amount, description, company_id) VALUES (?,?,?,?,?,?,?)")
                   ->execute([
                       $rev_je_id,
                       $ln['line_number'],
                       $ln['gl_account_id'],
                       $ln['credit_amount'],   // swap
                       $ln['debit_amount'],    // swap
                       $rev_desc,
                       $ln['company_id'],
                   ]);
            }

            // Mark the original JE as reversed
            $db->prepare("UPDATE journal_entries SET status='reversed', notes=CONCAT(COALESCE(notes,''), ' [Reversed by ', ?, ']') WHERE id=?")
               ->execute([$rev_ref, $je_id]);

            $db->commit();
            set_message('success', sprintf(__('fxr_msg_reversed'), $rev_ref));
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', __('fxr_err_reverse') . $e->getMessage());
        }
        redirect('fx_revaluation.php');
    }

    if ($action === 'revalue') {
        try {
            $db->beginTransaction();

            $revalue_date = post('revalue_date');
            $company_id   = intval(post('company_id')) ?: null;
            $posted_count = 0;
            $total_gain   = 0.0;
            $total_loss   = 0.0;

            // Fetch all active foreign-currency bank accounts
            $ba_stmt = $db->prepare("
                SELECT ba.id, ba.account_name, ba.currency_code, ba.gl_account_id,
                       ba.company_id,
                       -- Book balance in IDR (opening + transactions at historical rates)
                       ba.opening_balance
                       + COALESCE((
                           SELECT SUM(CASE ct.transaction_type WHEN 'receipt' THEN ct.amount_idr ELSE -ct.amount_idr END)
                           FROM cash_transactions ct
                           WHERE ct.bank_account_id = ba.id AND ct.status='posted'
                           AND ct.transaction_date <= ?
                       ), 0) AS book_balance_idr,
                       -- Foreign currency balance (opening assumed IDR=1 + foreign transactions)
                       COALESCE((
                           SELECT SUM(CASE ct.transaction_type WHEN 'receipt' THEN ct.amount_foreign ELSE -ct.amount_foreign END)
                           FROM cash_transactions ct
                           WHERE ct.bank_account_id = ba.id AND ct.status='posted'
                           AND ct.transaction_date <= ?
                       ), ba.opening_balance) AS balance_foreign
                FROM bank_accounts ba
                WHERE ba.currency_code != 'IDR'
                  AND ba.status = 'active'
                  " . ($company_id ? "AND ba.company_id = $company_id" : '') . "
            ");
            $ba_stmt->execute([$revalue_date, $revalue_date]);
            $accounts = $ba_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($accounts as $acc) {
                // Fetch closing rate for this currency on revalue_date
                $rate_stmt = $db->prepare("
                    SELECT rate FROM exchange_rates
                    WHERE currency_from=? AND currency_to='IDR' AND rate_date <= ?
                    ORDER BY rate_date DESC LIMIT 1
                ");
                $rate_stmt->execute([$acc['currency_code'], $revalue_date]);
                $closing_rate = floatval($rate_stmt->fetchColumn());
                if (!$closing_rate) continue; // skip if no rate found

                $balance_foreign    = floatval($acc['balance_foreign']);
                $book_balance_idr   = floatval($acc['book_balance_idr']);
                $revalued_idr       = $balance_foreign * $closing_rate;
                $fx_difference      = $revalued_idr - $book_balance_idr;

                if (abs($fx_difference) < 0.01) continue; // no material difference

                // Fetch FX gain/loss GL accounts from chart of accounts
                $fx_gain_gl = $db->prepare("SELECT id FROM general_ledger_accounts WHERE account_code='7110' LIMIT 1");
                $fx_gain_gl->execute();
                $fx_gain_id = $fx_gain_gl->fetchColumn();

                $fx_loss_gl = $db->prepare("SELECT id FROM general_ledger_accounts WHERE account_code='7120' LIMIT 1");
                $fx_loss_gl->execute();
                $fx_loss_id = $fx_loss_gl->fetchColumn();

                if (!$fx_gain_id || !$fx_loss_id) continue; // GL accounts not set up yet

                // Generate JE reference
                $ym  = date('Ym', strtotime($revalue_date));
                $ref = 'FXR-' . $ym;
                $last = $db->prepare("SELECT reference_number FROM journal_entries WHERE reference_number LIKE ? ORDER BY reference_number DESC LIMIT 1");
                $last->execute([$ref . '-%']);
                $seq  = ($r = $last->fetchColumn()) ? (intval(substr($r, -4)) + 1) : 1;
                $je_ref = sprintf('%s-%04d', $ref, $seq);

                $desc = sprintf('FX Revaluation: %s %s account at rate %.4f (diff: Rp %s)',
                    $acc['currency_code'], $acc['account_name'],
                    $closing_rate,
                    number_format(abs($fx_difference), 2)
                );

                $db->prepare("
                    INSERT INTO journal_entries
                        (entry_date, entry_type, reference_number, description,
                         company_id, total_debit, total_credit,
                         status, posted_date, posted_by, created_by)
                    VALUES (?, 'adjustment', ?, ?, ?, ?, ?, 'posted', NOW(), ?, ?)
                ")->execute([
                    $revalue_date, $je_ref, $desc,
                    $acc['company_id'],
                    abs($fx_difference), abs($fx_difference),
                    $username, $username,
                ]);
                $je_id = (int)$db->lastInsertId();

                if ($fx_difference > 0) {
                    // FX Gain: Dr Bank GL / Cr FX Gain (7110)
                    $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id,line_number,gl_account_id,debit_amount,credit_amount,description,company_id) VALUES (?,1,?,?,0,?,?)")
                       ->execute([$je_id, $acc['gl_account_id'], $fx_difference, $desc, $acc['company_id']]);
                    $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id,line_number,gl_account_id,debit_amount,credit_amount,description,company_id) VALUES (?,2,?,0,?,?,?)")
                       ->execute([$je_id, $fx_gain_id, $fx_difference, $desc, $acc['company_id']]);
                    $total_gain += $fx_difference;
                } else {
                    // FX Loss: Dr FX Loss (7120) / Cr Bank GL
                    $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id,line_number,gl_account_id,debit_amount,credit_amount,description,company_id) VALUES (?,1,?,?,0,?,?)")
                       ->execute([$je_id, $fx_loss_id, abs($fx_difference), $desc, $acc['company_id']]);
                    $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id,line_number,gl_account_id,debit_amount,credit_amount,description,company_id) VALUES (?,2,?,0,?,?,?)")
                       ->execute([$je_id, $acc['gl_account_id'], abs($fx_difference), $desc, $acc['company_id']]);
                    $total_loss += abs($fx_difference);
                }
                $posted_count++;
            }

            $db->commit();
            set_message('success', sprintf(__('fxr_msg_success'), $posted_count,
                number_format($total_gain,0,',','.'),
                number_format($total_loss,0,',','.')
            ));
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', __('fxr_err_revalue') . $e->getMessage());
        }
        redirect('fx_revaluation.php');
    }
}

// ── Fetch preview data: foreign-currency balances with latest rates ───────────
$f_company     = intval($_GET['company'] ?? 0);
$preview_date  = trim($_GET['date'] ?? date('Y-m-d'));

$companies = $db->query("SELECT company_id, company_name FROM companies WHERE status='Active' ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);

// Build preview
$where_co = $f_company ? "AND ba.company_id = $f_company" : '';
$preview_stmt = $db->prepare("
    SELECT ba.id, ba.account_code, ba.account_name, ba.currency_code, ba.gl_account_id,
           ba.company_id, c.company_name,
           ba.opening_balance
           + COALESCE((
               SELECT SUM(CASE ct.transaction_type WHEN 'receipt' THEN ct.amount_idr ELSE -ct.amount_idr END)
               FROM cash_transactions ct
               WHERE ct.bank_account_id=ba.id AND ct.status='posted' AND ct.transaction_date <= ?
           ), 0) AS book_balance_idr,
           COALESCE((
               SELECT SUM(CASE ct.transaction_type WHEN 'receipt' THEN ct.amount_foreign ELSE -ct.amount_foreign END)
               FROM cash_transactions ct
               WHERE ct.bank_account_id=ba.id AND ct.status='posted' AND ct.transaction_date <= ?
           ), ba.opening_balance) AS balance_foreign,
           (SELECT rate FROM exchange_rates
            WHERE currency_from=ba.currency_code AND currency_to='IDR' AND rate_date <= ?
            ORDER BY rate_date DESC LIMIT 1) AS closing_rate,
           (SELECT rate_date FROM exchange_rates
            WHERE currency_from=ba.currency_code AND currency_to='IDR' AND rate_date <= ?
            ORDER BY rate_date DESC LIMIT 1) AS rate_date
    FROM bank_accounts ba
    JOIN companies c ON ba.company_id = c.company_id
    WHERE ba.currency_code != 'IDR' AND ba.status='active' $where_co
    ORDER BY ba.currency_code, ba.account_code
");
$preview_stmt->execute([$preview_date, $preview_date, $preview_date, $preview_date]);
$previews = $preview_stmt->fetchAll(PDO::FETCH_ASSOC);

// Enrich with calculated differences
$total_net_diff = 0.0;
foreach ($previews as &$p) {
    $p['closing_rate']     = floatval($p['closing_rate'] ?? 0);
    $p['balance_foreign']  = floatval($p['balance_foreign']);
    $p['book_balance_idr'] = floatval($p['book_balance_idr']);
    $p['revalued_idr']     = $p['closing_rate'] > 0 ? round($p['balance_foreign'] * $p['closing_rate'], 2) : 0;
    $p['fx_difference']    = round($p['revalued_idr'] - $p['book_balance_idr'], 2);
    $total_net_diff       += $p['fx_difference'];
}
unset($p);

// Prior revaluation journal entries
$prior_revs = $db->query("
    SELECT je.id, je.entry_date, je.reference_number, je.description,
           je.total_debit, je.total_credit, je.posted_by
    FROM journal_entries je
    WHERE (je.reference_number LIKE 'FXR-%' OR je.reference_number LIKE 'REV-FXR-%')
    ORDER BY je.entry_date DESC, je.id DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="content-wrapper">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-arrow-repeat"></i> <?php echo __('fxr_title'); ?></h1>
                <p class="text-muted mb-0"><?php echo __('fxr_subtitle'); ?></p>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- LEFT: Preview + Run -->
        <div class="col-lg-7">

            <!-- Preview filters -->
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-eye"></i> <?php echo __('fxr_preview_header'); ?></div>
                <div class="card-body py-2">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small mb-1"><?php echo __('company'); ?></label>
                            <select name="company" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0">— <?php echo __('cfr_all_companies'); ?> —</option>
                                <?php foreach ($companies as $c): ?>
                                <option value="<?php echo $c['company_id']; ?>" <?php if($f_company==$c['company_id']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($c['company_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1"><?php echo __('fxr_preview_date'); ?></label>
                            <input type="date" name="date" class="form-control form-control-sm" value="<?php echo $preview_date; ?>">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="bi bi-calculator"></i> <?php echo __('fxr_preview_btn'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Preview table -->
            <div class="card mb-3">
                <div class="card-body p-0">
                    <table class="table table-sm mb-0" style="font-size:0.87rem;">
                        <thead class="table-light">
                            <tr>
                                <th><?php echo __('fxr_col_account'); ?></th>
                                <th class="text-center"><?php echo __('fxr_col_currency'); ?></th>
                                <th class="text-end"><?php echo __('fxr_col_foreign_bal'); ?></th>
                                <th class="text-end"><?php echo __('fxr_col_book_idr'); ?></th>
                                <th class="text-center"><?php echo __('fxr_col_rate'); ?></th>
                                <th class="text-end"><?php echo __('fxr_col_revalued'); ?></th>
                                <th class="text-end fw-bold"><?php echo __('fxr_col_difference'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($previews)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4"><?php echo __('fxr_no_foreign_accounts'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($previews as $p): ?>
                        <tr class="<?php echo abs($p['fx_difference']) < 0.01 ? 'text-muted' : ''; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($p['account_code']); ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars($p['account_name']); ?></small>
                                <br><small class="text-muted"><?php echo htmlspecialchars($p['company_name']); ?></small>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?php echo $p['currency_code']; ?></span>
                            </td>
                            <td class="text-end"><?php echo number_format($p['balance_foreign'],2,',','.'); ?></td>
                            <td class="text-end">Rp <?php echo number_format($p['book_balance_idr'],0,',','.'); ?></td>
                            <td class="text-center">
                                <?php if ($p['closing_rate'] > 0): ?>
                                <span title="<?php echo date('d/m/Y', strtotime($p['rate_date'])); ?>"><?php echo number_format($p['closing_rate'],2,',','.'); ?></span>
                                <br><small class="text-muted"><?php echo date('d/m/Y', strtotime($p['rate_date'])); ?></small>
                                <?php else: ?>
                                <span class="text-danger small"><?php echo __('fxr_no_rate'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">Rp <?php echo number_format($p['revalued_idr'],0,',','.'); ?></td>
                            <td class="text-end fw-bold">
                                <?php if (abs($p['fx_difference']) < 0.01): ?>
                                <span class="text-muted">—</span>
                                <?php elseif ($p['fx_difference'] > 0): ?>
                                <span class="text-success">+Rp <?php echo number_format($p['fx_difference'],0,',','.'); ?></span>
                                <small class="d-block text-success" style="font-size:0.7rem;"><?php echo __('fxr_gain'); ?></small>
                                <?php else: ?>
                                <span class="text-danger">(Rp <?php echo number_format(abs($p['fx_difference']),0,',','.'); ?>)</span>
                                <small class="d-block text-danger" style="font-size:0.7rem;"><?php echo __('fxr_loss'); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Net row -->
                        <tr class="table-light fw-bold border-top border-2">
                            <td colspan="6" class="text-end"><?php echo __('fxr_net_diff'); ?></td>
                            <td class="text-end <?php echo $total_net_diff >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $total_net_diff >= 0 ? '+' : ''; ?>Rp <?php echo number_format($total_net_diff,0,',','.'); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Run revaluation form -->
            <?php if (!empty($previews) && abs($total_net_diff) >= 0.01): ?>
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo __('fxr_run_header'); ?>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning py-2 small mb-3">
                        <?php echo __('fxr_run_warning'); ?>
                    </div>
                    <form method="POST" onsubmit="return confirm('<?php echo __('fxr_confirm_run'); ?>')">
                        <input type="hidden" name="action" value="revalue">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold"><?php echo __('fxr_run_date'); ?> *</label>
                                <input type="date" name="revalue_date" class="form-control" value="<?php echo $preview_date; ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo __('company'); ?></label>
                                <select name="company_id" class="form-select">
                                    <option value="0"><?php echo __('cfr_all_companies'); ?></option>
                                    <?php foreach ($companies as $c): ?>
                                    <option value="<?php echo $c['company_id']; ?>" <?php if($f_company==$c['company_id']) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($c['company_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-warning w-100 fw-bold">
                                    <i class="bi bi-arrow-repeat"></i> <?php echo __('fxr_run_btn'); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php elseif (!empty($previews)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i> <?php echo __('fxr_no_adjustment_needed'); ?>
            </div>
            <?php endif; ?>

        </div>

        <!-- RIGHT: Prior Revaluations -->
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-clock-history"></i> <?php echo __('fxr_history_header'); ?></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" style="font-size:0.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th><?php echo __('fxr_hist_col_date'); ?></th>
                                    <th><?php echo __('fxr_hist_col_ref'); ?></th>
                                    <th class="text-end"><?php echo __('fxr_hist_col_amount'); ?></th>
                                    <th><?php echo __('fxr_hist_col_by'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($prior_revs)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3"><?php echo __('fxr_no_history'); ?></td></tr>
                            <?php else: ?>
                            <?php foreach ($prior_revs as $pr): ?>
                            <?php $is_reversed = str_starts_with($pr['reference_number'], 'REV-'); ?>
                            <tr class="<?php echo $is_reversed ? 'table-secondary text-muted' : ''; ?>">
                                <td><?php echo date('d/m/Y', strtotime($pr['entry_date'])); ?></td>
                                <td>
                                    <a href="journal_entry_detail.php?id=<?php echo $pr['id']; ?>" class="text-decoration-none">
                                        <code style="font-size:0.78rem;"><?php echo htmlspecialchars($pr['reference_number']); ?></code>
                                    </a>
                                    <br><small class="text-muted"><?php echo htmlspecialchars(mb_strimwidth($pr['description'],0,40,'…')); ?></small>
                                </td>
                                <td class="text-end">Rp <?php echo number_format($pr['total_debit'],0,',','.'); ?></td>
                                <td><small><?php echo htmlspecialchars($pr['posted_by'] ?? ''); ?></small></td>
                                <td>
                                    <?php if (!$is_reversed): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="reverse">
                                        <input type="hidden" name="je_id"  value="<?php echo $pr['id']; ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-warning"
                                            style="font-size:0.72rem;padding:1px 6px;"
                                            onclick="return confirm('<?php echo __('fxr_confirm_reverse'); ?>')"
                                            title="<?php echo __('fxr_action_reverse'); ?>">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <span class="badge bg-secondary" style="font-size:0.68rem;"><?php echo __('fxr_reversed_badge'); ?></span>
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

            <!-- Info box -->
            <div class="card mt-3 border-info">
                <div class="card-body py-2 small">
                    <strong class="text-info"><i class="bi bi-info-circle"></i> <?php echo __('fxr_how_it_works'); ?></strong>
                    <ol class="ps-3 mb-0 mt-1" style="line-height:1.8;">
                        <li><?php echo __('fxr_step_1'); ?></li>
                        <li><?php echo __('fxr_step_2'); ?></li>
                        <li><?php echo __('fxr_step_3'); ?></li>
                        <li><?php echo __('fxr_step_4'); ?></li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
