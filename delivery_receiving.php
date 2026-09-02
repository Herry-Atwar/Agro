<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_delivery_receiving');

// ─── Quality parameter templates per product type ─────────────────────────────
function quality_params_for(string $product): array {
    return match($product) {
        'CPO','PKO' => [
            ['name'=>'FFA',         'unit'=>'%',         'spec'=>5.00,  'tolerance'=>0.50],
            ['name'=>'Moisture',    'unit'=>'%',         'spec'=>0.15,  'tolerance'=>0.05],
            ['name'=>'Impurities',  'unit'=>'%',         'spec'=>0.05,  'tolerance'=>0.02],
            ['name'=>'DOBI',        'unit'=>'index',     'spec'=>2.31,  'tolerance'=>0.10],
            ['name'=>'Colour (R)',  'unit'=>'Lovibond',  'spec'=>3.50,  'tolerance'=>0.50],
        ],
        'Kernel' => [
            ['name'=>'Moisture',         'unit'=>'%', 'spec'=>7.00, 'tolerance'=>0.50],
            ['name'=>'Dirt',             'unit'=>'%', 'spec'=>0.50, 'tolerance'=>0.20],
            ['name'=>'Broken Kernel',    'unit'=>'%', 'spec'=>15.0, 'tolerance'=>2.00],
            ['name'=>'Oil Content',      'unit'=>'%', 'spec'=>48.0, 'tolerance'=>2.00],
        ],
        'FFB' => [
            ['name'=>'Unripe Bunch',     'unit'=>'%', 'spec'=>1.00, 'tolerance'=>0.50],
            ['name'=>'Overripe Bunch',   'unit'=>'%', 'spec'=>5.00, 'tolerance'=>2.00],
            ['name'=>'Empty Bunch',      'unit'=>'%', 'spec'=>2.00, 'tolerance'=>1.00],
            ['name'=>'Long Stalk (>2cm)','unit'=>'%', 'spec'=>5.00, 'tolerance'=>2.00],
        ],
        default => [
            ['name'=>'Quality Check', 'unit'=>'—', 'spec'=>null, 'tolerance'=>null],
        ],
    };
}

