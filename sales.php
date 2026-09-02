<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Sales Management";

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // ── Create ────────────────────────────────────────────────────────────────
    if ($action === 'create_sale') {
        try {
            $qty   = (float) post('quantity_kg');
            $price = (float) post('unit_price');
            $db->prepare("
                INSERT INTO sales (sale_date, company_id, customer_id, product_type,
                    quantity_kg, unit_price, total_amount, currency,
                    payment_terms, payment_status, delivery_location,
                    delivery_date, invoice_number, reference_number, notes, created_by)
                VALUES (?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?,'admin')
            ")->execute([
                post('sale_date'), post('company_id'), post('customer_id'), post('product_type'),
                $qty, $price, $qty * $price, post('currency') ?: 'IDR',
                post('payment_terms'), post('payment_status') ?: 'pending', post('delivery_location'),
                post('delivery_date') ?: null, post('invoice_number'), post('reference_number'), post('notes'),
            ]);
            set_message('success', 'Sale created successfully!');
        } catch (PDOException $e) {
            set_message('error', 'Error creating sale: ' . $e->getMessage());
        }
        redirect("sales.php?year=" . post('sale_date', date('Y-m-d')) . "&month=" . date('m', strtotime(post('sale_date', date('Y-m-d')))));
    }

    // ── Update ────────────────────────────────────────────────────────────────
    elseif ($action === 'update_sale') {
        try {
            $qty   = (float) post('quantity_kg');
            $price = (float) post('unit_price');
            $db->prepare("
                UPDATE sales SET
                    sale_date=?, company_id=?, customer_id=?, product_type=?,
                    quantity_kg=?, unit_price=?, total_amount=?, currency=?,
                    payment_terms=?, payment_status=?, payment_date=?,
                    delivery_location=?, delivery_date=?,
                    invoice_number=?, reference_number=?, notes=?, updated_by='admin'
                WHERE sale_id=?
            ")->execute([
                post('sale_date'), post('company_id'), post('customer_id'), post('product_type'),
                $qty, $price, $qty * $price, post('currency') ?: 'IDR',
                post('payment_terms'), post('payment_status'),
                (post('payment_status') === 'paid' && post('payment_date')) ? post('payment_date') : null,
                post('delivery_location'), post('delivery_date') ?: null,
                post('invoice_number'), post('reference_number'), post('notes'),
                (int) post('sale_id'),
            ]);
            set_message('success', 'Sale updated successfully!');
        } catch (PDOException $e) {
            set_message('error', 'Error updating sale: ' . $e->getMessage());
        }
        redirect("sales.php?year=" . get('year', date('Y')) . "&month=" . get('month', date('m')));
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    elseif ($action === 'delete_sale') {
        try {
            $db->prepare("DELETE FROM sales WHERE sale_id=?")->execute([(int) post('sale_id')]);
            set_message('success', 'Sale deleted successfully!');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting sale: ' . $e->getMessage());
        }
        redirect("sales.php?year=" . get('year', date('Y')) . "&month=" . get('month', date('m')));
    }
}

require_once 'includes/header.php';

// ─── Filters ──────────────────────────────────────────────────────────────────
$year           = get('year',  date('Y'));
$month          = get('month', date('m'));
$company_filter = get('company_id', '');
$product_type   = get('product_type', 'all');
$search         = get('search', '');

