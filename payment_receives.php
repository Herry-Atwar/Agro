<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_payment_receives');

// ─── Auto-generate payment number ─────────────────────────────────────────────
function gen_payment_number(PDO $db): string {
    $ym = date('Ym');
    $prefix = 'PAY-' . $ym . '-';
    $stmt = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(payment_number,'-',-1) AS UNSIGNED)),0)+1
        FROM payment_receives WHERE payment_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── Create Journal Entry for payment receipt ─────────────────────────────────
function create_payment_journal(PDO $db, array $pay, array $do_row): ?int {
    // Dr Bank/Cash, Cr AR
    $ar_map   = ['CPO'=>'1121','Kernel'=>'1122','FFB'=>'1123','PKO'=>'1121','Other'=>'1120'];
    $bank_code = '1112'; // Cash in Bank - Operations
    $ar_code   = $ar_map[$do_row['product_type']] ?? '1120';

    $bank_id = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='$bank_code' LIMIT 1")->fetchColumn();
    $ar_id   = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='$ar_code'   LIMIT 1")->fetchColumn();
    if (!$bank_id || !$ar_id) return null;

    $ym     = date('Ym', strtotime($pay['payment_date']));
    $prefix = 'JE-PAY-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reference_number,'-',-1) AS UNSIGNED)),0)+1
        FROM journal_entries WHERE reference_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    $ref = $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);

    $amount = (float)$pay['payment_amount'];

    $db->prepare("
        INSERT INTO journal_entries
            (entry_date, entry_type, reference_number, description,
             company_id, total_debit, total_credit,
             currency_code, status, posted_date, posted_by, created_by)
        VALUES (?, 'sales_invoice', ?, ?, ?, ?, ?, 'IDR', 'posted', NOW(), 1, 'system')
    ")->execute([
        $pay['payment_date'], $ref,
        'Payment received — ' . $pay['payment_number'] . ' / ' . $do_row['invoice_number'],
        $pay['company_id'],
        $amount, $amount,
    ]);
    $je_id = (int)$db->lastInsertId();

    // Dr Bank
    $db->prepare("
        INSERT INTO journal_entry_lines
            (journal_entry_id, line_number, gl_account_id, debit_amount, credit_amount, description)
        VALUES (?,1,?,?,0,?)
    ")->execute([$je_id, $bank_id, $amount, 'Bank receipt — ' . ($pay['bank_name'] ?? '') . ' ' . ($pay['reference_number'] ?? '')]);

    // Cr AR
    $db->prepare("
        INSERT INTO journal_entry_lines
            (journal_entry_id, line_number, gl_account_id, debit_amount, credit_amount, description)
        VALUES (?,2,?,0,?,?)
    ")->execute([$je_id, $ar_id, $amount, 'Clear AR — ' . $do_row['invoice_number']]);

    return $je_id;
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'create_payment') {
        try {
            $db->beginTransaction();
            $num = gen_payment_number($db);
            $do_id = (int)post('do_id');

            // Fetch DO info
            $stmt = $db->prepare("
                SELECT do2.*, cu.customer_name
                FROM delivery_orders do2
                JOIN customers cu ON do2.customer_id = cu.customer_id
                WHERE do2.do_id = ?
            ");
            $stmt->execute([$do_id]);
            $do_row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$do_row) throw new Exception('Delivery order not found.');
            if ($do_row['status'] !== 'invoiced') throw new Exception('Payment can only be recorded for invoiced DOs.');

            $pay = [
                'payment_number'   => $num,
                'payment_date'     => post('payment_date'),
                'do_id'            => $do_id,
                'company_id'       => $do_row['company_id'],
                'customer_id'      => $do_row['customer_id'],
                'payment_amount'   => (float)post('payment_amount'),
                'currency'         => post('currency') ?: 'IDR',
                'payment_method'   => post('payment_method'),
                'bank_name'        => post('bank_name'),
                'bank_account'     => post('bank_account'),
                'reference_number' => post('reference_number'),
                'payment_type'     => post('payment_type') ?: 'full',
                'notes'            => post('notes'),
            ];

            $je_id = create_payment_journal($db, $pay, $do_row);

            $db->prepare("
                INSERT INTO payment_receives
                    (payment_number, payment_date, do_id, company_id, customer_id,
                     payment_amount, currency, payment_method, bank_name, bank_account,
                     reference_number, payment_type, notes, journal_entry_id, created_by)
                VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,'admin')
            ")->execute([
                $pay['payment_number'], $pay['payment_date'], $pay['do_id'],
                $pay['company_id'], $pay['customer_id'],
                $pay['payment_amount'], $pay['currency'], $pay['payment_method'],
                $pay['bank_name'], $pay['bank_account'],
                $pay['reference_number'], $pay['payment_type'], $pay['notes'], $je_id,
            ]);

            // Check if DO is fully paid and update DO status → mark payment
            $total_paid_stmt = $db->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM payment_receives WHERE do_id=?");
            $total_paid_stmt->execute([$do_id]);
            $total_paid = (float)$total_paid_stmt->fetchColumn();

            if ($total_paid >= (float)$do_row['total_amount']) {
                $db->prepare("UPDATE delivery_orders SET status='invoiced' WHERE do_id=?")->execute([$do_id]);
                // Update related sales contract status
                $db->prepare("
                    UPDATE sales_contracts sc
                    JOIN (
                        SELECT contract_id,
                               SUM(quantity_mt) AS delivered_mt
                        FROM delivery_orders
                        WHERE status NOT IN ('draft','cancelled')
                        GROUP BY contract_id
                    ) d ON sc.contract_id = d.contract_id
                    SET sc.status = CASE
                        WHEN d.delivered_mt >= sc.quantity_mt THEN 'fully_delivered'
                        WHEN d.delivered_mt > 0 THEN 'partially_delivered'
                        ELSE sc.status END
                    WHERE sc.contract_id = ?
                ")->execute([$do_row['contract_id']]);
            }

            $db->commit();
            set_message('success', "Payment <b>$num</b> recorded. Journal entry auto-posted. (Dr Bank / Cr AR)");
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('payment_receives.php?do_id=' . (int)post('do_id'));
    }

    if ($action === 'delete_payment') {
        try {
            $pay_id = (int)post('payment_id');
            $stmt = $db->prepare("SELECT journal_entry_id FROM payment_receives WHERE payment_id=?");
            $stmt->execute([$pay_id]);
            $je_id = (int)$stmt->fetchColumn();

            $db->beginTransaction();
            $db->prepare("DELETE FROM payment_receives WHERE payment_id=?")->execute([$pay_id]);
            if ($je_id) {
                $db->prepare("DELETE FROM journal_entry_lines WHERE journal_entry_id=?")->execute([$je_id]);
                $db->prepare("DELETE FROM journal_entries       WHERE id=?")->execute([$je_id]);
            }
            $db->commit();
            set_message('success', 'Payment record and journal entry deleted.');
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('payment_receives.php?do_id=' . (int)post('do_id'));
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$do_id_filter    = (int)get('do_id', 0);
$year            = get('year', date('Y'));
$company_filter  = get('company_id', '');
$search          = get('search', '');

// ─── Reference data ───────────────────────────────────────────────────────────
$companies = $db->query("SELECT * FROM companies ORDER BY company_code")->fetchAll(PDO::FETCH_ASSOC);

// Invoiced DOs available for payment (filtered)
$dos_for_payment = $db->query("
    SELECT do2.do_id, do2.do_number, do2.invoice_number, do2.total_amount,
           do2.company_id, do2.customer_id, cu.customer_name,
           COALESCE(SUM(pr.payment_amount),0) AS paid_amount,
           do2.total_amount - COALESCE(SUM(pr.payment_amount),0) AS outstanding
    FROM delivery_orders do2
    JOIN customers cu ON do2.customer_id = cu.customer_id
    LEFT JOIN payment_receives pr ON pr.do_id = do2.do_id
    WHERE do2.status = 'invoiced'
    GROUP BY do2.do_id
    HAVING outstanding > 0
    ORDER BY do2.invoice_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$dos_map = [];
foreach ($dos_for_payment as $d) { $dos_map[$d['do_id']] = $d; }

// ─── Payment list ─────────────────────────────────────────────────────────────
try {
    $sql = "SELECT pr.*, do2.do_number, do2.invoice_number, do2.total_amount AS invoice_amount,
                   c.company_name, cu.customer_name, do2.product_type,
                   je.reference_number AS je_ref
            FROM payment_receives pr
            JOIN delivery_orders do2 ON pr.do_id = do2.do_id
            JOIN companies  c  ON pr.company_id  = c.company_id
            JOIN customers  cu ON pr.customer_id = cu.customer_id
            LEFT JOIN journal_entries je ON pr.journal_entry_id = je.id
            WHERE YEAR(pr.payment_date)=?";
    $p = [$year];
    if ($do_id_filter)    { $sql .= " AND pr.do_id=?";       $p[] = $do_id_filter; }
    if ($company_filter)  { $sql .= " AND pr.company_id=?";  $p[] = $company_filter; }
    if ($search) {
        $sql .= " AND (pr.payment_number LIKE ? OR do2.invoice_number LIKE ? OR cu.customer_name LIKE ?)";
        $t="%$search%"; $p[]=$t; $p[]=$t; $p[]=$t;
    }
    $sql .= " ORDER BY pr.payment_date DESC, pr.payment_id DESC";
    $stmt = $db->prepare($sql); $stmt->execute($p);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $payments = []; }

$selected_do = $do_id_filter ? ($dos_map[$do_id_filter] ?? null) : null;

require_once 'includes/header.php';
?>

<style>
    .pay-green { color: #16a34a !important; }
    .bg-pay    { background-color: #16a34a !important; }
    .btn-pay   { background-color: #16a34a; color:#fff; border:none; }
    .btn-pay:hover { background-color: #15803d; color:#fff; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="pay-green"><i class="bi bi-cash-coin"></i> Payment Receives</h1>
            <p class="text-muted mb-0">
                <a href="sales_contracts.php" class="text-decoration-none">Sales Contracts</a>
                &rsaquo; <a href="delivery_orders.php" class="text-decoration-none">Delivery Orders</a>
                &rsaquo; <b>Payment Receives</b>
                <?php if ($selected_do): ?>
                    &nbsp;|&nbsp; DO: <strong><?= htmlspecialchars($selected_do['do_number']) ?></strong>
                    Invoice: <strong><?= htmlspecialchars($selected_do['invoice_number']) ?></strong>
                <?php endif; ?>
            </p>
        </div>
        <div class="col-auto">
            <button class="btn btn-pay" data-bs-toggle="modal" data-bs-target="#createPayModal">
                <i class="bi bi-plus-circle"></i> Record Payment
            </button>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- Outstanding receivables alert -->
<?php if (!empty($dos_for_payment)): ?>
<div class="alert alert-warning d-flex align-items-center mb-3 py-2">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <span>
        <strong><?= count($dos_for_payment) ?></strong> invoiced delivery order(s) with outstanding balance.
        Total outstanding: <strong>Rp <?= number_format(array_sum(array_column($dos_for_payment,'outstanding')),0) ?></strong>
    </span>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-header bg-pay text-white py-2"><i class="bi bi-funnel"></i> Filter Payments</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="Payment # / invoice / customer…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y=date('Y')-2;$y<=date('Y')+1;$y++): ?>
                        <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="company_id" class="form-select form-select-sm">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?=$c['company_id']?>" <?=$c['company_id']==$company_filter?'selected':''?>>
                            <?= htmlspecialchars($c['company_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-pay btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="payment_receives.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- KPI -->
<?php
$total_received    = array_sum(array_column($payments,'payment_amount'));
$journal_cnt       = count(array_filter($payments,fn($r)=>!empty($r['je_ref'])));
$bank_transfer_cnt = count(array_filter($payments,fn($r)=>$r['payment_method']==='bank_transfer'));
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Total Receipts</div><div class="fw-bold fs-4"><?=count($payments)?></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Amount Received</div>
        <div class="fw-bold fs-5 text-success">Rp <?=number_format($total_received/1000000,1)?>M</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Journal Entries</div>
        <div class="fw-bold fs-5 text-info"><?=$journal_cnt?></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Bank Transfers</div>
        <div class="fw-bold fs-5"><?=$bank_transfer_cnt?></div>
    </div></div></div>
</div>

<!-- Outstanding Receivables Summary -->
<?php if (!empty($dos_for_payment)): ?>
<div class="card mb-3">
    <div class="card-header bg-warning text-dark py-2">
        <i class="bi bi-clock-history"></i> Outstanding Receivables
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>DO #</th><th>Invoice #</th><th>Customer</th>
                        <th class="text-end">Invoice Amt</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Outstanding</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dos_for_payment as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars($d['do_number']) ?></td>
                            <td><?= htmlspecialchars($d['invoice_number']) ?></td>
                            <td><?= htmlspecialchars($d['customer_name']) ?></td>
                            <td class="text-end"><?= number_format($d['total_amount'],0) ?></td>
                            <td class="text-end text-success"><?= number_format($d['paid_amount'],0) ?></td>
                            <td class="text-end fw-bold text-danger"><?= number_format($d['outstanding'],0) ?></td>
                            <td>
                                <button class="btn btn-sm btn-pay"
                                        onclick="prefillPayModal(<?= htmlspecialchars(json_encode($d)) ?>)">
                                    <i class="bi bi-cash-coin"></i> Pay
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Payment History -->
<div class="card">
    <div class="card-header bg-pay text-white py-2">
        <i class="bi bi-table"></i> <?=count($payments)?> Payment Receipt(s) — <?=$year?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Payment #</th>
                        <th>Date</th>
                        <th>DO / Invoice</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th class="text-end">Amount (Rp)</th>
                        <th>Method</th>
                        <th>Type</th>
                        <th>Journal</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-3">No payment records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($payments as $r): ?>
                            <?php $method_badges=['bank_transfer'=>'primary','cash'=>'success','cheque'=>'info','giro'=>'warning']; ?>
                            <tr>
                                <td class="fw-bold"><?=htmlspecialchars($r['payment_number'])?></td>
                                <td><?=date('d/m/Y',strtotime($r['payment_date']))?></td>
                                <td>
                                    <a href="delivery_orders.php?do_id=<?=$r['do_id']?>">
                                        <?=htmlspecialchars($r['do_number'])?>
                                    </a><br>
                                    <small class="text-muted"><?=htmlspecialchars($r['invoice_number']??'')?></small>
                                </td>
                                <td><?=htmlspecialchars($r['customer_name'])?><br>
                                    <small class="text-muted"><?=htmlspecialchars($r['company_name'])?></small></td>
                                <td><span class="badge bg-secondary"><?=$r['product_type']?></span></td>
                                <td class="text-end fw-bold text-success"><?=number_format($r['payment_amount'],0)?></td>
                                <td><span class="badge bg-<?=$method_badges[$r['payment_method']]??'secondary'?>">
                                    <?=str_replace('_',' ',ucfirst($r['payment_method']))?>
                                </span></td>
                                <td><span class="badge bg-<?= $r['payment_type']==='full'?'success':($r['payment_type']==='partial'?'warning':'info') ?>">
                                    <?=ucfirst($r['payment_type'])?>
                                </span></td>
                                <td>
                                    <?php if ($r['je_ref']): ?>
                                        <a href="journal_entry_detail.php?ref=<?=urlencode($r['je_ref'])?>"
                                           class="badge bg-info text-dark text-decoration-none">
                                            <?=htmlspecialchars($r['je_ref'])?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete payment <?=htmlspecialchars($r['payment_number'])?> and its journal entry?')">
                                        <input type="hidden" name="action" value="delete_payment">
                                        <input type="hidden" name="payment_id" value="<?=$r['payment_id']?>">
                                        <input type="hidden" name="do_id" value="<?=$r['do_id']?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
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

<!-- Create Payment Modal -->
<div class="modal fade" id="createPayModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <input type="hidden" name="action" value="create_payment">
            <div class="modal-content">
                <div class="modal-header bg-pay text-white">
                    <h5 class="modal-title"><i class="bi bi-cash-coin"></i> Record Payment Received</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle"></i>
                        Recording payment will automatically post a journal entry:<br>
                        <strong>Dr Cash/Bank → Cr Accounts Receivable</strong>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Invoice / Delivery Order *</label>
                            <select name="do_id" id="payDoSel" class="form-select form-select-sm" required
                                    onchange="fillOutstanding(this)">
                                <option value="">— Select DO —</option>
                                <?php foreach ($dos_for_payment as $d): ?>
                                    <option value="<?=$d['do_id']?>"
                                            data-outstanding="<?=$d['outstanding']?>"
                                            <?=$d['do_id']==$do_id_filter?'selected':''?>>
                                        <?=htmlspecialchars($d['do_number'])?> / <?=htmlspecialchars($d['invoice_number'])?>
                                        — <?=htmlspecialchars($d['customer_name'])?>
                                        (Rp <?=number_format($d['outstanding'],0)?> outstanding)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Payment Date *</label>
                            <input type="date" name="payment_date" id="payDate"
                                   class="form-control form-control-sm" value="<?=date('Y-m-d')?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Payment Type *</label>
                            <select name="payment_type" class="form-select form-select-sm">
                                <option value="full">Full</option>
                                <option value="partial">Partial</option>
                                <option value="advance">Advance</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Amount (IDR) *</label>
                            <input type="number" step="0.01" name="payment_amount" id="payAmount"
                                   class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Payment Method *</label>
                            <select name="payment_method" class="form-select form-select-sm" required>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="giro">Giro</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reference #</label>
                            <input type="text" name="reference_number" class="form-control form-control-sm"
                                   placeholder="TT / Cheque number…">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bank Account</label>
                            <input type="text" name="bank_account" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-pay btn-sm"><i class="bi bi-save"></i> Record Payment</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function fillOutstanding(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('payAmount').value = opt.dataset.outstanding || '';
}
function prefillPayModal(d) {
    document.getElementById('payDoSel').value = d.do_id;
    document.getElementById('payAmount').value = d.outstanding;
    new bootstrap.Modal(document.getElementById('createPayModal')).show();
}
window.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('payDoSel');
    if (sel && sel.value) fillOutstanding(sel);
});
</script>

<?php require_once 'includes/footer.php'; ?>
