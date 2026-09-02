<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_delivery_complaints');

// ─── Credit-note GL journal ───────────────────────────────────────────────────
function create_credit_note_journal(PDO $db, array $cmp, string $cn_num): ?int {
    // Debit: Sales Revenue contra (4111/4112/4113 per product); Credit: AR
    $rev_map  = ['CPO'=>'4111','Kernel'=>'4112','FFB'=>'4113','PKO'=>'4111','Other'=>'4110'];
    $ar_map   = ['CPO'=>'1121','Kernel'=>'1122','FFB'=>'1123','PKO'=>'1121','Other'=>'1120'];

    $prod = $cmp['product_type'] ?? 'Other';
    $rev_code = $rev_map[$prod] ?? '4110';
    $ar_code  = $ar_map[$prod]  ?? '1120';

    $rev_id = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='$rev_code' LIMIT 1")->fetchColumn();
    $ar_id  = (int)$db->query("SELECT id FROM general_ledger_accounts WHERE account_code='$ar_code'  LIMIT 1")->fetchColumn();
    if (!$rev_id || !$ar_id) return null;   // GL accounts not found — skip silently

    $ym     = date('Ym', strtotime($cmp['resolved_at'] ?? date('Y-m-d')));
    $prefix = 'JE-CN-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reference_number,'-',-1) AS UNSIGNED)),0)+1
        FROM journal_entries WHERE reference_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    $ref = $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);

    $amount = (float)$cmp['agreed_deduction'];

    $db->prepare("
        INSERT INTO journal_entries
            (entry_date, entry_type, reference_number, description,
             company_id, total_debit, total_credit,
             currency_code, status, posted_date, posted_by, created_by)
        VALUES (?, 'sales_invoice', ?, ?, ?, ?, ?, 'IDR', 'posted', NOW(), 1, 'admin')
    ")->execute([
        $cmp['resolved_at'] ?? date('Y-m-d'),
        $ref,
        'Credit note — ' . $cn_num . ' / complaint ' . $cmp['complaint_number'],
        $cmp['company_id'],
        $amount, $amount,
    ]);
    $je_id = (int)$db->lastInsertId();

    // Dr Revenue (reduces revenue = contra)
    $db->prepare("
        INSERT INTO journal_entry_lines
            (journal_entry_id, line_number, gl_account_id, debit_amount, credit_amount, description)
        VALUES (?,1,?,?,0,?)
    ")->execute([$je_id, $rev_id, $amount, 'Deduction — ' . $cmp['complaint_number']]);

    // Cr AR (reduces receivable)
    $db->prepare("
        INSERT INTO journal_entry_lines
            (journal_entry_id, line_number, gl_account_id, debit_amount, credit_amount, description)
        VALUES (?,2,?,0,?,?)
    ")->execute([$je_id, $ar_id, $amount, 'Credit note issued — ' . $cn_num]);

    return $je_id;
}

// ─── Credit-note number generator ────────────────────────────────────────────
function gen_cn_number(PDO $db): string {
    $ym     = date('Ym');
    $prefix = 'CN-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(credit_note_number,'-',-1) AS UNSIGNED)),0)+1
        FROM delivery_complaints WHERE credit_note_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── Auto-number ──────────────────────────────────────────────────────────────
function gen_cmp_number(PDO $db): string {
    $ym     = date('Ym');
    $prefix = 'CMP-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(complaint_number,'-',-1) AS UNSIGNED)),0)+1
        FROM delivery_complaints WHERE complaint_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // ── Create complaint ─────────────────────────────────────────────────────
    if ($action === 'create_complaint') {
        $rec_id = (int)post('receiving_id');
        try {
            $db->beginTransaction();

            // Fetch receiving → delivery
            $stmt = $db->prepare("
                SELECT gr.*, pd.delivery_id, pd.company_id, pd.customer_id
                FROM delivery_receivings gr
                JOIN product_deliveries pd ON gr.delivery_id = pd.delivery_id
                WHERE gr.receiving_id = ?
            ");
            $stmt->execute([$rec_id]);
            $gr = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$gr) throw new Exception('Receiving record not found.');

            $cmp_num = gen_cmp_number($db);

            $db->prepare("
                INSERT INTO delivery_complaints
                    (complaint_number, complaint_date, receiving_id, delivery_id,
                     company_id, customer_id,
                     complaint_type, subject, description,
                     claimed_deduction, currency,
                     status, created_by)
                VALUES (?,?,?,?, ?,?, ?,?,?, ?,?, 'open','admin')
            ")->execute([
                $cmp_num,
                post('complaint_date') ?: date('Y-m-d'),
                $rec_id, (int)$gr['delivery_id'],
                (int)$gr['company_id'], (int)$gr['customer_id'],
                post('complaint_type'),
                post('subject'),
                post('description'),
                (float)post('claimed_deduction'),
                'IDR',
            ]);
            $cmp_id = (int)$db->lastInsertId();

            // Complaint items
            $item_types  = $_POST['item_type']        ?? [];
            $item_params = $_POST['item_param_name']  ?? [];
            $item_descs  = $_POST['item_description'] ?? [];
            $item_contr  = $_POST['item_contract_val']?? [];
            $item_actual = $_POST['item_actual_val']  ?? [];
            $item_units  = $_POST['item_unit']        ?? [];
            $item_qty    = $_POST['item_qty_affected'] ?? [];
            $item_claim  = $_POST['item_claimed_amt'] ?? [];

            foreach ($item_types as $i => $itype) {
                if (empty($item_descs[$i])) continue;
                $db->prepare("
                    INSERT INTO complaint_items
                        (complaint_id, item_type, param_name, description,
                         contract_value, actual_value, unit,
                         quantity_affected_kg, claimed_amount)
                    VALUES (?,?,?,?, ?,?,?, ?,?)
                ")->execute([
                    $cmp_id,
                    $itype,
                    $item_params[$i] ?? null,
                    $item_descs[$i],
                    $item_contr[$i]  !== '' ? (float)$item_contr[$i]  : null,
                    $item_actual[$i] !== '' ? (float)$item_actual[$i] : null,
                    $item_units[$i]  ?? null,
                    $item_qty[$i]    !== '' ? (float)$item_qty[$i]    : null,
                    (float)($item_claim[$i] ?? 0),
                ]);
            }

            // Mark receiving as disputed if not already
            $db->prepare("
                UPDATE delivery_receivings SET status='disputed', updated_by='admin'
                WHERE receiving_id=? AND status NOT IN ('approved')
            ")->execute([$rec_id]);

            $db->commit();
            set_message('success', "Complaint <b>$cmp_num</b> raised.");
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('delivery_complaints.php');
    }

    // ── Resolve complaint ────────────────────────────────────────────────────
    if ($action === 'resolve_complaint') {
        $cmp_id      = (int)post('complaint_id');
        $agreed_ded  = (float)post('agreed_deduction');
        $resolution  = post('resolution');
        try {
            $db->beginTransaction();

            // Fetch complaint for journal context
            $stmt = $db->prepare("
                SELECT cmp.*, pd.product_type
                FROM delivery_complaints cmp
                JOIN product_deliveries pd ON cmp.delivery_id = pd.delivery_id
                WHERE cmp.complaint_id = ?
            ");
            $stmt->execute([$cmp_id]);
            $cmp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cmp) throw new Exception('Complaint not found.');

            $cn_num = null;
            $je_id  = null;

            if ($agreed_ded > 0) {
                $cn_num = gen_cn_number($db);
                $cmp['agreed_deduction'] = $agreed_ded;
                $cmp['resolved_at']      = date('Y-m-d');
                $je_id = create_credit_note_journal($db, $cmp, $cn_num);
            }

            $db->prepare("
                UPDATE delivery_complaints SET
                    status='resolved',
                    resolution=?,
                    agreed_deduction=?,
                    resolved_by='admin',
                    resolved_at=CURDATE(),
                    credit_note_number=?,
                    journal_entry_id=?,
                    updated_by='admin'
                WHERE complaint_id=?
            ")->execute([
                $resolution,
                $agreed_ded,
                $cn_num,
                $je_id,
                $cmp_id,
            ]);

            $db->commit();
            $msg = 'Complaint resolved.';
            if ($cn_num) $msg .= " Credit note <b>$cn_num</b> issued.";
            if ($je_id)  $msg .= " Journal entry posted.";
            set_message('success', $msg);
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('delivery_complaints.php');
    }

    // ── Close / reject complaint ─────────────────────────────────────────────
    if ($action === 'update_status') {
        try {
            $db->prepare("
                UPDATE delivery_complaints SET status=?, updated_by='admin' WHERE complaint_id=?
            ")->execute([post('new_status'), (int)post('complaint_id')]);
            set_message('success', 'Status updated.');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('delivery_complaints.php');
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$year            = get('year', date('Y'));
$status_filter   = get('status', '');
$type_filter     = get('complaint_type', '');
$search          = get('search', '');
$receiving_id_f  = (int)get('receiving_id', 0);

// ─── Reference: receivings for the create-complaint dropdown ─────────────────
$receivings_ref = $db->query("
    SELECT gr.receiving_id, gr.receiving_number, gr.receiving_date,
           pd.delivery_number, pd.product_type,
           cu.customer_name
    FROM delivery_receivings gr
    JOIN product_deliveries pd ON gr.delivery_id = pd.delivery_id
    JOIN customers cu           ON gr.customer_id = cu.customer_id
    ORDER BY gr.receiving_date DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Complaints list ──────────────────────────────────────────────────────────
try {
    $sql = "
        SELECT cmp.*,
               cu.customer_name, c.company_name,
               pd.delivery_number, pd.product_type,
               gr.receiving_number,
               gr.quality_status   AS gr_quality_status,
               gr.quantity_status  AS gr_qty_status,
               COUNT(ci.item_id)   AS item_count,
               DATEDIFF(CURDATE(), cmp.complaint_date) AS days_open
        FROM delivery_complaints cmp
        JOIN delivery_receivings gr ON cmp.receiving_id  = gr.receiving_id
        JOIN product_deliveries  pd ON cmp.delivery_id   = pd.delivery_id
        JOIN customers           cu ON cmp.customer_id   = cu.customer_id
        JOIN companies            c ON cmp.company_id    = c.company_id
        LEFT JOIN complaint_items ci ON ci.complaint_id  = cmp.complaint_id
        WHERE YEAR(cmp.complaint_date) = ?";
    $p2 = [$year];
    if ($status_filter)    { $sql .= " AND cmp.status=?";         $p2[] = $status_filter; }
    if ($type_filter)      { $sql .= " AND cmp.complaint_type=?"; $p2[] = $type_filter; }
    if ($receiving_id_f)   { $sql .= " AND cmp.receiving_id=?";   $p2[] = $receiving_id_f; }
    if ($search) {
        $sql .= " AND (cmp.complaint_number LIKE ? OR cu.customer_name LIKE ? OR pd.delivery_number LIKE ?)";
        $t = "%$search%"; $p2[]=$t; $p2[]=$t; $p2[]=$t;
    }
    $sql .= " GROUP BY cmp.complaint_id ORDER BY cmp.complaint_date DESC, cmp.complaint_id DESC";
    $stmt = $db->prepare($sql); $stmt->execute($p2);
    $complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $complaints = []; }

$status_colours = [
    'open'         => 'danger',
    'under_review' => 'warning',
    'resolved'     => 'success',
    'rejected'     => 'secondary',
    'closed'       => 'dark',
];
$type_colours = [
    'quantity'          => 'info',
    'quality'           => 'warning',
    'quantity_quality'  => 'danger',
    'packaging'         => 'primary',
    'other'             => 'secondary',
];
$product_colours = ['FFB'=>'success','CPO'=>'warning','Kernel'=>'info','PKO'=>'primary','Other'=>'secondary'];

require_once 'includes/header.php';
?>

<style>
    .cmp-red  { color: #dc2626 !important; }
    .bg-cmp   { background-color: #dc2626 !important; }
    .btn-cmp  { background-color: #dc2626; color:#fff; border:none; }
    .btn-cmp:hover { background-color: #b91c1c; color:#fff; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="cmp-red"><i class="bi bi-exclamation-triangle-fill"></i> Delivery Complaints</h1>
            <p class="text-muted mb-0">
                <a href="product_deliveries.php"  class="text-decoration-none">Deliveries</a>
                &rsaquo; <a href="delivery_receiving.php" class="text-decoration-none">Receiving</a>
                &rsaquo; <b>Complaints</b>
                &rsaquo; <a href="delivery_monitoring.php" class="text-decoration-none">Monitoring</a>
            </p>
        </div>
        <div class="col-auto">
            <button class="btn btn-cmp" data-bs-toggle="modal" data-bs-target="#createCmpModal">
                <i class="bi bi-plus-circle"></i> New Complaint
            </button>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-cmp text-white py-2"><i class="bi bi-funnel"></i> Filter Complaints</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="CMP # / delivery / customer…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y=date('Y')-2;$y<=date('Y')+1;$y++): ?>
                        <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <?php foreach (array_keys($status_colours) as $s): ?>
                        <option value="<?=$s?>" <?=$s===$status_filter?'selected':''?>><?=ucfirst(str_replace('_',' ',$s))?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="complaint_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach (array_keys($type_colours) as $t): ?>
                        <option value="<?=$t?>" <?=$t===$type_filter?'selected':''?>><?=ucwords(str_replace('_',' ',$t))?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-cmp btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="delivery_complaints.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── KPI ────────────────────────────────────────────────────────────────── -->
<?php
$open_cnt     = count(array_filter($complaints, fn($r)=>$r['status']==='open'));
$review_cnt   = count(array_filter($complaints, fn($r)=>$r['status']==='under_review'));
$resolved_cnt = count(array_filter($complaints, fn($r)=>$r['status']==='resolved'));
$total_claimed = array_sum(array_column($complaints,'claimed_deduction'));
$total_agreed  = array_sum(array_column($complaints,'agreed_deduction'));
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-2"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Total</div>
        <div class="fw-bold fs-3"><?= count($complaints) ?></div>
    </div></div></div>
    <div class="col-6 col-md-2"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Open</div>
        <div class="fw-bold fs-3 text-danger"><?= $open_cnt ?></div>
    </div></div></div>
    <div class="col-6 col-md-2"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Under Review</div>
        <div class="fw-bold fs-3 text-warning"><?= $review_cnt ?></div>
    </div></div></div>
    <div class="col-6 col-md-2"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Resolved</div>
        <div class="fw-bold fs-3 text-success"><?= $resolved_cnt ?></div>
    </div></div></div>
    <div class="col-6 col-md-2"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Claimed (Rp)</div>
        <div class="fw-bold" style="font-size:1rem"><?= number_format($total_claimed/1000000,1) ?>M</div>
    </div></div></div>
    <div class="col-6 col-md-2"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Agreed Deduction</div>
        <div class="fw-bold text-danger" style="font-size:1rem"><?= number_format($total_agreed/1000000,1) ?>M</div>
    </div></div></div>
</div>

<!-- ── Complaints Table ────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header bg-cmp text-white py-2">
        <i class="bi bi-table"></i> <?= count($complaints) ?> Complaint(s)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>CMP #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Delivery</th>
                        <th>Type</th>
                        <th>Subject</th>
                        <th class="text-end">Claimed (Rp)</th>
                        <th class="text-end">Agreed (Rp)</th>
                        <th class="text-center">Days Open</th>
                        <th>Status</th>
                        <th>Actions</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($complaints)): ?>
                        <tr><td colspan="12" class="text-center text-muted py-4">No complaints found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($complaints as $r): ?>
                            <?php $row_cls = in_array($r['status'],['open','under_review']) ? 'table-danger' : ''; ?>
                            <tr class="<?= $row_cls ?>">
                                <td class="fw-bold"><?= htmlspecialchars($r['complaint_number']) ?></td>
                                <td><?= date('d/m/Y', strtotime($r['complaint_date'])) ?></td>
                                <td><?= htmlspecialchars($r['customer_name']) ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($r['company_name']) ?></small></td>
                                <td>
                                    <small><?= htmlspecialchars($r['delivery_number']) ?></small><br>
                                    <span class="badge bg-<?= $product_colours[$r['product_type']]??'secondary' ?>"><?= $r['product_type'] ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $type_colours[$r['complaint_type']]??'secondary' ?>">
                                        <?= ucwords(str_replace('_',' ',$r['complaint_type'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= htmlspecialchars($r['subject']) ?>
                                    <?php if ((int)$r['item_count'] > 0): ?>
                                        <br><small class="text-muted"><?= $r['item_count'] ?> item(s)</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-danger"><?= number_format($r['claimed_deduction'],0) ?></td>
                                <td class="text-end <?= $r['agreed_deduction']>0?'text-warning':'' ?>">
                                    <?= $r['agreed_deduction']>0 ? number_format($r['agreed_deduction'],0) : '—' ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!in_array($r['status'],['resolved','closed','rejected'])): ?>
                                        <span class="badge <?= (int)$r['days_open']>14?'bg-danger':'bg-warning text-dark' ?>">
                                            <?= $r['days_open'] ?>d
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-<?= $status_colours[$r['status']]??'secondary' ?>">
                                    <?= ucwords(str_replace('_',' ',$r['status'])) ?>
                                </span></td>
                                <td>
                                    <?php if (in_array($r['status'],['open','under_review'])): ?>
                                        <!-- Mark under review -->
                                        <?php if ($r['status']==='open'): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="complaint_id" value="<?=$r['complaint_id']?>">
                                                <input type="hidden" name="new_status" value="under_review">
                                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Mark Under Review">
                                                    <i class="bi bi-search"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <!-- Resolve -->
                                        <button class="btn btn-sm btn-outline-success"
                                                onclick="openResolveModal(<?=$r['complaint_id']?>,<?=$r['claimed_deduction']?>)"
                                                title="Resolve Complaint">
                                            <i class="bi bi-check-circle"></i>
                                        </button>
                                        <!-- Reject -->
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Reject this complaint?')">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="complaint_id" value="<?=$r['complaint_id']?>">
                                            <input type="hidden" name="new_status" value="rejected">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Reject">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($r['status']==='resolved'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="complaint_id" value="<?=$r['complaint_id']?>">
                                            <input type="hidden" name="new_status" value="closed">
                                            <button type="submit" class="btn btn-sm btn-outline-dark" title="Close">
                                                <i class="bi bi-archive"></i> Close
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="openDetailModal(<?= $r['complaint_id'] ?>)"
                                            title="View Detail">
                                        <i class="bi bi-eye"></i>
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

<!-- ── Create Complaint Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="createCmpModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form method="POST" id="cmpForm">
            <input type="hidden" name="action" value="create_complaint">
            <div class="modal-content">
                <div class="modal-header bg-cmp text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Raise New Complaint</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label">Receiving Record *</label>
                            <select name="receiving_id" id="cmpReceivingId" class="form-select form-select-sm" required>
                                <option value="">— Select Receiving —</option>
                                <?php foreach ($receivings_ref as $gr): ?>
                                    <option value="<?= $gr['receiving_id'] ?>"
                                            <?= $receiving_id_f==$gr['receiving_id']?'selected':'' ?>>
                                        <?= htmlspecialchars($gr['receiving_number']) ?>
                                        — <?= htmlspecialchars($gr['delivery_number']) ?>
                                        (<?= htmlspecialchars($gr['customer_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Complaint Date *</label>
                            <input type="date" name="complaint_date" class="form-control form-control-sm"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Complaint Type *</label>
                            <select name="complaint_type" class="form-select form-select-sm" required>
                                <option value="quantity">Quantity (Short / Excess)</option>
                                <option value="quality">Quality (Parameter Failure)</option>
                                <option value="quantity_quality">Quantity &amp; Quality</option>
                                <option value="packaging">Packaging / Contamination</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Subject *</label>
                            <input type="text" name="subject" class="form-control form-control-sm" required
                                   placeholder="Brief summary of the complaint">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Detailed Description</label>
                            <textarea name="description" class="form-control form-control-sm" rows="3"
                                      placeholder="Full description of the issue…"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total Claimed Deduction (Rp)</label>
                            <input type="number" step="1" name="claimed_deduction"
                                   class="form-control form-control-sm" value="0" id="cmpTotalClaimed">
                        </div>
                    </div>

                    <!-- Complaint Items -->
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 cmp-red"><i class="bi bi-list-ul"></i> Complaint Item Lines</h6>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="addCmpItem()">
                            <i class="bi bi-plus-lg"></i> Add Item
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered" id="cmpItemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Issue Type</th>
                                    <th>Parameter / Detail</th>
                                    <th>Description *</th>
                                    <th>Contract Value</th>
                                    <th>Actual Value</th>
                                    <th>Unit</th>
                                    <th>Qty Affected (kg)</th>
                                    <th>Claimed (Rp)</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="cmpItemsBody">
                                <!-- JS-populated -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-cmp btn-sm"><i class="bi bi-send"></i> Submit Complaint</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Resolve Modal ──────────────────────────────────────────────────────── -->
<div class="modal fade" id="resolveModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <input type="hidden" name="action" value="resolve_complaint">
            <input type="hidden" name="complaint_id" id="rsvCmpId">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-check-circle"></i> Resolve Complaint</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Agreed Deduction / Settlement (Rp)</label>
                        <input type="number" step="1" name="agreed_deduction" id="rsvAgreed"
                               class="form-control" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Resolution Notes *</label>
                        <textarea name="resolution" class="form-control" rows="4" required
                                  placeholder="How was this resolved? Credit note issued? Re-delivery scheduled?"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Resolve</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Detail Modal ────────────────────────────────────────────────────────── -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-cmp text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text"></i> Complaint Detail</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailModalBody">
                <div class="text-center text-muted py-4"><i class="bi bi-hourglass-split"></i> Loading…</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
let cmpItemIdx = 0;
function addCmpItem() {
    const idx  = cmpItemIdx++;
    const body = document.getElementById('cmpItemsBody');
    const row  = document.createElement('tr');
    row.id = 'cmprow_' + idx;
    row.innerHTML = `
        <td>
            <select name="item_type[]" class="form-select form-select-sm" style="min-width:130px">
                <option value="quality_param">Quality Param</option>
                <option value="weight_short">Weight Short</option>
                <option value="weight_excess">Weight Excess</option>
                <option value="contamination">Contamination</option>
                <option value="packaging">Packaging</option>
                <option value="other">Other</option>
            </select>
        </td>
        <td><input type="text" name="item_param_name[]" class="form-control form-control-sm"
                   placeholder="FFA / Moisture…" style="min-width:100px"></td>
        <td><input type="text" name="item_description[]" class="form-control form-control-sm"
                   placeholder="e.g. FFA exceeded 5%" required style="min-width:160px"></td>
        <td><input type="number" step="0.0001" name="item_contract_val[]"
                   class="form-control form-control-sm" placeholder="—" style="width:80px"></td>
        <td><input type="number" step="0.0001" name="item_actual_val[]"
                   class="form-control form-control-sm" placeholder="—" style="width:80px"></td>
        <td><input type="text" name="item_unit[]" class="form-control form-control-sm"
                   placeholder="%" style="width:55px"></td>
        <td><input type="number" step="0.01" name="item_qty_affected[]"
                   class="form-control form-control-sm" placeholder="0" style="width:90px"
                   oninput="sumCmpClaims()"></td>
        <td><input type="number" step="1" name="item_claimed_amt[]"
                   class="form-control form-control-sm" placeholder="0" style="width:110px"
                   oninput="sumCmpClaims()"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="document.getElementById('cmprow_${idx}').remove(); sumCmpClaims()">
            <i class="bi bi-x"></i></button></td>`;
    body.appendChild(row);
}

function sumCmpClaims() {
    let total = 0;
    document.querySelectorAll('[name="item_claimed_amt[]"]').forEach(el => {
        total += parseFloat(el.value) || 0;
    });
    document.getElementById('cmpTotalClaimed').value = total;
}

function openResolveModal(cmpId, claimed) {
    document.getElementById('rsvCmpId').value  = cmpId;
    document.getElementById('rsvAgreed').value = claimed;
    new bootstrap.Modal(document.getElementById('resolveModal')).show();
}

document.getElementById('createCmpModal').addEventListener('show.bs.modal', () => {
    if (document.getElementById('cmpItemsBody').children.length === 0) addCmpItem();
});

// Auto-open New Complaint modal if arriving from quick-raise button
<?php if ($receiving_id_f): ?>
window.addEventListener('DOMContentLoaded', () => {
    new bootstrap.Modal(document.getElementById('createCmpModal')).show();
});
<?php endif; ?>

function openDetailModal(cmpId) {
    const body  = document.getElementById('detailModalBody');
    const modal = new bootstrap.Modal(document.getElementById('detailModal'));
    body.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-hourglass-split"></i> Loading…</div>';
    modal.show();
    fetch('ajax_complaint_detail.php?complaint_id=' + cmpId)
        .then(r => r.text())
        .then(html => { body.innerHTML = html; })
        .catch(() => { body.innerHTML = '<div class="alert alert-danger">Failed to load detail.</div>'; });
}
</script>

<?php require_once 'includes/footer.php'; ?>