// ─── Auto-number ──────────────────────────────────────────────────────────────
function gen_gr_number(PDO $db): string {
    $ym     = date('Ym');
    $prefix = 'GR-' . $ym . '-';
    $stmt   = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(receiving_number,'-',-1) AS UNSIGNED)),0)+1
        FROM delivery_receivings WHERE receiving_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // ── Save receiving record ────────────────────────────────────────────────
    if ($action === 'save_receiving') {
        $del_id = (int)post('delivery_id');
        try {
            $db->beginTransaction();

            // Check not already received
            $exists = $db->prepare("SELECT receiving_id FROM delivery_receivings WHERE delivery_id=?");
            $exists->execute([$del_id]);
            if ($exists->fetchColumn()) {
                throw new Exception('A receiving record already exists for this delivery.');
            }

            $gr_num = gen_gr_number($db);

            $received_gross = (float)post('received_gross_kg');
            $received_tare  = (float)post('received_tare_kg');
            $net            = $received_gross - $received_tare;

            // Fetch supplier net weight to calculate variance
            $pd_row = $db->prepare("SELECT net_weight_kg, company_id, customer_id FROM product_deliveries WHERE delivery_id=?");
            $pd_row->execute([$del_id]);
            $pd = $pd_row->fetch(PDO::FETCH_ASSOC);
            $weight_diff  = $net - (float)$pd['net_weight_kg'];

            $q_status = post('quantity_status') ?: 'accepted';
            $ql_status = post('quality_status') ?: 'accepted';

            $db->prepare("
                INSERT INTO delivery_receivings
                    (receiving_number, delivery_id, receiving_date,
                     company_id, customer_id,
                     received_gross_kg, received_tare_kg,
                     quantity_status, quality_status,
                     weight_difference_kg, deduction_amount,
                     received_by, receiver_position, storage_location,
                     status, notes, created_by)
                VALUES (?,?,?, ?,?, ?,?, ?,?, ?,?, ?,?,?, 'submitted',?,'admin')
            ")->execute([
                $gr_num, $del_id, post('receiving_date'),
                (int)$pd['company_id'], (int)$pd['customer_id'],
                $received_gross, $received_tare,
                $q_status, $ql_status,
                $weight_diff, (float)post('deduction_amount'),
                post('received_by'), post('receiver_position'), post('storage_location'),
                post('notes'),
            ]);
            $gr_id = (int)$db->lastInsertId();

            // Quality parameters
            $param_names  = $_POST['param_name']      ?? [];
            $param_units  = $_POST['param_unit']      ?? [];
            $param_specs  = $_POST['param_spec']      ?? [];
            $param_tols   = $_POST['param_tolerance'] ?? [];
            $param_vals   = $_POST['param_actual']    ?? [];
            $param_rmk    = $_POST['param_remarks']   ?? [];

            foreach ($param_names as $i => $pname) {
                $actual = $param_vals[$i] ?? null;
                if ($pname === '' || $actual === null || $actual === '') continue;
                $db->prepare("
                    INSERT INTO receiving_quality_params
                        (receiving_id, param_name, unit, contract_spec, actual_value, tolerance, remarks)
                    VALUES (?,?,?,?,?,?,?)
                ")->execute([
                    $gr_id,
                    trim($pname),
                    $param_units[$i]  ?? null,
                    $param_specs[$i]  !== '' ? (float)$param_specs[$i]  : null,
                    (float)$actual,
                    $param_tols[$i]   !== '' ? (float)$param_tols[$i]  : null,
                    $param_rmk[$i]    ?? null,
                ]);
            }

            // Update delivery status → received
            $db->prepare("UPDATE product_deliveries SET status='received', updated_by='admin' WHERE delivery_id=?")
               ->execute([$del_id]);

            $db->commit();
            set_message('success', "Receiving record <b>$gr_num</b> saved.");
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('delivery_receiving.php');
    }

    // ── Approve receiving ─────────────────────────────────────────────────────
    if ($action === 'approve_receiving') {
        try {
            $db->prepare("
                UPDATE delivery_receivings
                SET status='approved', approved_by='admin', approved_at=NOW(), updated_by='admin'
                WHERE receiving_id=?
            ")->execute([(int)post('receiving_id')]);
            set_message('success', 'Receiving approved.');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('delivery_receiving.php');
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$year            = get('year', date('Y'));
$status_filter   = get('status', '');
$search          = get('search', '');
$delivery_id_pre = (int)get('delivery_id', 0);   // pre-select a delivery

// ─── Reference: invoiced deliveries not yet received ─────────────────────────
$pending_deliveries = $db->query("
    SELECT pd.delivery_id, pd.delivery_number, pd.delivery_date,
           pd.product_type, pd.net_weight_kg,
           cu.customer_name, c.company_name, pd.vehicle_number
    FROM product_deliveries pd
    JOIN customers cu ON pd.customer_id = cu.customer_id
    JOIN companies c  ON pd.company_id  = c.company_id
    WHERE pd.status IN ('invoiced','received')
      AND NOT EXISTS (SELECT 1 FROM delivery_receivings gr WHERE gr.delivery_id = pd.delivery_id)
    ORDER BY pd.delivery_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pending_map = [];
foreach ($pending_deliveries as $p) { $pending_map[$p['delivery_id']] = $p; }

// Pre-selected delivery for auto-fill
$pre = $delivery_id_pre ? ($pending_map[$delivery_id_pre] ?? null) : null;
$pre_params = $pre ? quality_params_for($pre['product_type']) : [];

// ─── Receiving list ───────────────────────────────────────────────────────────
try {
    $sql = "
        SELECT gr.*, pd.delivery_number, pd.delivery_date, pd.net_weight_kg,
               pd.product_type, pd.vehicle_number,
               cu.customer_name, c.company_name,
               COUNT(qp.param_id)                AS param_count,
               SUM(CASE WHEN qp.pass=0 THEN 1 ELSE 0 END) AS failed_params,
               COUNT(cmp.complaint_id)            AS complaint_count
        FROM delivery_receivings gr
        JOIN product_deliveries pd ON gr.delivery_id = pd.delivery_id
        JOIN customers cu          ON gr.customer_id = cu.customer_id
        JOIN companies c           ON gr.company_id  = c.company_id
        LEFT JOIN receiving_quality_params qp ON qp.receiving_id = gr.receiving_id
        LEFT JOIN delivery_complaints cmp      ON cmp.receiving_id = gr.receiving_id
        WHERE YEAR(gr.receiving_date) = ?";
    $p2 = [$year];
    if ($status_filter) { $sql .= " AND gr.status = ?";   $p2[] = $status_filter; }
    if ($search) {
        $sql .= " AND (gr.receiving_number LIKE ? OR pd.delivery_number LIKE ? OR cu.customer_name LIKE ?)";
        $t = "%$search%"; $p2[] = $t; $p2[] = $t; $p2[] = $t;
    }
    $sql .= " GROUP BY gr.receiving_id ORDER BY gr.receiving_date DESC, gr.receiving_id DESC";
    $stmt = $db->prepare($sql); $stmt->execute($p2);
    $receivings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $receivings = []; }

$status_colours = [
    'draft'     => 'secondary',
    'submitted' => 'warning',
    'approved'  => 'success',
    'disputed'  => 'danger',
];
$qty_colours = ['accepted'=>'success','short'=>'warning','excess'=>'info','disputed'=>'danger'];
$qlt_colours = ['accepted'=>'success','conditionally_accepted'=>'warning','rejected'=>'danger'];
$product_colours = ['FFB'=>'success','CPO'=>'warning','Kernel'=>'info','PKO'=>'primary','Other'=>'secondary'];

require_once 'includes/header.php';
?>

<style>
    .gr-orange { color: #d97706 !important; }
    .bg-gr     { background-color: #d97706 !important; }
    .btn-gr    { background-color: #d97706; color:#fff; border:none; }
    .btn-gr:hover { background-color: #b45309; color:#fff; }
    .pass-row  { background:#f0fdf4; }
    .fail-row  { background:#fef2f2; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="gr-orange"><i class="bi bi-clipboard2-check"></i> Delivery Receiving</h1>
            <p class="text-muted mb-0">
                <a href="sales_contracts.php"    class="text-decoration-none">Contracts</a>
                &rsaquo; <a href="product_deliveries.php" class="text-decoration-none">Deliveries</a>
                &rsaquo; <b>Receiving</b>
                &rsaquo; <a href="delivery_complaints.php" class="text-decoration-none">Complaints</a>
                &rsaquo; <a href="delivery_monitoring.php" class="text-decoration-none">Monitoring</a>
            </p>
        </div>
        <div class="col-auto d-flex gap-2">
            <a href="delivery_complaints.php" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-exclamation-triangle"></i> Complaints
            </a>
            <button class="btn btn-gr" data-bs-toggle="modal" data-bs-target="#createGRModal">
                <i class="bi bi-plus-circle"></i> Record Receiving
            </button>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- ── Info ────────────────────────────────────────────────────────────────── -->
<div class="alert alert-info py-2 mb-3">
    <i class="bi bi-info-circle"></i>
    <strong>Customer Receiving:</strong> Record the customer-site weighbridge result,
    quality parameters, and any quantity/quality discrepancy.
    Failing quality parameters or weight shorts automatically trigger a complaint notice.
</div>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-gr text-white py-2"><i class="bi bi-funnel"></i> Filter Records</div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="GR # / PD # / customer…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = date('Y')-2; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y==$year?'selected':''?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <?php foreach (array_keys($status_colours) as $s): ?>
                        <option value="<?= $s ?>" <?= $s===$status_filter?'selected':''?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-gr btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="delivery_receiving.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── KPI ─────────────────────────────────────────────────────────────────── -->
<?php
$total_rec    = count($receivings);
$approved_cnt = count(array_filter($receivings, fn($r)=>$r['status']==='approved'));
$with_issues  = count(array_filter($receivings, fn($r)=>(int)$r['failed_params']>0 || $r['quantity_status']!=='accepted'));
$complaints   = array_sum(array_column($receivings,'complaint_count'));
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Total Receivings</div>
        <div class="fw-bold fs-4"><?= $total_rec ?></div>
        <div class="small text-success"><?= $approved_cnt ?> approved</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Pending Approval</div>
        <div class="fw-bold fs-4 text-warning"><?= count(array_filter($receivings,fn($r)=>$r['status']==='submitted')) ?></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">With Issues</div>
        <div class="fw-bold fs-4 text-danger"><?= $with_issues ?></div>
        <div class="small text-muted">qty / quality discrepancy</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small">Complaints Raised</div>
        <div class="fw-bold fs-4 text-danger"><?= $complaints ?></div>
        <a href="delivery_complaints.php" class="small text-decoration-none">View all →</a>
    </div></div></div>
</div>

<!-- ── Receivings Table ────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header bg-gr text-white py-2">
        <i class="bi bi-table"></i> <?= $total_rec ?> Receiving Record(s)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>GR #</th>
                        <th>Date</th>
                        <th>Delivery</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th class="text-end">Delivered (MT)</th>
                        <th class="text-end">Received (MT)</th>
                        <th class="text-end">Variance (kg)</th>
                        <th>Qty Status</th>
                        <th>Quality</th>
                        <th class="text-center">Params</th>
                        <th class="text-center">Complaints</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($receivings)): ?>
                        <tr><td colspan="14" class="text-center text-muted py-4">No receiving records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($receivings as $r): ?>
                            <?php
                            $var_kg   = (float)$r['received_net_kg'] - (float)$r['net_weight_kg'];
                            $var_cls  = $var_kg < 0 ? 'text-danger' : ($var_kg > 0 ? 'text-info' : 'text-success');
                            $row_cls  = ($r['quality_status']==='rejected' || $r['quantity_status']==='disputed') ? 'table-danger' : '';
                            ?>
                            <tr class="<?= $row_cls ?>">
                                <td class="fw-bold"><?= htmlspecialchars($r['receiving_number']) ?></td>
                                <td><?= date('d/m/Y', strtotime($r['receiving_date'])) ?></td>
                                <td>
                                    <a href="product_deliveries.php" class="text-decoration-none small">
                                        <?= htmlspecialchars($r['delivery_number']) ?>
                                    </a><br>
                                    <small class="text-muted"><?= htmlspecialchars($r['vehicle_number'] ?? '—') ?></small>
                                </td>
                                <td><?= htmlspecialchars($r['customer_name']) ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($r['company_name']) ?></small></td>
                                <td><span class="badge bg-<?= $product_colours[$r['product_type']]??'secondary' ?>"><?= $r['product_type'] ?></span></td>
                                <td class="text-end"><?= number_format($r['net_weight_kg']/1000, 3) ?></td>
                                <td class="text-end"><?= number_format($r['received_net_kg']/1000, 3) ?></td>
                                <td class="text-end <?= $var_cls ?>">
                                    <?= ($var_kg >= 0 ? '+' : '') . number_format($var_kg, 0) ?>
                                </td>
                                <td><span class="badge bg-<?= $qty_colours[$r['quantity_status']]??'secondary' ?>">
                                    <?= ucfirst($r['quantity_status']) ?>
                                </span></td>
                                <td><span class="badge bg-<?= $qlt_colours[$r['quality_status']]??'secondary' ?>">
                                    <?= ucwords(str_replace('_',' ',$r['quality_status'])) ?>
                                </span></td>
                                <td class="text-center">
                                    <?php if ((int)$r['param_count'] > 0): ?>
                                        <button class="btn btn-sm btn-outline-secondary"
                                                onclick="showParams(<?= $r['receiving_id'] ?>)">
                                            <?php if ((int)$r['failed_params'] > 0): ?>
                                                <span class="badge bg-danger"><?= $r['failed_params'] ?> fail</span>
                                            <?php else: ?>
                                                <span class="badge bg-success"><?= $r['param_count'] ?> ✓</span>
                                            <?php endif; ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ((int)$r['complaint_count'] > 0): ?>
                                        <a href="delivery_complaints.php?receiving_id=<?= $r['receiving_id'] ?>"
                                           class="badge bg-danger text-decoration-none">
                                            <i class="bi bi-exclamation-triangle"></i> <?= $r['complaint_count'] ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php
                                    $has_issue = ($r['quantity_status'] !== 'accepted'
                                                  || $r['quality_status'] !== 'accepted'
                                                  || (int)$r['failed_params'] > 0);
                                    ?>
                                    <button class="btn btn-sm <?= $has_issue ? 'btn-danger' : 'btn-outline-danger' ?>"
                                            title="Raise New Complaint"
                                            onclick="openQuickComplaint(<?= $r['receiving_id'] ?>, <?= htmlspecialchars(json_encode($r['receiving_number'])) ?>, <?= htmlspecialchars(json_encode($r['delivery_number'])) ?>, <?= htmlspecialchars(json_encode($r['customer_name'])) ?>, <?= htmlspecialchars(json_encode($r['product_type'])) ?>)">
                                        <i class="bi bi-exclamation-circle<?= $has_issue ? '-fill' : '' ?>"></i>
                                    </button>
                                </td>
                                <td><span class="badge bg-<?= $status_colours[$r['status']]??'secondary' ?>"><?= ucfirst($r['status']) ?></span></td>
                                <td>
                                    <?php if ($r['status'] === 'submitted'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="approve_receiving">
                                            <input type="hidden" name="receiving_id" value="<?= $r['receiving_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success"
                                                    onclick="return confirm('Approve this receiving record?')"
                                                    title="Approve">
                                                <i class="bi bi-check-lg"></i> Approve
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
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

<!-- ── Create Receiving Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="createGRModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form method="POST" id="grForm">
            <input type="hidden" name="action" value="save_receiving">
            <div class="modal-content">
                <div class="modal-header bg-gr text-white">
                    <h5 class="modal-title"><i class="bi bi-clipboard2-check"></i> Record Customer Receiving</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <!-- Delivery selection -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label">Delivery to Receive *</label>
                            <select name="delivery_id" id="grDeliveryId" class="form-select form-select-sm"
                                    onchange="fillDeliveryInfo(this)" required>
                                <option value="">— Select Invoiced Delivery —</option>
                                <?php foreach ($pending_deliveries as $pd): ?>
                                    <option value="<?= $pd['delivery_id'] ?>"
                                            data-product="<?= $pd['product_type'] ?>"
                                            data-net="<?= $pd['net_weight_kg'] ?>"
                                            data-customer="<?= htmlspecialchars($pd['customer_name']) ?>"
                                            data-vehicle="<?= htmlspecialchars($pd['vehicle_number'] ?? '') ?>"
                                            <?= $delivery_id_pre==$pd['delivery_id']?'selected':'' ?>>
                                        <?= htmlspecialchars($pd['delivery_number']) ?>
                                        — <?= htmlspecialchars($pd['customer_name']) ?>
                                        (<?= $pd['product_type'] ?>
                                        <?= number_format($pd['net_weight_kg']/1000,1) ?> MT)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Receiving Date *</label>
                            <input type="date" name="receiving_date" class="form-control form-control-sm"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Supplier Net (kg)</label>
                            <input type="text" id="grSupplierNet" class="form-control form-control-sm"
                                   readonly style="background:#f0f0ff;">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Product</label>
                            <input type="text" id="grProduct" class="form-control form-control-sm" readonly
                                   style="background:#f0f0ff;">
                        </div>
                    </div>

                    <!-- Customer weighbridge -->
                    <h6 class="gr-orange border-bottom pb-1 mb-2"><i class="bi bi-speedometer"></i> Customer Weighbridge</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Gross Weight (kg) *</label>
                            <input type="number" step="0.01" name="received_gross_kg" id="grGross"
                                   class="form-control form-control-sm" required oninput="calcReceivedNet()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tare Weight (kg) *</label>
                            <input type="number" step="0.01" name="received_tare_kg" id="grTare"
                                   class="form-control form-control-sm" required oninput="calcReceivedNet()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Net Weight (kg)</label>
                            <input type="text" id="grNet" class="form-control form-control-sm"
                                   readonly style="background:#f0fff4;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Variance (kg)</label>
                            <input type="text" id="grVariance" class="form-control form-control-sm"
                                   readonly style="background:#fffbeb;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quantity Status *</label>
                            <select name="quantity_status" class="form-select form-select-sm">
                                <option value="accepted">Accepted</option>
                                <option value="short">Short</option>
                                <option value="excess">Excess</option>
                                <option value="disputed">Disputed</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Deduction Amount (Rp)</label>
                            <input type="number" step="1" name="deduction_amount"
                                   class="form-control form-control-sm" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Overall Quality Status *</label>
                            <select name="quality_status" class="form-select form-select-sm">
                                <option value="accepted">Accepted</option>
                                <option value="conditionally_accepted">Conditionally Accepted</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Storage Location</label>
                            <input type="text" name="storage_location" class="form-control form-control-sm"
                                   placeholder="Tank / Silo / Warehouse">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Received By</label>
                            <input type="text" name="received_by" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Position / Title</label>
                            <input type="text" name="receiver_position" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>

                    <!-- Quality Parameters -->
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 gr-orange"><i class="bi bi-flask"></i> Quality Parameters</h6>
                        <button type="button" class="btn btn-sm btn-outline-warning" onclick="addQualityRow()">
                            <i class="bi bi-plus-lg"></i> Add Parameter
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered" id="qParamsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Parameter</th>
                                    <th>Unit</th>
                                    <th>Contract Spec</th>
                                    <th>Actual Value</th>
                                    <th>Tolerance ±</th>
                                    <th>Result</th>
                                    <th>Remarks</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="qParamsBody">
                                <!-- JS-populated -->
                            </tbody>
                        </table>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gr btn-sm"><i class="bi bi-save"></i> Save Receiving</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Quality Params View Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="paramsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-gr text-white">
                <h5 class="modal-title"><i class="bi bi-flask"></i> Quality Parameters</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="paramsModalBody">
                <div class="text-center text-muted py-3"><i class="bi bi-hourglass"></i> Loading…</div>
            </div>
        </div>
    </div>
</div>

<script>
// Quality param templates by product type
const qualityTemplates = <?= json_encode([
    'CPO'    => quality_params_for('CPO'),
    'PKO'    => quality_params_for('PKO'),
    'Kernel' => quality_params_for('Kernel'),
    'FFB'    => quality_params_for('FFB'),
    'Other'  => quality_params_for('Other'),
]) ?>;

let currentProduct = '<?= htmlspecialchars($pre['product_type'] ?? '') ?>';
let supplierNet    = <?= $pre ? $pre['net_weight_kg'] : 0 ?>;

function fillDeliveryInfo(sel) {
    const opt   = sel.options[sel.selectedIndex];
    const net   = parseFloat(opt.dataset.net) || 0;
    const prod  = opt.dataset.product || '';
    supplierNet = net;
    currentProduct = prod;
    document.getElementById('grSupplierNet').value = net ? net.toLocaleString('id-ID') : '';
    document.getElementById('grProduct').value     = prod;
    calcReceivedNet();
    populateQualityParams(prod);
}

function calcReceivedNet() {
    const gross   = parseFloat(document.getElementById('grGross').value) || 0;
    const tare    = parseFloat(document.getElementById('grTare').value)  || 0;
    const net     = gross - tare;
    document.getElementById('grNet').value = net > 0 ? net.toFixed(2) : '';

    const variance = net - supplierNet;
    const varEl    = document.getElementById('grVariance');
    varEl.value    = (variance >= 0 ? '+' : '') + variance.toFixed(2);
    varEl.style.color = variance < 0 ? '#dc2626' : (variance > 0 ? '#0284c7' : '#16a34a');
}

let qRowIdx = 0;
function addQualityRow(name='', unit='', spec='', actual='', tol='', remarks='') {
    const idx  = qRowIdx++;
    const body = document.getElementById('qParamsBody');
    const row  = document.createElement('tr');
    row.id = 'qrow_' + idx;
    row.innerHTML = `
        <td><input type="text"   name="param_name[]"      class="form-control form-control-sm"
                   value="${name}" required placeholder="e.g. FFA"></td>
        <td><input type="text"   name="param_unit[]"      class="form-control form-control-sm"
                   value="${unit}" style="width:70px" placeholder="%"></td>
        <td><input type="number" name="param_spec[]"      class="form-control form-control-sm"
                   value="${spec}" step="0.0001" placeholder="—"
                   oninput="evalRow(${idx})" style="width:90px"></td>
        <td><input type="number" name="param_actual[]"    class="form-control form-control-sm"
                   value="${actual}" step="0.0001" placeholder="0" required
                   oninput="evalRow(${idx})" id="actual_${idx}"></td>
        <td><input type="number" name="param_tolerance[]" class="form-control form-control-sm"
                   value="${tol}" step="0.0001" placeholder="—"
                   oninput="evalRow(${idx})" style="width:80px"></td>
        <td id="qresult_${idx}" class="text-center fw-bold">—</td>
        <td><input type="text"   name="param_remarks[]"   class="form-control form-control-sm"
                   value="${remarks}" placeholder="optional"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="document.getElementById('qrow_${idx}').remove()">
            <i class="bi bi-x"></i></button></td>`;
    body.appendChild(row);
    if (actual !== '') evalRow(idx);
}

function evalRow(idx) {
    const spec    = parseFloat(document.querySelector(`[name="param_spec[]"]:nth-of-type(1)`)?.value);
    // read by row id to be safe
    const row = document.getElementById('qrow_' + idx);
    if (!row) return;
    const specVal = parseFloat(row.querySelector('[name="param_spec[]"]').value);
    const actVal  = parseFloat(row.querySelector('[name="param_actual[]"]').value);
    const tolVal  = parseFloat(row.querySelector('[name="param_tolerance[]"]').value) || 0;
    const resEl   = document.getElementById('qresult_' + idx);
    if (isNaN(specVal) || isNaN(actVal)) { resEl.textContent = '—'; resEl.style.color=''; return; }
    const pass = Math.abs(actVal - specVal) <= tolVal;
    resEl.textContent = pass ? '✓ PASS' : '✗ FAIL';
    resEl.style.color = pass ? '#16a34a' : '#dc2626';
    row.style.background = pass ? '#f0fdf4' : '#fef2f2';
}

function populateQualityParams(product) {
    document.getElementById('qParamsBody').innerHTML = '';
    qRowIdx = 0;
    const tmpl = qualityTemplates[product] || qualityTemplates['Other'];
    tmpl.forEach(p => addQualityRow(p.name, p.unit, p.spec ?? '', '', p.tolerance ?? ''));
}

// Show quality params detail via AJAX
function showParams(receivingId) {
    const modal = new bootstrap.Modal(document.getElementById('paramsModal'));
    document.getElementById('paramsModalBody').innerHTML =
        '<div class="text-center text-muted py-3"><i class="bi bi-hourglass"></i> Loading…</div>';
    modal.show();
    fetch('ajax_receiving_params.php?receiving_id=' + receivingId)
        .then(r => r.text())
        .then(html => { document.getElementById('paramsModalBody').innerHTML = html; })
        .catch(() => { document.getElementById('paramsModalBody').innerHTML = '<p class="text-danger p-3">Error loading.</p>'; });
}

// Auto-populate on modal open if delivery pre-selected
document.getElementById('createGRModal').addEventListener('show.bs.modal', () => {
    const sel = document.getElementById('grDeliveryId');
    if (sel.value) fillDeliveryInfo(sel);
    else if (document.getElementById('qParamsBody').children.length === 0) {
        // start with one blank row
        addQualityRow();
    }
});

// Quick-raise: open complaints page with receiving pre-selected
// Passes context to help the user fill the form quickly
function openQuickComplaint(receivingId, grNum, pdNum, customer, product) {
    const url = 'delivery_complaints.php?receiving_id=' + receivingId
              + '&_hint=' + encodeURIComponent(grNum + ' – ' + pdNum + ' (' + customer + ', ' + product + ')');
    window.location.href = url;
}
</script>

<?php require_once 'includes/footer.php'; ?>
