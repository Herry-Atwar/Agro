<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';

$db = getDB();
$page_title = __('cb_book_title');

// ── Filters ───────────────────────────────────────────────────────────────────
$f_bank    = intval($_GET['bank']      ?? 0);
$f_from    = trim($_GET['date_from']   ?? date('Y-01-01'));
$f_to      = trim($_GET['date_to']     ?? date('Y-m-d'));
$f_type    = trim($_GET['type']        ?? '');

// Bank accounts dropdown
$bank_accounts = $db->query("
    SELECT ba.id, ba.account_code, ba.account_name, ba.currency_code, ba.opening_balance, ba.opening_date,
           c.company_name
    FROM bank_accounts ba
    JOIN companies c ON ba.company_id = c.company_id
    WHERE ba.status = 'active'
    ORDER BY ba.account_code
")->fetchAll(PDO::FETCH_ASSOC);

// Selected bank account details
$selected_bank = null;
if ($f_bank) {
    foreach ($bank_accounts as $b) {
        if ($b['id'] == $f_bank) { $selected_bank = $b; break; }
    }
}

// ── Opening balance up to filter start date ───────────────────────────────────
$opening_balance = 0.00;
if ($f_bank) {
    // Base opening balance seeded at account creation
    $ob_row = $db->prepare("SELECT opening_balance, opening_date FROM bank_accounts WHERE id=?");
    $ob_row->execute([$f_bank]);
    $ob = $ob_row->fetch(PDO::FETCH_ASSOC);

    // Add all posted transactions before the filter start date
    $pre = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN transaction_type='receipt' THEN amount_idr ELSE -amount_idr END), 0)
        FROM cash_transactions
        WHERE bank_account_id = ? AND status = 'posted' AND transaction_date < ?
    ");
    $pre->execute([$f_bank, $f_from]);
    $opening_balance = floatval($ob['opening_balance'] ?? 0) + floatval($pre->fetchColumn());
}

// ── Transactions in period ────────────────────────────────────────────────────
$where  = ['ct.bank_account_id=?', 'ct.status=?', 'ct.transaction_date BETWEEN ? AND ?'];
$params = [$f_bank ?: 0, 'posted', $f_from, $f_to];

if ($f_type) { $where[] = 'ct.transaction_type=?'; $params[] = $f_type; }