// ─── Reference data ───────────────────────────────────────────────────────────
try { $companies = $db->query("SELECT * FROM companies ORDER BY company_code")->fetchAll(PDO::FETCH_ASSOC); }
catch (PDOException $e) { $companies = []; }
try { $customers = $db->query("SELECT * FROM customers ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC); }
catch (PDOException $e) { $customers = []; }

// ─── Monthly summary ─────────────────────────────────────────────────────────
try {
    $sum_sql = "SELECT product_type, COUNT(*) as cnt, SUM(quantity_kg) as total_kg,
                       SUM(total_amount) as revenue
                FROM sales WHERE YEAR(sale_date)=? AND MONTH(sale_date)=?";
    $sum_p   = [$year, $month];
    if ($company_filter) { $sum_sql .= " AND company_id=?"; $sum_p[] = $company_filter; }
    $sum_sql .= " GROUP BY product_type";
    $stmt = $db->prepare($sum_sql); $stmt->execute($sum_p);
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $summary = []; }

// ─── Sales list ───────────────────────────────────────────────────────────────
try {
    $sql = "SELECT s.*, c.company_name, cu.customer_name
            FROM sales s
            JOIN companies c  ON s.company_id  = c.company_id
            JOIN customers cu ON s.customer_id = cu.customer_id
            WHERE YEAR(s.sale_date)=? AND MONTH(s.sale_date)=?";
    $p   = [$year, $month];
    if ($company_filter)    { $sql .= " AND s.company_id=?";    $p[] = $company_filter; }
    if ($product_type !== 'all') { $sql .= " AND s.product_type=?"; $p[] = $product_type; }
    if ($search) {
        $sql .= " AND (s.invoice_number LIKE ? OR cu.customer_name LIKE ? OR c.company_name LIKE ?)";
        $t = "%$search%"; $p[] = $t; $p[] = $t; $p[] = $t;
    }
    $sql .= " ORDER BY s.sale_date DESC, s.sale_id DESC";
    $stmt = $db->prepare($sql); $stmt->execute($p);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $sales = []; }

$total_revenue  = array_sum(array_column($sales, 'total_amount'));
$total_quantity = array_sum(array_column($sales, 'quantity_kg'));
$paid_revenue   = array_sum(array_map(fn($s) => $s['payment_status'] === 'paid' ? $s['total_amount'] : 0, $sales));

// Product badge colours
$product_colours = [
    'FFB'    => 'success',
    'CPO'    => 'warning',
    'Kernel' => 'info',
    'PKO'    => 'primary',
    'Other'  => 'secondary',
];
$pay_colours = ['paid' => 'success', 'partial' => 'warning', 'pending' => 'danger'];
?>

<style>
    .sales-blue { color: #166c82 !important; }
    .bg-sales   { background-color: #166c82 !important; }
    .btn-sales  { background-color: #166c82; color: #fff; border: none; }
    .btn-sales:hover { background-color: #1a7d9a; color: #fff; }
    .page-header { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e9ecef; }
    .stat-card  { border-left: 4px solid #166c82; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="sales-blue"><i class="bi bi-cart-check"></i> Sales Management</h1>
            <p class="text-muted mb-0">Track and manage CPO, Kernel, and FFB sales transactions</p>
        </div>
        <div class="col-auto">
            <button class="btn btn-sales" data-bs-toggle="modal" data-bs-target="#createSaleModal">
                <i class="bi bi-plus-circle"></i> New Sale
            </button>
        </div>
    </div>
</div>

<?php display_message(); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-header bg-sales text-white py-2">
        <i class="bi bi-funnel"></i> <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?>
    </div>
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Search</label>
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="Invoice / customer / company…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= str_pad($m,2,'0',STR_PAD_LEFT) ?>" <?= $m == $month ? 'selected' : '' ?>>
                            <?= date('F', mktime(0,0,0,$m,1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Company</label>
                <select name="company_id" class="form-select form-select-sm">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= $c['company_id'] ?>" <?= $c['company_id'] == $company_filter ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['company_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Product</label>
                <select name="product_type" class="form-select form-select-sm">
                    <option value="all">All Products</option>
                    <?php foreach (['FFB','CPO','Kernel','PKO','Other'] as $pt): ?>
                        <option value="<?= $pt ?>" <?= $product_type == $pt ? 'selected' : '' ?>><?= $pt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-sales btn-sm w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- KPI cards -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body py-2">
                <div class="text-muted small">Transactions</div>
                <h4 class="sales-blue mb-0"><?= count($sales) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body py-2">
                <div class="text-muted small">Total Volume</div>
                <h4 class="mb-0"><?= number_format($total_quantity / 1000, 1) ?> MT</h4>
                <small class="text-muted"><?= number_format($total_quantity, 0) ?> kg</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body py-2">
                <div class="text-muted small">Total Revenue</div>
                <h4 class="sales-blue mb-0">Rp <?= number_format($total_revenue, 0, ',', '.') ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body py-2">
                <div class="text-muted small">Paid Revenue</div>
                <h4 class="text-success mb-0">Rp <?= number_format($paid_revenue, 0, ',', '.') ?></h4>
                <?php if ($total_revenue > 0): ?>
                    <small class="text-muted"><?= number_format($paid_revenue / $total_revenue * 100, 0) ?>% collected</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- By product mini summary -->
<?php if (!empty($summary)): ?>
<div class="row mb-3">
    <?php foreach ($summary as $s): $col = $product_colours[$s['product_type']] ?? 'secondary'; ?>
    <div class="col-md-3 mb-2">
        <div class="card border-<?= $col ?>">
            <div class="card-body py-2">
                <span class="badge bg-<?= $col ?>"><?= $s['product_type'] ?></span>
                <span class="ms-2 fw-bold"><?= number_format($s['total_kg']/1000,1) ?> MT</span>
                <div class="small text-muted">Rp <?= number_format($s['revenue'],0,',','.') ?> · <?= $s['cnt'] ?> txns</div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Sales table -->
<div class="card">
    <div class="card-header bg-sales text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list"></i> Sales Transactions (<?= count($sales) ?>)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th class="text-end">Qty (MT)</th>
                        <th class="text-end">Price/kg</th>
                        <th class="text-end">Total (Rp)</th>
                        <th>Payment</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">
                            <i class="bi bi-inbox" style="font-size:2rem"></i>
                            <p class="mt-1 mb-0">No sales records found</p>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($sales as $sale):
                            $pcol = $product_colours[$sale['product_type']] ?? 'secondary';
                            $scol = $pay_colours[$sale['payment_status']]   ?? 'secondary';
                        ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($sale['sale_date'])) ?></td>
                            <td><code><?= htmlspecialchars($sale['invoice_number']) ?></code></td>
                            <td>
                                <?= htmlspecialchars($sale['customer_name']) ?>
                                <br><small class="text-muted"><?= htmlspecialchars($sale['company_name']) ?></small>
                            </td>
                            <td><span class="badge bg-<?= $pcol ?>"><?= $sale['product_type'] ?></span></td>
                            <td class="text-end"><?= number_format($sale['quantity_kg']/1000, 2) ?></td>
                            <td class="text-end"><?= number_format($sale['unit_price'], 0) ?></td>
                            <td class="text-end fw-bold"><?= number_format($sale['total_amount'], 0, ',', '.') ?></td>
                            <td>
                                <span class="badge bg-<?= $scol ?>"><?= ucfirst($sale['payment_status']) ?></span>
                                <br><small class="text-muted"><?= $sale['payment_terms'] ?></small>
                            </td>
                            <td class="text-center text-nowrap">
                                <!-- View -->
                                <button type="button" class="btn btn-sm btn-outline-info btn-view"
                                    data-sale='<?= htmlspecialchars(json_encode($sale), ENT_QUOTES) ?>'
                                    title="View detail">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <!-- Edit -->
                                <button type="button" class="btn btn-sm btn-outline-warning btn-edit"
                                    data-sale='<?= htmlspecialchars(json_encode($sale), ENT_QUOTES) ?>'
                                    title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <!-- Delete -->
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('Delete invoice <?= htmlspecialchars($sale['invoice_number']) ?>? This cannot be undone.')">
                                    <input type="hidden" name="action"  value="delete_sale">
                                    <input type="hidden" name="sale_id" value="<?= $sale['sale_id'] ?>">
                                    <input type="hidden" name="year"    value="<?= $year ?>">
                                    <input type="hidden" name="month"   value="<?= $month ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
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


<!-- ═══ MODAL: View ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="viewSaleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-sales text-white">
                <h5 class="modal-title"><i class="bi bi-receipt"></i> Sale Detail</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody"><!-- filled by JS --></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" id="viewToEditBtn">
                    <i class="bi bi-pencil"></i> Edit This Sale
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ═══ MODAL: Create / Edit ══════════════════════════════════════════════════ -->
<div class="modal fade" id="createSaleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="saleForm">
                <div class="modal-header bg-sales text-white">
                    <h5 class="modal-title" id="saleModalTitle"><i class="bi bi-plus-circle"></i> New Sale</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action"  id="formAction"  value="create_sale">
                    <input type="hidden" name="sale_id" id="formSaleId"  value="">
                    <input type="hidden" name="year"    value="<?= $year ?>">
                    <input type="hidden" name="month"   value="<?= $month ?>">

                    <div class="row g-3">
                        <!-- Row 1 -->
                        <div class="col-md-4">
                            <label class="form-label">Sale Date <span class="text-danger">*</span></label>
                            <input type="date" name="sale_date" id="f_sale_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Invoice No <span class="text-danger">*</span></label>
                            <input type="text" name="invoice_number" id="f_invoice" class="form-control"
                                   placeholder="INV-<?= date('Ymd') ?>-001" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reference No</label>
                            <input type="text" name="reference_number" id="f_reference" class="form-control"
                                   placeholder="SO-2026-0001">
                        </div>

                        <!-- Row 2 -->
                        <div class="col-md-6">
                            <label class="form-label">Company <span class="text-danger">*</span></label>
                            <select name="company_id" id="f_company" class="form-select" required>
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= $c['company_id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Customer <span class="text-danger">*</span></label>
                            <select name="customer_id" id="f_customer" class="form-select" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $cu): ?>
                                    <option value="<?= $cu['customer_id'] ?>"><?= htmlspecialchars($cu['customer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Row 3 -->
                        <div class="col-md-4">
                            <label class="form-label">Product <span class="text-danger">*</span></label>
                            <select name="product_type" id="f_product" class="form-select" required>
                                <?php foreach (['FFB'=>'FFB (Fresh Fruit Bunch)','CPO'=>'CPO (Crude Palm Oil)','Kernel'=>'Palm Kernel','PKO'=>'PKO (Palm Kernel Oil)','Other'=>'Other'] as $v=>$l): ?>
                                    <option value="<?= $v ?>"><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity (kg) <span class="text-danger">*</span></label>
                            <input type="number" name="quantity_kg" id="f_qty" class="form-control"
                                   step="0.01" min="0.01" required>
                            <small class="text-muted" id="f_qty_mt">0.000 MT</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit Price (Rp/kg) <span class="text-danger">*</span></label>
                            <input type="number" name="unit_price" id="f_price" class="form-control"
                                   step="0.01" min="0" required>
                            <small class="text-muted">Total: <span id="f_total">Rp 0</span></small>
                        </div>

                        <!-- Row 4 -->
                        <div class="col-md-3">
                            <label class="form-label">Currency</label>
                            <select name="currency" id="f_currency" class="form-select">
                                <option value="IDR" selected>IDR</option>
                                <option value="USD">USD</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Payment Terms</label>
                            <select name="payment_terms" id="f_pay_terms" class="form-select">
                                <?php foreach (['Cash','Credit 7 days','Credit 14 days','Credit 21 days','Credit 30 days'] as $pt): ?>
                                    <option value="<?= $pt ?>"><?= $pt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Payment Status</label>
                            <select name="payment_status" id="f_pay_status" class="form-select">
                                <option value="pending">Pending</option>
                                <option value="partial">Partial</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                        <div class="col-md-3" id="payDateRow">
                            <label class="form-label">Payment Date</label>
                            <input type="date" name="payment_date" id="f_pay_date" class="form-control">
                        </div>

                        <!-- Row 5 -->
                        <div class="col-md-6">
                            <label class="form-label">Delivery Location</label>
                            <input type="text" name="delivery_location" id="f_del_loc" class="form-control"
                                   placeholder="e.g. Pelabuhan Belawan, Medan">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Delivery Date</label>
                            <input type="date" name="delivery_date" id="f_del_date" class="form-control">
                        </div>

                        <!-- Row 6 -->
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="f_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sales" id="formSubmitBtn">
                        <i class="bi bi-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
(function () {
    // ── helpers ───────────────────────────────────────────────────────────────
    const fmt  = n => new Intl.NumberFormat('id-ID').format(Math.round(n));
    const fmtd = d => d ? new Date(d + 'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '-';

    // ── live total calc ───────────────────────────────────────────────────────
    function recalc() {
        const qty   = parseFloat(document.getElementById('f_qty').value)   || 0;
        const price = parseFloat(document.getElementById('f_price').value) || 0;
        document.getElementById('f_qty_mt').textContent = (qty / 1000).toFixed(3) + ' MT';
        document.getElementById('f_total').textContent  = 'Rp ' + fmt(qty * price);
    }
    document.getElementById('f_qty').addEventListener('input', recalc);
    document.getElementById('f_price').addEventListener('input', recalc);

    // ── show/hide payment date row ────────────────────────────────────────────
    function togglePayDate() {
        const status = document.getElementById('f_pay_status').value;
        document.getElementById('payDateRow').style.display = status === 'paid' ? '' : 'none';
    }
    document.getElementById('f_pay_status').addEventListener('change', togglePayDate);
    togglePayDate();

    // ── fill form helper ──────────────────────────────────────────────────────
    function fillForm(sale) {
        document.getElementById('formSaleId').value  = sale.sale_id;
        document.getElementById('f_sale_date').value = sale.sale_date ? sale.sale_date.substring(0, 10) : '';
        document.getElementById('f_invoice').value   = sale.invoice_number  || '';
        document.getElementById('f_reference').value = sale.reference_number || '';
        document.getElementById('f_company').value   = sale.company_id;
        document.getElementById('f_customer').value  = sale.customer_id;
        document.getElementById('f_product').value   = sale.product_type;
        document.getElementById('f_qty').value       = sale.quantity_kg;
        document.getElementById('f_price').value     = sale.unit_price;
        document.getElementById('f_currency').value  = sale.currency || 'IDR';
        document.getElementById('f_pay_terms').value  = sale.payment_terms || 'Cash';
        document.getElementById('f_pay_status').value = sale.payment_status || 'pending';
        document.getElementById('f_pay_date').value  = sale.payment_date ? sale.payment_date.substring(0, 10) : '';
        document.getElementById('f_del_loc').value   = sale.delivery_location || '';
        document.getElementById('f_del_date').value  = sale.delivery_date ? sale.delivery_date.substring(0, 10) : '';
        document.getElementById('f_notes').value     = sale.notes || '';
        recalc();
        togglePayDate();
    }

    // ── product badge colour ──────────────────────────────────────────────────
    const pcolour = {FFB:'success', CPO:'warning', Kernel:'info', PKO:'primary', Other:'secondary'};
    const scolour = {paid:'success', partial:'warning', pending:'danger'};

    // ── View modal ────────────────────────────────────────────────────────────
    let currentSale = null;
    document.querySelectorAll('.btn-view').forEach(btn => {
        btn.addEventListener('click', function () {
            const sale = JSON.parse(this.dataset.sale);
            currentSale = sale;
            const pc = pcolour[sale.product_type] || 'secondary';
            const sc = scolour[sale.payment_status] || 'secondary';
            document.getElementById('viewModalBody').innerHTML = `
                <div class="row g-3">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr><th class="text-muted" style="width:40%">Invoice</th>
                                <td><code>${sale.invoice_number}</code></td></tr>
                            <tr><th class="text-muted">Reference</th>
                                <td>${sale.reference_number || '-'}</td></tr>
                            <tr><th class="text-muted">Sale Date</th>
                                <td>${fmtd(sale.sale_date)}</td></tr>
                            <tr><th class="text-muted">Company</th>
                                <td>${sale.company_name}</td></tr>
                            <tr><th class="text-muted">Customer</th>
                                <td>${sale.customer_name}</td></tr>
                            <tr><th class="text-muted">Product</th>
                                <td><span class="badge bg-${pc}">${sale.product_type}</span></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr><th class="text-muted" style="width:45%">Quantity</th>
                                <td>${fmt(sale.quantity_kg)} kg<br>
                                    <small class="text-muted">${(sale.quantity_kg/1000).toFixed(3)} MT</small></td></tr>
                            <tr><th class="text-muted">Unit Price</th>
                                <td>Rp ${fmt(sale.unit_price)}/kg</td></tr>
                            <tr><th class="text-muted">Total Amount</th>
                                <td class="fw-bold text-success">Rp ${fmt(sale.total_amount)}</td></tr>
                            <tr><th class="text-muted">Currency</th>
                                <td>${sale.currency || 'IDR'}</td></tr>
                            <tr><th class="text-muted">Payment</th>
                                <td><span class="badge bg-${sc}">${sale.payment_status}</span>
                                    <br><small class="text-muted">${sale.payment_terms || ''}</small></td></tr>
                            <tr><th class="text-muted">Paid Date</th>
                                <td>${fmtd(sale.payment_date)}</td></tr>
                        </table>
                    </div>
                    <div class="col-12">
                        <hr class="my-1">
                        <table class="table table-sm table-borderless mb-0">
                            <tr><th class="text-muted" style="width:20%">Delivery</th>
                                <td>${sale.delivery_location || '-'}</td>
                                <th class="text-muted">Delivery Date</th>
                                <td>${fmtd(sale.delivery_date)}</td></tr>
                            ${sale.notes ? `<tr><th class="text-muted">Notes</th><td colspan="3">${sale.notes}</td></tr>` : ''}
                        </table>
                    </div>
                </div>`;
            new bootstrap.Modal(document.getElementById('viewSaleModal')).show();
        });
    });

    // ── Switch from View → Edit ───────────────────────────────────────────────
    document.getElementById('viewToEditBtn').addEventListener('click', function () {
        bootstrap.Modal.getInstance(document.getElementById('viewSaleModal')).hide();
        if (currentSale) openEditModal(currentSale);
    });

    // ── Edit modal ────────────────────────────────────────────────────────────
    function openEditModal(sale) {
        document.getElementById('formAction').value = 'update_sale';
        document.getElementById('saleModalTitle').innerHTML = '<i class="bi bi-pencil"></i> Edit Sale #' + sale.sale_id;
        document.getElementById('formSubmitBtn').innerHTML  = '<i class="bi bi-save"></i> Update Sale';
        fillForm(sale);
        new bootstrap.Modal(document.getElementById('createSaleModal')).show();
    }

    document.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', function () {
            openEditModal(JSON.parse(this.dataset.sale));
        });
    });

    // ── Reset modal to Create mode on close ───────────────────────────────────
    document.getElementById('createSaleModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('formAction').value = 'create_sale';
        document.getElementById('formSaleId').value = '';
        document.getElementById('saleModalTitle').innerHTML = '<i class="bi bi-plus-circle"></i> New Sale';
        document.getElementById('formSubmitBtn').innerHTML  = '<i class="bi bi-save"></i> Save';
        document.getElementById('saleForm').reset();
        document.getElementById('f_qty_mt').textContent = '0.000 MT';
        document.getElementById('f_total').textContent  = 'Rp 0';
        togglePayDate();
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
