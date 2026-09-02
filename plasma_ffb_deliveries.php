<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db         = getDB();
$page_title = __('pt_plasma_ffb');

// ─── Auto-migrate ─────────────────────────────────────────────────────────────
(function (PDO $db) {
    if ($db->query("SHOW TABLES LIKE 'plasma_ffb_deliveries'")->fetchColumn()) return;
    $db->exec("
        CREATE TABLE IF NOT EXISTS plasma_ffb_deliveries (
            id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
            delivery_no       VARCHAR(30)     NOT NULL UNIQUE,
            farmer_id         INT UNSIGNED    NOT NULL,
            delivery_date     DATE            NOT NULL,
            vehicle_no        VARCHAR(30)     NULL,
            driver_name       VARCHAR(100)    NULL,
            gross_weight_kg   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            tare_weight_kg    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            net_weight_kg     DECIMAL(10,2)   GENERATED ALWAYS AS (gross_weight_kg - tare_weight_kg) STORED,
            bunch_count       INT             NULL,
            quality_grade     ENUM('Premium','Grade A','Grade B','Grade C','Reject') NOT NULL DEFAULT 'Grade A',
            ripeness          ENUM('Under Ripe','Ripe','Over Ripe') NOT NULL DEFAULT 'Ripe',
            destination_mill  VARCHAR(150)    NULL,
            received_by       VARCHAR(100)    NULL,
            weighbridge_ref   VARCHAR(50)     NULL,
            status            ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
            rejection_reason  TEXT            NULL,
            notes             TEXT            NULL,
            created_by        VARCHAR(50)     NULL,
            updated_by        VARCHAR(50)     NULL,
            created_at        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            updated_at        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_farmer (farmer_id),
            INDEX idx_date   (delivery_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
})($db);

// ─── Helper: next delivery number ─────────────────────────────────────────────
function next_delivery_no(PDO $db): string {
    $yr  = date('Y');
    $pfx = "PFFB-$yr-";
    $max = (int) $db->query("SELECT MAX(CAST(SUBSTRING(delivery_no, " . (strlen($pfx) + 1) . ") AS UNSIGNED))
                              FROM plasma_ffb_deliveries WHERE delivery_no LIKE '$pfx%'")->fetchColumn();
    return $pfx . str_pad($max + 1, 5, '0', STR_PAD_LEFT);
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'create') {
        try {
            $db->prepare("
                INSERT INTO plasma_ffb_deliveries
                    (delivery_no, farmer_id, delivery_date,
                     vehicle_no, driver_name,
                     gross_weight_kg, tare_weight_kg,
                     bunch_count, quality_grade, ripeness,
                     destination_mill, received_by, weighbridge_ref,
                     status, rejection_reason, notes, created_by)
                VALUES (?,?,?, ?,?, ?,?, ?,?,?, ?,?,?, ?,?,?,?)
            ")->execute([
                next_delivery_no($db),
                (int)   post('farmer_id'),
                        post('delivery_date'),
                        post('vehicle_no')        ?: null,
                        post('driver_name')        ?: null,
                (float) post('gross_weight_kg'),
                (float) post('tare_weight_kg'),
                        post('bunch_count')        ? (int)post('bunch_count') : null,
                        post('quality_grade')      ?: 'Grade A',
                        post('ripeness')           ?: 'Ripe',
                        post('destination_mill')   ?: null,
                        post('received_by')        ?: null,
                        post('weighbridge_ref')    ?: null,
                        post('status')             ?: 'pending',
                        post('rejection_reason')   ?: null,
                        post('notes')              ?: null,
                'admin',
            ]);
            set_message('success', 'FFB delivery recorded successfully!');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('plasma_ffb_deliveries.php?' . http_build_query(array_filter([
            'farmer_id'  => get('farmer_id'),
            'date_from'  => get('date_from'),
            'date_to'    => get('date_to'),
        ])));
    }

    if ($action === 'update') {
        try {
            $db->prepare("
                UPDATE plasma_ffb_deliveries SET
                    farmer_id=?, delivery_date=?,
                    vehicle_no=?, driver_name=?,
                    gross_weight_kg=?, tare_weight_kg=?,
                    bunch_count=?, quality_grade=?, ripeness=?,
                    destination_mill=?, received_by=?, weighbridge_ref=?,
                    status=?, rejection_reason=?, notes=?, updated_by='admin'
                WHERE id=?
            ")->execute([
                (int)   post('farmer_id'),
                        post('delivery_date'),
                        post('vehicle_no')       ?: null,
                        post('driver_name')      ?: null,
                (float) post('gross_weight_kg'),
                (float) post('tare_weight_kg'),
                        post('bunch_count')      ? (int)post('bunch_count') : null,
                        post('quality_grade')    ?: 'Grade A',
                        post('ripeness')         ?: 'Ripe',
                        post('destination_mill') ?: null,
                        post('received_by')      ?: null,
                        post('weighbridge_ref')  ?: null,
                        post('status')           ?: 'pending',
                        post('rejection_reason') ?: null,
                        post('notes')            ?: null,
                (int)   post('delivery_id'),
            ]);
            set_message('success', 'Delivery updated.');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('plasma_ffb_deliveries.php');
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM plasma_ffb_deliveries WHERE id=?")->execute([(int)post('delivery_id')]);
        set_message('success', 'Delivery record deleted.');
        redirect('plasma_ffb_deliveries.php');
    }
}

require_once 'includes/header.php';

// ─── Filters ─────────────────────────────────────────────────────────────────
$farmer_id  = (int)   get('farmer_id', 0);
$status_f   = get('status',    '');
$grade_f    = get('grade',     '');
$date_from  = get('date_from', date('Y-01-01'));
$date_to    = get('date_to',   date('Y-m-d'));
$search     = get('search',    '');

// ─── Summary stats ────────────────────────────────────────────────────────────
$sw    = $farmer_id ? "AND d.farmer_id=$farmer_id" : '';
$stats = $db->query("
    SELECT COUNT(*)                                                     AS total,
           COALESCE(SUM(d.net_weight_kg),0)                            AS total_net_kg,
           COALESCE(SUM(CASE WHEN d.status='accepted' THEN d.net_weight_kg ELSE 0 END),0) AS accepted_kg,
           SUM(CASE WHEN d.status='rejected' THEN 1 ELSE 0 END)        AS rejected_count,
           SUM(CASE WHEN d.status='pending'  THEN 1 ELSE 0 END)        AS pending_count
    FROM plasma_ffb_deliveries d WHERE 1=1 $sw
")->fetch();

// ─── Dropdowns ───────────────────────────────────────────────────────────────
$farmers = $db->query("
    SELECT pf.id, pf.farmer_code, pf.farmer_name, bu.unit_name AS estate_name
    FROM plasma_farmers pf
    LEFT JOIN business_units bu ON pf.business_unit_id = bu.business_unit_id
    WHERE pf.status IN ('active','graduated')
    ORDER BY pf.farmer_code
")->fetchAll();

// ─── Main list ────────────────────────────────────────────────────────────────
$where  = ['d.delivery_date BETWEEN ? AND ?'];
$params = [$date_from, $date_to];
if ($farmer_id) { $where[] = 'd.farmer_id=?';      $params[] = $farmer_id; }
if ($status_f)  { $where[] = 'd.status=?';         $params[] = $status_f; }
if ($grade_f)   { $where[] = 'd.quality_grade=?';  $params[] = $grade_f; }
if ($search)    { $where[] = '(pf.farmer_name LIKE ? OR d.delivery_no LIKE ? OR d.vehicle_no LIKE ?)';
                  $params  = array_merge($params, ["%$search%","%$search%","%$search%"]); }

$stmt = $db->prepare("
    SELECT d.*,
           pf.farmer_code, pf.farmer_name, pf.kud_name,
           bu.unit_name AS estate_name
    FROM plasma_ffb_deliveries d
    JOIN  plasma_farmers  pf ON d.farmer_id          = pf.id
    LEFT JOIN business_units bu ON pf.business_unit_id = bu.business_unit_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY d.delivery_date DESC, d.id DESC
");
$stmt->execute($params);
$deliveries = $stmt->fetchAll();

$farmer_opts = '';
foreach ($farmers as $f)
    $farmer_opts .= "<option value='{$f['id']}'>[{$f['farmer_code']}] " . htmlspecialchars($f['farmer_name']) . ($f['estate_name'] ? ' — ' . htmlspecialchars($f['estate_name']) : '') . "</option>";
?>

<div class="content-wrapper">
    <!-- Page header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-truck"></i> Plasma FFB Deliveries</h1>
                <p class="text-muted mb-0">Record smallholder FFB trips to the mill — weight, quality, acceptance status</p>
            </div>
            <div class="d-flex gap-2">
                <a href="plasma_payments.php" class="btn btn-outline-success">
                    <i class="bi bi-receipt"></i> Payment Statements
                </a>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                    <i class="bi bi-plus-circle"></i> Record Delivery
                </button>
            </div>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card stat-card text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Total Deliveries</h6>
                    <h4 class="mb-0 text-primary"><?= $stats['total'] ?></h4>
                    <small class="text-muted"><?= $stats['pending_count'] ?> pending</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-start border-success border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Accepted Net Weight</h6>
                    <h5 class="mb-0 text-success"><?= number_format($stats['accepted_kg'] / 1000, 2) ?> ton</h5>
                    <small class="text-muted"><?= number_format($stats['accepted_kg'], 0) ?> kg</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-start border-info border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Total Net Weight (All)</h6>
                    <h5 class="mb-0" style="color:#0891b2"><?= number_format($stats['total_net_kg'] / 1000, 2) ?> ton</h5>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-start border-danger border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Rejected Deliveries</h6>
                    <h5 class="mb-0 text-danger"><?= $stats['rejected_count'] ?></h5>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Date From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= $date_from ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Date To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= $date_to ?>">
                </div>
                <div class="col-md-2">
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
                        <option value="pending"  <?= $status_f==='pending'  ?'selected':'' ?>>Pending</option>
                        <option value="accepted" <?= $status_f==='accepted' ?'selected':'' ?>>Accepted</option>
                        <option value="rejected" <?= $status_f==='rejected' ?'selected':'' ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="grade" class="form-select form-select-sm">
                        <option value="">All Grades</option>
                        <?php foreach (['Premium','Grade A','Grade B','Grade C','Reject'] as $g): ?>
                            <option value="<?= $g ?>" <?= $grade_f===$g?'selected':'' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" class="form-control" placeholder="Name / code / plate…" value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                        <a href="plasma_ffb_deliveries.php" class="btn btn-outline-secondary">✕</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table"></i> Deliveries (<?= count($deliveries) ?>) — <?= $date_from ?> → <?= $date_to ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Delivery No</th>
                            <th>Date</th>
                            <th>Farmer</th>
                            <th>Estate / KUD</th>
                            <th class="text-end">Gross (kg)</th>
                            <th class="text-end">Tare (kg)</th>
                            <th class="text-end">Net (kg)</th>
                            <th>Grade</th>
                            <th>Ripeness</th>
                            <th>Vehicle</th>
                            <th>Weighbridge</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deliveries)): ?>
                            <tr><td colspan="13" class="text-center text-muted py-4">No deliveries found for this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($deliveries as $d):
                                $stColor = ['pending'=>'warning','accepted'=>'success','rejected'=>'danger'][$d['status']] ?? 'secondary';
                                $grColor = ['Premium'=>'primary','Grade A'=>'success','Grade B'=>'info','Grade C'=>'warning','Reject'=>'danger'][$d['quality_grade']] ?? 'secondary';
                            ?>
                            <tr>
                                <td><code><?= htmlspecialchars($d['delivery_no']) ?></code></td>
                                <td><?= date('d/m/Y', strtotime($d['delivery_date'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($d['farmer_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($d['farmer_code']) ?></small>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($d['estate_name'] ?? '—') ?></small><br>
                                    <small class="text-muted"><?= htmlspecialchars($d['kud_name'] ?? '') ?></small>
                                </td>
                                <td class="text-end"><?= number_format($d['gross_weight_kg'], 2) ?></td>
                                <td class="text-end text-muted"><?= number_format($d['tare_weight_kg'], 2) ?></td>
                                <td class="text-end fw-bold"><?= number_format($d['net_weight_kg'], 2) ?></td>
                                <td><span class="badge bg-<?= $grColor ?>"><?= htmlspecialchars($d['quality_grade']) ?></span></td>
                                <td><small><?= htmlspecialchars($d['ripeness']) ?></small></td>
                                <td>
                                    <small><?= htmlspecialchars($d['vehicle_no'] ?? '—') ?></small><br>
                                    <small class="text-muted"><?= htmlspecialchars($d['driver_name'] ?? '') ?></small>
                                </td>
                                <td><small class="text-muted"><?= htmlspecialchars($d['weighbridge_ref'] ?? '—') ?></small></td>
                                <td><span class="badge bg-<?= $stColor ?>"><?= ucfirst($d['status']) ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary me-1"
                                            data-bs-toggle="modal" data-bs-target="#editModal"
                                            data-delivery='<?= htmlspecialchars(json_encode($d), ENT_QUOTES) ?>'>
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete this delivery record?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="delivery_id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($deliveries)):
                        $totalNet = array_sum(array_column($deliveries, 'net_weight_kg'));
                    ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="6" class="text-end">Period Total Net Weight:</td>
                            <td class="text-end"><?= number_format($totalNet, 2) ?> kg</td>
                            <td colspan="6"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
// ─── Shared form partial ──────────────────────────────────────────────────────
function render_delivery_form(string $pfx, string $farmer_opts): string {
    $req = '<span class="text-danger">*</span>';
    return <<<HTML
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Farmer $req</label>
        <select name="farmer_id" id="{$pfx}_farmer_id" class="form-select" required>
          <option value="">— Select Farmer —</option>$farmer_opts
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Delivery Date $req</label>
        <input type="date" name="delivery_date" id="{$pfx}_delivery_date" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Status</label>
        <select name="status" id="{$pfx}_status" class="form-select">
          <option value="pending">Pending</option>
          <option value="accepted">Accepted</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Gross Weight (kg) $req</label>
        <input type="number" name="gross_weight_kg" id="{$pfx}_gross_weight_kg"
               class="form-control" step="0.01" min="0" required
               oninput="calcNet('{$pfx}')">
      </div>
      <div class="col-md-4">
        <label class="form-label">Tare Weight (kg) $req</label>
        <input type="number" name="tare_weight_kg" id="{$pfx}_tare_weight_kg"
               class="form-control" step="0.01" min="0" required
               oninput="calcNet('{$pfx}')">
      </div>
      <div class="col-md-4">
        <label class="form-label">Net Weight (kg)</label>
        <input type="text" id="{$pfx}_net_display" class="form-control bg-light" readonly placeholder="Auto-calculated">
      </div>

      <div class="col-md-4">
        <label class="form-label">Quality Grade</label>
        <select name="quality_grade" id="{$pfx}_quality_grade" class="form-select">
          <option>Premium</option><option selected>Grade A</option>
          <option>Grade B</option><option>Grade C</option><option>Reject</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Ripeness</label>
        <select name="ripeness" id="{$pfx}_ripeness" class="form-select">
          <option>Under Ripe</option><option selected>Ripe</option><option>Over Ripe</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Bunch Count</label>
        <input type="number" name="bunch_count" id="{$pfx}_bunch_count" class="form-control" min="0">
      </div>

      <div class="col-md-4">
        <label class="form-label">Vehicle Plate No.</label>
        <input type="text" name="vehicle_no" id="{$pfx}_vehicle_no" class="form-control">
      </div>
      <div class="col-md-4">
        <label class="form-label">Driver Name</label>
        <input type="text" name="driver_name" id="{$pfx}_driver_name" class="form-control">
      </div>
      <div class="col-md-4">
        <label class="form-label">Destination Mill</label>
        <input type="text" name="destination_mill" id="{$pfx}_destination_mill" class="form-control">
      </div>

      <div class="col-md-4">
        <label class="form-label">Received By (Mill)</label>
        <input type="text" name="received_by" id="{$pfx}_received_by" class="form-control">
      </div>
      <div class="col-md-4">
        <label class="form-label">Weighbridge Ref / Docket</label>
        <input type="text" name="weighbridge_ref" id="{$pfx}_weighbridge_ref" class="form-control">
      </div>
      <div class="col-md-4"></div>

      <div class="col-12" id="{$pfx}_rejection_row">
        <label class="form-label">Rejection Reason</label>
        <textarea name="rejection_reason" id="{$pfx}_rejection_reason" class="form-control" rows="2"></textarea>
      </div>
      <div class="col-12">
        <label class="form-label">Notes</label>
        <textarea name="notes" id="{$pfx}_notes" class="form-control" rows="2"></textarea>
      </div>
    </div>
HTML;
}
?>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-truck"></i> Record FFB Delivery</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php echo render_delivery_form('c', $farmer_opts); ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Delivery</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="delivery_id" id="e_delivery_id">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Delivery — <span id="e_delivery_no_label"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php echo render_delivery_form('e', $farmer_opts); ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
function calcNet(pfx) {
    var g = parseFloat(document.getElementById(pfx + '_gross_weight_kg').value) || 0;
    var t = parseFloat(document.getElementById(pfx + '_tare_weight_kg').value) || 0;
    var n = document.getElementById(pfx + '_net_display');
    if (n) n.value = (g - t).toFixed(2);
}

// Populate edit modal
document.getElementById('editModal').addEventListener('show.bs.modal', function(e) {
    var d = JSON.parse(e.relatedTarget.dataset.delivery);
    document.getElementById('e_delivery_id').value = d.id;
    document.getElementById('e_delivery_no_label').textContent = d.delivery_no;

    var fields = ['delivery_date','gross_weight_kg','tare_weight_kg','bunch_count',
                  'vehicle_no','driver_name','destination_mill','received_by',
                  'weighbridge_ref','rejection_reason','notes'];
    fields.forEach(function(fld) {
        var el = document.getElementById('e_' + fld);
        if (!el) return;
        // Flatpickr wraps date inputs — use its API so the visible field updates
        if (el._flatpickr) {
            el._flatpickr.setDate(d[fld] || '', false, 'Y-m-d');
        } else {
            el.value = d[fld] || '';
        }
    });
    ['farmer_id','quality_grade','ripeness','status'].forEach(function(fld) {
        var sel = document.getElementById('e_' + fld);
        if (!sel) return;
        for (var i = 0; i < sel.options.length; i++)
            if (String(sel.options[i].value) === String(d[fld] || '')) { sel.selectedIndex = i; break; }
    });
    calcNet('e');
});
</script>
JS;
require_once 'includes/footer.php';
?>