$rows = [];
if ($f_bank) {
    $stmt = $db->prepare("
        SELECT
            ct.id, ct.reference_number, ct.transaction_date, ct.transaction_type,
            ct.currency_code, ct.amount_foreign, ct.exchange_rate,
            ct.amount_foreign * ct.exchange_rate AS amount_idr,
            ct.description, ct.payee_payer_name,
            ct.cf_category, cs.code AS cf_code, cs.name AS cf_subcat_name,
            gla.account_code AS contra_gl_code, gla.account_name AS contra_gl_name,
            ct.journal_entry_id
        FROM cash_transactions ct
        LEFT JOIN cf_subcategories cs        ON ct.cf_subcategory_id    = cs.id
        JOIN  general_ledger_accounts gla    ON ct.contra_gl_account_id = gla.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ct.transaction_date ASC, ct.id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Build running balance ─────────────────────────────────────────────────────
$running  = $opening_balance;
$tot_in   = 0.0;
$tot_out  = 0.0;
foreach ($rows as &$r) {
    $amt = floatval($r['amount_idr']);
    if ($r['transaction_type'] === 'receipt') {
        $r['debit_idr']  = $amt;
        $r['credit_idr'] = 0;
        $tot_in  += $amt;
        $running += $amt;
    } else {
        $r['debit_idr']  = 0;
        $r['credit_idr'] = $amt;
        $tot_out += $amt;
        $running -= $amt;
    }
    $r['balance'] = $running;
}
unset($r);
$closing_balance = $running;

require_once 'includes/header.php';
?>

<div class="content-wrapper">
    <!-- Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-journal-bookmark"></i> <?php echo __('cb_book_title'); ?></h1>
                <p class="text-muted mb-0"><?php echo __('cb_book_subtitle'); ?></p>
            </div>
            <?php if ($f_bank && !empty($rows)): ?>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer"></i> <?php echo __('cb_book_print'); ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1 fw-semibold"><?php echo __('cb_book_select_bank'); ?> *</label>
                    <select name="bank" class="form-select" required onchange="this.form.submit()">
                        <option value="">— <?php echo __('cb_book_choose_account'); ?> —</option>
                        <?php foreach ($bank_accounts as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php if($f_bank==$b['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($b['account_code'].' – '.$b['account_name'].' ('.$b['currency_code'].')'); ?>
                            — <?php echo htmlspecialchars($b['company_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('cb_txn_date_from'); ?></label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $f_from; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('cb_txn_date_to'); ?></label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $f_to; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('cb_txn_filter_type'); ?></label>
                    <select name="type" class="form-select">
                        <option value="">— All —</option>
                        <option value="receipt" <?php if($f_type==='receipt') echo 'selected'; ?>>Receipt only</option>
                        <option value="payment" <?php if($f_type==='payment') echo 'selected'; ?>>Payment only</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i> <?php echo __('search'); ?></button>
                    <a href="cash_book.php" class="btn btn-outline-secondary btn-sm px-2"><i class="bi bi-x"></i></a>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$f_bank): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle"></i> <?php echo __('cb_book_select_prompt'); ?></div>
    <?php else: ?>

    <!-- Account header card -->
    <div class="card mb-3" style="border-left:4px solid var(--primary-color);">
        <div class="card-body py-3">
            <div class="row align-items-center">
                <div class="col-md-5">
                    <div class="fw-bold fs-5"><?php echo htmlspecialchars($selected_bank['account_name']); ?></div>
                    <div class="text-muted small"><?php echo htmlspecialchars($selected_bank['account_code']); ?> &middot; <?php echo htmlspecialchars($selected_bank['currency_code']); ?> &middot; <?php echo htmlspecialchars($selected_bank['company_name']); ?></div>
                    <div class="text-muted small"><?php echo __('cb_book_period'); ?>: <strong><?php echo $f_from; ?></strong> — <strong><?php echo $f_to; ?></strong></div>
                </div>
                <div class="col-md-7">
                    <div class="row text-center g-0">
                        <div class="col-4 border-end">
                            <div class="small text-muted"><?php echo __('cb_book_opening'); ?></div>
                            <div class="fw-bold">Rp <?php echo number_format($opening_balance,0,',','.'); ?></div>
                        </div>
                        <div class="col-4 border-end">
                            <div class="small text-muted"><?php echo __('cb_book_net_movement'); ?></div>
                            <?php $net = $tot_in - $tot_out; ?>
                            <div class="fw-bold <?php echo $net >= 0 ? 'text-success' : 'text-danger'; ?>">
                                Rp <?php echo ($net >= 0 ? '+' : '') . number_format($net,0,',','.'); ?>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted"><?php echo __('cb_book_closing'); ?></div>
                            <div class="fw-bold <?php echo $closing_balance < 0 ? 'text-danger' : 'text-success'; ?>">
                                Rp <?php echo number_format($closing_balance,0,',','.'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cash Book table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table"></i> <?php echo __('cb_book_list_header'); ?></span>
            <small class="text-white-50"><?php echo count($rows); ?> <?php echo __('records'); ?></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:0.87rem;" id="cashBookTable">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo __('cb_book_col_date'); ?></th>
                            <th><?php echo __('cb_book_col_ref'); ?></th>
                            <th><?php echo __('cb_book_col_description'); ?></th>
                            <th><?php echo __('cb_book_col_cf_line'); ?></th>
                            <th><?php echo __('cb_book_col_contra'); ?></th>
                            <?php if ($selected_bank && $selected_bank['currency_code'] !== 'IDR'): ?>
                            <th class="text-end"><?php echo __('cb_book_col_foreign'); ?></th>
                            <th class="text-center"><?php echo __('cb_book_col_rate'); ?></th>
                            <?php endif; ?>
                            <th class="text-end text-success"><?php echo __('cb_book_col_receipt'); ?></th>
                            <th class="text-end text-danger"><?php echo __('cb_book_col_payment'); ?></th>
                            <th class="text-end fw-bold"><?php echo __('cb_book_col_balance'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Opening balance row -->
                        <tr class="table-secondary fw-semibold">
                            <td><?php echo $f_from; ?></td>
                            <td colspan="<?php echo ($selected_bank && $selected_bank['currency_code'] !== 'IDR') ? 6 : 4; ?>">
                                <em><?php echo __('cb_book_opening_row'); ?></em>
                            </td>
                            <td class="text-end"></td>
                            <td class="text-end"></td>
                            <td class="text-end">Rp <?php echo number_format($opening_balance, 0, ',', '.'); ?></td>
                        </tr>

                        <?php if (empty($rows)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4"><?php echo __('no_data'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($r['transaction_date'])); ?></td>
                            <td>
                                <code style="font-size:0.78rem;"><?php echo htmlspecialchars($r['reference_number']); ?></code>
                                <?php if ($r['journal_entry_id']): ?>
                                <a href="journal_entry_detail.php?id=<?php echo $r['journal_entry_id']; ?>" class="ms-1 text-muted small" title="View Journal Entry">↗</a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($r['description']); ?>
                                <?php if ($r['payee_payer_name']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($r['payee_payer_name']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($r['cf_subcat_name'] ?? $r['cf_category'] ?? '—'); ?></small></td>
                            <td><small><?php echo htmlspecialchars($r['contra_gl_code'].' '.$r['contra_gl_name']); ?></small></td>
                            <?php if ($selected_bank && $selected_bank['currency_code'] !== 'IDR'): ?>
                            <td class="text-end">
                                <small class="badge bg-secondary"><?php echo $r['currency_code']; ?></small>
                                <?php echo number_format($r['amount_foreign'], 2, ',', '.'); ?>
                            </td>
                            <td class="text-center"><small><?php echo number_format($r['exchange_rate'], 2, ',', '.'); ?></small></td>
                            <?php endif; ?>
                            <td class="text-end text-success fw-semibold">
                                <?php echo $r['debit_idr'] > 0 ? 'Rp '.number_format($r['debit_idr'],0,',','.') : ''; ?>
                            </td>
                            <td class="text-end text-danger fw-semibold">
                                <?php echo $r['credit_idr'] > 0 ? 'Rp '.number_format($r['credit_idr'],0,',','.') : ''; ?>
                            </td>
                            <td class="text-end fw-bold <?php echo $r['balance'] < 0 ? 'text-danger' : ''; ?>">
                                Rp <?php echo number_format($r['balance'], 0, ',', '.'); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Totals row -->
                        <tr class="table-light fw-bold border-top border-2">
                            <td colspan="<?php echo ($selected_bank && $selected_bank['currency_code'] !== 'IDR') ? 7 : 5; ?>"
                                class="text-end"><?php echo __('cb_book_total'); ?></td>
                            <td class="text-end text-success">Rp <?php echo number_format($tot_in,  0, ',', '.'); ?></td>
                            <td class="text-end text-danger">Rp <?php echo number_format($tot_out, 0, ',', '.'); ?></td>
                            <td class="text-end <?php echo $closing_balance < 0 ? 'text-danger' : 'text-primary'; ?>">
                                Rp <?php echo number_format($closing_balance, 0, ',', '.'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<style>
@media print {
    .sidebar, #sidebarToggle, nav.navbar, .page-header button,
    form, .card-header small { display: none !important; }
    .content-wrapper { padding: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ccc !important; }
    #cashBookTable { font-size: 0.78rem; }
}
</style>

<?php require_once 'includes/footer.php'; ?>
