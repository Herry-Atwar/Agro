<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/plasma_accounting.php';

$db         = getDB();
$page_title = __('pt_plasma_payments');

// ─── Auto-migrate ─────────────────────────────────────────────────────────────
(function (PDO $db) {
    // Create table with journal_posted column included
    $db->exec("
        CREATE TABLE IF NOT EXISTS plasma_payments (
            id               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
            payment_no       VARCHAR(30)     NOT NULL UNIQUE,
            farmer_id        INT UNSIGNED    NOT NULL,
            period_start     DATE            NOT NULL,
            period_end       DATE            NOT NULL,
            total_kg         DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            ffb_price_per_kg DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
            gross_amount     DECIMAL(18,2)   GENERATED ALWAYS AS (total_kg * ffb_price_per_kg) STORED,
            deduction_pct    DECIMAL(5,2)    NOT NULL DEFAULT 30.00,
            loan_deduction   DECIMAL(18,2)   GENERATED ALWAYS AS (ROUND(total_kg * ffb_price_per_kg * deduction_pct / 100, 2)) STORED,
            other_deduction  DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
            net_payout       DECIMAL(18,2)   GENERATED ALWAYS AS (
                                 ROUND(total_kg * ffb_price_per_kg, 2)
                               - ROUND(total_kg * ffb_price_per_kg * deduction_pct / 100, 2)
                               - other_deduction
                             ) STORED,
            credit_before    DECIMAL(18,2)   NULL,
            credit_after     DECIMAL(18,2)   NULL,
            status           ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
            journal_posted   TINYINT(1)      NOT NULL DEFAULT 0,
            payment_date     DATE            NULL,
            payment_ref      VARCHAR(100)    NULL,
            notes            TEXT            NULL,
            created_by       VARCHAR(50)     NULL,
            updated_by       VARCHAR(50)     NULL,
            created_at       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_farmer (farmer_id),
            INDEX idx_period (period_start, period_end),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Add journal_posted if table existed without it (cloud upgrade path)
    $db->exec("ALTER TABLE plasma_payments ADD COLUMN IF NOT EXISTS journal_posted TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    // Ensure link tables also exist
    $db->exec("
        CREATE TABLE IF NOT EXISTS plasma_payment_journals (
            id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
            payment_id   INT UNSIGNED    NOT NULL,
            journal_id   BIGINT UNSIGNED NOT NULL,
            journal_type ENUM('plasma_ffb_purchase','plasma_loan_repayment','plasma_payment_transfer') NOT NULL,
            created_at   TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payment_type (payment_id, journal_type),
            INDEX idx_payment (payment_id),
            INDEX idx_journal (journal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
})($db);

// ─── Helper: next payment number ─────────────────────────────────────────────
function next_payment_no(PDO $db): string {
    $yr  = date('Y');
    $pfx = "PPY-$yr-";
    $max = (int) $db->query("SELECT MAX(CAST(SUBSTRING(payment_no, " . (strlen($pfx) + 1) . ") AS UNSIGNED))
                              FROM plasma_payments WHERE payment_no LIKE '$pfx%'")->fetchColumn();
    return $pfx . str_pad($max + 1, 5, '0', STR_PAD_LEFT);
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // ── Create draft payment ──────────────────────────────────────────────────
    if ($action === 'create') {
        try {
            $farmer_id = (int) post('farmer_id');
            $farmer    = $db->query("SELECT credit_remaining, deduction_pct FROM plasma_farmers WHERE id=$farmer_id")->fetch();

            $db->prepare("
                INSERT INTO plasma_payments
                    (payment_no, farmer_id, period_start, period_end,
                     total_kg, ffb_price_per_kg, deduction_pct, other_deduction,
                     credit_before, status, notes, created_by)
                VALUES (?,?,?,?, ?,?,?,?, ?,?, ?,?)
            ")->execute([
                next_payment_no($db),
                $farmer_id,
                post('period_start'),
                post('period_end'),
                (float) post('total_kg'),
                (float) post('ffb_price_per_kg'),
                (float) post('deduction_pct') ?: ($farmer['deduction_pct'] ?? 30),
                (float) post('other_deduction') ?: 0,
                $farmer['credit_remaining'] ?? 0,
                'draft',
                post('notes') ?: null,
                'admin',
            ]);
            set_message('success', 'Payment statement created as Draft.');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('plasma_payments.php');
    }

    // ── Update draft ──────────────────────────────────────────────────────────
    if ($action === 'update') {
        try {
            $pid = (int) post('payment_id');
            // Only allow edits on draft
            $current = $db->query("SELECT status FROM plasma_payments WHERE id=$pid")->fetchColumn();
            if ($current !== 'draft') throw new Exception('Only Draft payments can be edited.');
            $db->prepare("
                UPDATE plasma_payments SET
                    farmer_id=?, period_start=?, period_end=?,
                    total_kg=?, ffb_price_per_kg=?, deduction_pct=?, other_deduction=?,
                    notes=?, updated_by='admin'
                WHERE id=?
            ")->execute([
                (int)   post('farmer_id'),
                        post('period_start'),
                        post('period_end'),
                (float) post('total_kg'),
                (float) post('ffb_price_per_kg'),
                (float) post('deduction_pct'),
                (float) post('other_deduction'),
                        post('notes') ?: null,
                $pid,
            ]);
            set_message('success', 'Payment statement updated.');
        } catch (Exception $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('plasma_payments.php');
    }

    // ── Post (finalise) a payment — applies loan deduction to farmer master ───
    if ($action === 'post') {
        try {
            $pid       = (int) post('payment_id');
            $post_to_gl = (bool) post('post_to_gl');
            $db->beginTransaction();

            $pay = $db->query("
                SELECT p.*, pf.credit_remaining
                FROM plasma_payments p
                JOIN plasma_farmers pf ON p.farmer_id = pf.id
                WHERE p.id = $pid
            ")->fetch();

            if (!$pay)                         throw new Exception('Payment not found.');
            if ($pay['status'] !== 'draft')    throw new Exception('Only Draft payments can be posted.');

            $loan_deduction = round($pay['total_kg'] * $pay['ffb_price_per_kg'] * $pay['deduction_pct'] / 100, 2);
            $credit_after   = max(0, (float)$pay['credit_remaining'] - $loan_deduction);

            // Update payment record
            $db->prepare("
                UPDATE plasma_payments SET
                    status='posted',
                    payment_date=?,
                    payment_ref=?,
                    credit_before=?,
                    credit_after=?,
                    updated_by='admin'
                WHERE id=?
            ")->execute([
                post('payment_date') ?: date('Y-m-d'),
                post('payment_ref')  ?: null,
                $pay['credit_remaining'],
                $credit_after,
                $pid,
            ]);

            // Reduce farmer's outstanding credit
            $db->prepare("
                UPDATE plasma_farmers SET
                    credit_remaining = GREATEST(0, credit_remaining - ?),
                    status = CASE WHEN GREATEST(0, credit_remaining - ?) <= 0 AND status='active' THEN 'graduated' ELSE status END,
                    updated_by = 'admin'
                WHERE id = ?
            ")->execute([$loan_deduction, $loan_deduction, $pay['farmer_id']]);

            // Optionally auto-create journal entries
            if ($post_to_gl) {
                $username = $_SESSION['username'] ?? $_SESSION['name'] ?? 'admin';
                plasma_post_journals($db, $pid, $username);
            }

            $db->commit();
            $msg = 'Payment posted! Loan balance reduced by Rp ' . number_format($loan_deduction, 0) . '.';
            if ($post_to_gl) $msg .= ' Journal entries created in GL.';
            set_message('success', $msg);
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('plasma_payments.php');
    }

    // ── Post to GL (for already-posted payments not yet journalled) ───────────
    if ($action === 'post_journals') {
        try {
            $pid      = (int) post('payment_id');
            $username = $_SESSION['username'] ?? $_SESSION['name'] ?? 'admin';
            $db->beginTransaction();
            plasma_post_journals($db, $pid, $username);
            $db->commit();
            set_message('success', 'Journal entries created in GL successfully.');
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', 'GL posting error: ' . $e->getMessage());
        }
        redirect('plasma_payments.php');
    }

    // ── Cancel ────────────────────────────────────────────────────────────────
    if ($action === 'cancel') {
        try {
            $pid = (int) post('payment_id');
            $db->prepare("UPDATE plasma_payments SET status='cancelled', updated_by='admin' WHERE id=? AND status='draft'")
               ->execute([$pid]);
            set_message('success', 'Payment cancelled.');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('plasma_payments.php');
    }
}

require_once 'includes/header.php';

// ─── Filters ─────────────────────────────────────────────────────────────────
$farmer_id = (int) get('farmer_id', 0);
$status_f  = get('status', '');
$yr_f      = get('year',   date('Y'));
$search    = get('search', '');

// ─── Summary stats ────────────────────────────────────────────────────────────
$sw    = $farmer_id ? "AND p.farmer_id=$farmer_id" : '';
$stats = $db->query("
    SELECT COUNT(*)                                                          AS total,
           COALESCE(SUM(CASE WHEN p.status='posted' THEN p.gross_amount  ELSE 0 END),0) AS posted_gross,
           COALESCE(SUM(CASE WHEN p.status='posted' THEN p.loan_deduction ELSE 0 END),0) AS posted_deduction,
           COALESCE(SUM(CASE WHEN p.status='posted' THEN p.net_payout    ELSE 0 END),0) AS posted_net,
           SUM(CASE WHEN p.status='draft'     THEN 1 ELSE 0 END)           AS draft_count,
           SUM(CASE WHEN p.status='posted'    THEN 1 ELSE 0 END)           AS posted_count,
           SUM(CASE WHEN p.status='cancelled' THEN 1 ELSE 0 END)           AS cancelled_count
    FROM plasma_payments p WHERE 1=1 $sw
")->fetch();

// ─── Dropdowns ───────────────────────────────────────────────────────────────
$farmers = $db->query("
    SELECT pf.id, pf.farmer_code, pf.farmer_name, pf.deduction_pct,
           pf.credit_remaining, bu.unit_name AS estate_name
    FROM plasma_farmers pf
    LEFT JOIN business_units bu ON pf.business_unit_id = bu.business_unit_id
    WHERE pf.status IN ('active','graduated')
    ORDER BY pf.farmer_code
")->fetchAll();

// ─── Main list ────────────────────────────────────────────────────────────────
$where  = ['YEAR(p.period_start) = ?'];
$params = [(int)$yr_f];
if ($farmer_id) { $where[] = 'p.farmer_id=?';  $params[] = $farmer_id; }
if ($status_f)  { $where[] = 'p.status=?';     $params[] = $status_f; }
if ($search)    { $where[] = '(pf.farmer_name LIKE ? OR p.payment_no LIKE ?)';
                  $params  = array_merge($params, ["%$search%","%$search%"]); }

$stmt = $db->prepare("
    SELECT p.*,
           pf.farmer_code, pf.farmer_name, pf.kud_name, pf.credit_remaining AS current_credit,
           bu.unit_name AS estate_name
    FROM plasma_payments p
    JOIN  plasma_farmers  pf ON p.farmer_id          = pf.id
    LEFT JOIN business_units bu ON pf.business_unit_id = bu.business_unit_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.period_start DESC, p.id DESC
");
$stmt->execute($params);
$payments = $stmt->fetchAll();

$farmer_opts = '';
foreach ($farmers as $f)
    $farmer_opts .= "<option value='{$f['id']}' data-deduction='{$f['deduction_pct']}' data-credit='{$f['credit_remaining']}'>"
                 . "[{$f['farmer_code']}] " . htmlspecialchars($f['farmer_name'])
                 . ($f['estate_name'] ? ' — ' . htmlspecialchars($f['estate_name']) : '')
                 . "</option>";

$years_range = range(date('Y'), 2020);
?>

<div class="content-wrapper">
    <!-- Page header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-receipt"></i> Plasma Payment Statements</h1>
                <p class="text-muted mb-0">FFB price × tonnage − loan deduction = net payout to farmer · auto-reduces outstanding credit</p>
            </div>
            <div class="d-flex gap-2">
                <a href="plasma_ffb_deliveries.php" class="btn btn-outline-secondary">
                    <i class="bi bi-truck"></i> FFB Deliveries
                </a>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                    <i class="bi bi-plus-circle"></i> New Statement
                </button>
            </div>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card stat-card text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Total Statements</h6>
                    <h4 class="mb-0 text-primary"><?= $stats['total'] ?></h4>
                    <small class="text-muted"><?= $stats['draft_count'] ?> draft</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-info border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Gross FFB Revenue</h6>
                    <h5 class="mb-0" style="color:#0891b2">Rp <?= number_format($stats['posted_gross']/1e6,2) ?>M</h5>
                    <small class="text-muted">posted only</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-warning border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Loan Deductions</h6>
                    <h5 class="mb-0 text-warning">Rp <?= number_format($stats['posted_deduction']/1e6,2) ?>M</h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-success border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Net Payout to Farmers</h6>
                    <h5 class="mb-0 text-success">Rp <?= number_format($stats['posted_net']/1e6,2) ?>M</h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-primary border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Posted</h6>
                    <h5 class="mb-0 text-primary"><?= $stats['posted_count'] ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-secondary border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Cancelled</h6>
                    <h5 class="mb-0 text-secondary"><?= $stats['cancelled_count'] ?></h5>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <select name="year" class="form-select form-select-sm">
                        <?php foreach ($years_range as $yr): ?>
                            <option value="<?= $yr ?>" <?= $yr_f==$yr?'selected':'' ?>><?= $yr ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="farmer_id" class="form-select form-select-sm">
                        <option value="">All Farmers</option>
                        <?php foreach ($farmers as $f): ?>
                            <option value="<?= $f['id'] ?>" <?= $farmer_id==$f['id']?'selected':'' ?>>
                                [<?= htmlspecialchars($f['farmer_code']) ?>] <?= htmlspecialchars($f['farmer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="draft"     <?= $status_f==='draft'     ?'selected':'' ?>>Draft</option>
                        <option value="posted"    <?= $status_f==='posted'    ?'selected':'' ?>>Posted</option>
                        <option value="cancelled" <?= $status_f==='cancelled' ?'selected':'' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" class="form-control" placeholder="Name / payment no…" value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                        <a href="plasma_payments.php" class="btn btn-outline-secondary">✕</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-header"><i class="bi bi-table"></i> Payment Statements (<?= count($payments) ?>) — <?= $yr_f ?></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Payment No</th>
                            <th>Farmer</th>
                            <th>Period</th>
                            <th class="text-end">FFB (kg)</th>
                            <th class="text-end">Price/kg</th>
                            <th class="text-end">Gross (IDR)</th>
                            <th class="text-end">Loan Deduct.</th>
                            <th class="text-end">Other Deduct.</th>
                            <th class="text-end">Net Payout</th>
                            <th>Credit Before → After</th>
                            <th>Status</th>
                            <th>GL</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr><td colspan="12" class="text-center text-muted py-4">No payment statements for <?= $yr_f ?>.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payments as $p):
                                $stColor = ['draft'=>'warning','posted'=>'success','cancelled'=>'secondary'][$p['status']] ?? 'secondary';
                                $gross   = (float)$p['gross_amount'];
                                $deduct  = (float)$p['loan_deduction'];
                                $net     = (float)$p['net_payout'];
                            ?>
                            <tr>
                                <td><code><?= htmlspecialchars($p['payment_no']) ?></code></td>
                                <td>
                                    <strong><?= htmlspecialchars($p['farmer_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($p['farmer_code']) ?></small>
                                </td>
                                <td>
                                    <small><?= $p['period_start'] ?></small><br>
                                    <small class="text-muted">→ <?= $p['period_end'] ?></small>
                                </td>
                                <td class="text-end"><?= number_format($p['total_kg'], 2) ?></td>
                                <td class="text-end">Rp <?= number_format($p['ffb_price_per_kg'], 0) ?></td>
                                <td class="text-end">Rp <?= number_format($gross, 0) ?></td>
                                <td class="text-end text-warning">
                                    (Rp <?= number_format($deduct, 0) ?>)<br>
                                    <small class="text-muted"><?= $p['deduction_pct'] ?>%</small>
                                </td>
                                <td class="text-end text-muted">
                                    <?= $p['other_deduction'] > 0 ? '(Rp '.number_format($p['other_deduction'],0).')' : '—' ?>
                                </td>
                                <td class="text-end fw-bold text-success">Rp <?= number_format($net, 0) ?></td>
                                <td>
                                    <?php if ($p['status'] === 'posted' && $p['credit_before'] !== null): ?>
                                        <small class="text-muted">Rp <?= number_format($p['credit_before'], 0) ?></small><br>
                                        <small class="text-success">→ Rp <?= number_format($p['credit_after'], 0) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">—</small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-<?= $stColor ?>"><?= ucfirst($p['status']) ?></span></td>
                                <!-- GL posted indicator -->
                                <td class="text-center">
                                    <?php if ($p['status'] === 'posted'): ?>
                                        <?php if (!empty($p['journal_posted'])): ?>
                                            <span class="badge bg-success" title="Journal entries posted to GL">
                                                <i class="bi bi-journal-check"></i> GL
                                            </span>
                                        <?php else: ?>
                                            <form method="POST" class="d-inline"
                                                  onsubmit="return confirm('Post journal entries for <?= htmlspecialchars($p['payment_no'], ENT_QUOTES) ?> to the General Ledger?')">
                                                <input type="hidden" name="action"     value="post_journals">
                                                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary"
                                                        title="Post to General Ledger">
                                                    <i class="bi bi-journal-arrow-up"></i> GL
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($p['status'] === 'draft'): ?>
                                        <!-- Edit -->
                                        <button class="btn btn-sm btn-outline-primary me-1"
                                                data-bs-toggle="modal" data-bs-target="#editModal"
                                                data-payment='<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <!-- Post -->
                                        <button class="btn btn-sm btn-success me-1"
                                                data-bs-toggle="modal" data-bs-target="#postModal"
                                                data-pid="<?= $p['id'] ?>"
                                                data-pno="<?= htmlspecialchars($p['payment_no']) ?>"
                                                data-net="<?= number_format($net, 0) ?>"
                                                data-deduct="<?= number_format($deduct, 0) ?>">
                                            <i class="bi bi-check2-circle"></i> Post
                                        </button>
                                        <!-- Cancel -->
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Cancel this payment statement?')">
                                            <input type="hidden" name="action"     value="cancel">
                                            <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($p['status'] === 'posted'): ?>
                                        <span class="text-muted small">Posted <?= $p['payment_date'] ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($payments)):
                        $totGross  = array_sum(array_column($payments, 'gross_amount'));
                        $totDeduct = array_sum(array_column($payments, 'loan_deduction'));
                        $totOther  = array_sum(array_column($payments, 'other_deduction'));
                        $totNet    = array_sum(array_column($payments, 'net_payout'));
                    ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="5" class="text-end">Totals:</td>
                            <td class="text-end">Rp <?= number_format($totGross, 0) ?></td>
                            <td class="text-end text-warning">(Rp <?= number_format($totDeduct, 0) ?>)</td>
                            <td class="text-end text-muted"><?= $totOther > 0 ? '(Rp '.number_format($totOther,0).')' : '—' ?></td>
                            <td class="text-end text-success">Rp <?= number_format($totNet, 0) ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-plus-circle"></i> New Payment Statement</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-12">
              <label class="form-label">Farmer <span class="text-danger">*</span></label>
              <select name="farmer_id" id="c_farmer_id" class="form-select" required onchange="fillDeduction('c')">
                <option value="">— Select Farmer —</option>
                <?= $farmer_opts ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Period Start <span class="text-danger">*</span></label>
              <input type="date" name="period_start" id="c_period_start" class="form-control" required value="<?= date('Y-m-01') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Period End <span class="text-danger">*</span></label>
              <input type="date" name="period_end" id="c_period_end" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Total FFB (kg) <span class="text-danger">*</span></label>
              <input type="number" name="total_kg" id="c_total_kg" class="form-control" step="0.01" min="0" required oninput="calcPreview('c')">
            </div>
            <div class="col-md-4">
              <label class="form-label">FFB Price per kg (IDR) <span class="text-danger">*</span></label>
              <input type="number" name="ffb_price_per_kg" id="c_ffb_price_per_kg" class="form-control" step="0.01" min="0" required oninput="calcPreview('c')">
            </div>
            <div class="col-md-4">
              <label class="form-label">Loan Deduction %</label>
              <input type="number" name="deduction_pct" id="c_deduction_pct" class="form-control" step="0.01" min="0" max="100" value="30" oninput="calcPreview('c')">
            </div>
            <div class="col-md-4">
              <label class="form-label">Other Deductions (IDR)</label>
              <input type="number" name="other_deduction" id="c_other_deduction" class="form-control" step="1" min="0" value="0" oninput="calcPreview('c')">
            </div>
            <!-- Live preview -->
            <div class="col-md-8">
              <div class="alert alert-info mb-0" id="c_preview" style="display:none">
                <div class="row text-center">
                  <div class="col-4"><div class="small text-muted">Gross</div><div id="c_prev_gross" class="fw-bold"></div></div>
                  <div class="col-4"><div class="small text-muted">Loan Deduction</div><div id="c_prev_deduct" class="fw-bold text-warning"></div></div>
                  <div class="col-4"><div class="small text-muted">Net Payout</div><div id="c_prev_net" class="fw-bold text-success"></div></div>
                </div>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" id="c_notes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save as Draft</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="payment_id" id="e_payment_id">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Statement — <span id="e_payment_no_label"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-12">
              <label class="form-label">Farmer <span class="text-danger">*</span></label>
              <select name="farmer_id" id="e_farmer_id" class="form-select" required>
                <option value="">— Select Farmer —</option>
                <?= $farmer_opts ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Period Start</label>
              <input type="date" name="period_start" id="e_period_start" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Period End</label>
              <input type="date" name="period_end" id="e_period_end" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Total FFB (kg)</label>
              <input type="number" name="total_kg" id="e_total_kg" class="form-control" step="0.01" min="0" required oninput="calcPreview('e')">
            </div>
            <div class="col-md-4">
              <label class="form-label">FFB Price per kg (IDR)</label>
              <input type="number" name="ffb_price_per_kg" id="e_ffb_price_per_kg" class="form-control" step="0.01" min="0" required oninput="calcPreview('e')">
            </div>
            <div class="col-md-4">
              <label class="form-label">Loan Deduction %</label>
              <input type="number" name="deduction_pct" id="e_deduction_pct" class="form-control" step="0.01" min="0" max="100" oninput="calcPreview('e')">
            </div>
            <div class="col-md-4">
              <label class="form-label">Other Deductions (IDR)</label>
              <input type="number" name="other_deduction" id="e_other_deduction" class="form-control" step="1" min="0" oninput="calcPreview('e')">
            </div>
            <div class="col-md-8">
              <div class="alert alert-info mb-0" id="e_preview" style="display:none">
                <div class="row text-center">
                  <div class="col-4"><div class="small text-muted">Gross</div><div id="e_prev_gross" class="fw-bold"></div></div>
                  <div class="col-4"><div class="small text-muted">Loan Deduction</div><div id="e_prev_deduct" class="fw-bold text-warning"></div></div>
                  <div class="col-4"><div class="small text-muted">Net Payout</div><div id="e_prev_net" class="fw-bold text-success"></div></div>
                </div>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" id="e_notes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Post Confirmation Modal -->
<div class="modal fade" id="postModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action"     value="post">
        <input type="hidden" name="payment_id" id="post_pid">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="bi bi-check2-circle"></i> Post Payment — <span id="post_pno"></span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning">
            <strong>This action cannot be undone.</strong> Posting will:
            <ul class="mb-0 mt-1">
              <li>Lock this statement as <em>Posted</em></li>
              <li>Reduce the farmer's outstanding credit by <strong id="post_deduct"></strong></li>
              <li>Automatically graduate the farmer if balance reaches zero</li>
            </ul>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Payment Date</label>
              <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Bank Transfer Reference</label>
              <input type="text" name="payment_ref" class="form-control" placeholder="e.g. BNI-TRF-12345">
            </div>
          </div>
          <div class="mt-3 text-center">
            <span class="text-muted small">Net payout to farmer: </span>
            <strong class="text-success" id="post_net"></strong>
          </div>
          <!-- GL option -->
          <div class="mt-3 p-3 border rounded bg-light">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="post_to_gl" value="1" id="chk_post_to_gl" checked>
              <label class="form-check-label fw-semibold" for="chk_post_to_gl">
                <i class="bi bi-journal-arrow-up text-primary"></i> Also post to General Ledger
              </label>
            </div>
            <div class="text-muted small mt-1" style="padding-left:2.25rem">
              Creates 3 double-entry journal entries automatically:
              <br>① FFB purchase recognition &nbsp;&nbsp;
              ② Loan repayment deduction &nbsp;&nbsp;
              ③ Net payout bank transfer
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle"></i> Confirm &amp; Post</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
function fmt(n) {
    return 'Rp ' + Math.round(n).toLocaleString('id-ID');
}

function calcPreview(pfx) {
    var kg    = parseFloat(document.getElementById(pfx + '_total_kg').value) || 0;
    var price = parseFloat(document.getElementById(pfx + '_ffb_price_per_kg').value) || 0;
    var pct   = parseFloat(document.getElementById(pfx + '_deduction_pct').value) || 0;
    var other = parseFloat(document.getElementById(pfx + '_other_deduction').value) || 0;
    var gross  = kg * price;
    var deduct = Math.round(gross * pct / 100);
    var net    = Math.round(gross) - deduct - other;
    var panel  = document.getElementById(pfx + '_preview');
    if (kg > 0 && price > 0) {
        document.getElementById(pfx + '_prev_gross').textContent  = fmt(gross);
        document.getElementById(pfx + '_prev_deduct').textContent = fmt(deduct);
        document.getElementById(pfx + '_prev_net').textContent    = fmt(net);
        panel.style.display = '';
    } else {
        panel.style.display = 'none';
    }
}

function fillDeduction(pfx) {
    var sel = document.getElementById(pfx + '_farmer_id');
    var opt = sel.options[sel.selectedIndex];
    var pct = opt.dataset.deduction || 30;
    var el  = document.getElementById(pfx + '_deduction_pct');
    if (el) { el.value = pct; calcPreview(pfx); }
}

// Edit modal populate
document.getElementById('editModal').addEventListener('show.bs.modal', function(e) {
    var p = JSON.parse(e.relatedTarget.dataset.payment);
    document.getElementById('e_payment_id').value = p.id;
    document.getElementById('e_payment_no_label').textContent = p.payment_no;
    var fields = ['period_start','period_end','total_kg','ffb_price_per_kg','deduction_pct','other_deduction','notes'];
    fields.forEach(function(fld) {
        var el = document.getElementById('e_' + fld);
        if (!el) return;
        if (el._flatpickr) {
            el._flatpickr.setDate(p[fld] || '', false, 'Y-m-d');
        } else {
            el.value = p[fld] || '';
        }
    });
    var sel = document.getElementById('e_farmer_id');
    for (var i = 0; i < sel.options.length; i++)
        if (String(sel.options[i].value) === String(p.farmer_id)) { sel.selectedIndex = i; break; }
    calcPreview('e');
});

// Post modal populate
document.getElementById('postModal').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('post_pid').value = btn.dataset.pid;
    document.getElementById('post_pno').textContent  = btn.dataset.pno;
    document.getElementById('post_net').textContent  = 'Rp ' + btn.dataset.net;
    document.getElementById('post_deduct').textContent = 'Rp ' + btn.dataset.deduct;
});
</script>
JS;
require_once 'includes/footer.php';
?>
