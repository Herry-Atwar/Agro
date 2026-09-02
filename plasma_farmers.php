<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db  = getDB();
$page_title = "Plasma Farmers";

// ─── Auto-migrate ─────────────────────────────────────────────────────────────
(function (PDO $db) {
    if ($db->query("SHOW TABLES LIKE 'plasma_farmers'")->fetchColumn()) {
        // Ensure division_id column exists on older installations
        $hasDivision = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'plasma_farmers'
                AND COLUMN_NAME  = 'division_id'"
        )->fetchColumn();
        if (!$hasDivision) {
            $db->exec("ALTER TABLE plasma_farmers ADD COLUMN division_id BIGINT UNSIGNED NULL AFTER business_unit_id");
        }
        return;
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS plasma_farmers (
            id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
            farmer_code         VARCHAR(30)     NOT NULL UNIQUE,
            farmer_name         VARCHAR(200)    NOT NULL,
            id_number           VARCHAR(50)     NULL,
            phone               VARCHAR(20)     NULL,
            address             TEXT            NULL,
            village             VARCHAR(100)    NULL,
            district            VARCHAR(100)    NULL,
            regency             VARCHAR(100)    NULL,
            province            VARCHAR(100)    NULL,
            company_id          BIGINT UNSIGNED NOT NULL,
            business_unit_id    BIGINT UNSIGNED NULL,
            division_id         BIGINT UNSIGNED NULL,
            kud_name            VARCHAR(200)    NULL,
            kud_member_no       VARCHAR(50)     NULL,
            land_area_ha        DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
            land_certificate    VARCHAR(100)    NULL,
            planting_year       INT             NULL,
            plant_variety       VARCHAR(100)    NULL,
            credit_total        DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
            credit_remaining    DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
            deduction_pct       DECIMAL(5,2)    NOT NULL DEFAULT 30.00,
            credit_start_date   DATE            NULL,
            credit_end_date     DATE            NULL,
            status              ENUM('active','inactive','graduated','exited') NOT NULL DEFAULT 'active',
            notes               TEXT            NULL,
            created_by          VARCHAR(50)     NULL,
            updated_by          VARCHAR(50)     NULL,
            created_at          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_company    (company_id),
            INDEX idx_bu         (business_unit_id),
            INDEX idx_division   (division_id),
            INDEX idx_status     (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
})($db);

// ─── Helper: generate next farmer code ───────────────────────────────────────
function next_farmer_code(PDO $db, int $bu_id): string {
    $prefix = 'PLM-' . str_pad($bu_id, 3, '0', STR_PAD_LEFT) . '-';
    $max = (int) $db->query(
        "SELECT MAX(CAST(SUBSTRING(farmer_code, " . (strlen($prefix) + 1) . ") AS UNSIGNED))
           FROM plasma_farmers WHERE farmer_code LIKE '$prefix%'"
    )->fetchColumn();
    return $prefix . str_pad($max + 1, 3, '0', STR_PAD_LEFT);
}

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'create') {
        try {
            $bu  = post('business_unit_id') ? (int) post('business_unit_id') : null;
            $div = post('division_id')       ? (int) post('division_id')       : null;
            $db->prepare("
                INSERT INTO plasma_farmers
                    (farmer_code, farmer_name, id_number, phone,
                     address, village, district, regency, province,
                     company_id, business_unit_id, division_id,
                     kud_name, kud_member_no,
                     land_area_ha, land_certificate, planting_year, plant_variety,
                     credit_total, credit_remaining, deduction_pct,
                     credit_start_date, credit_end_date,
                     status, notes, created_by)
                VALUES (?,?,?,?, ?,?,?,?,?, ?,?,?, ?,?, ?,?,?,?, ?,?,?, ?,?, ?,?,?)
            ")->execute([
                next_farmer_code($db, $bu ?? (int) post('company_id')),
                post('farmer_name'),
                post('id_number')  ?: null,
                post('phone')      ?: null,
                post('address')    ?: null,
                post('village')    ?: null,
                post('district')   ?: null,
                post('regency')    ?: null,
                post('province')   ?: null,
                (int) post('company_id'),
                $bu,
                $div,
                post('kud_name')        ?: null,
                post('kud_member_no')   ?: null,
                (float) post('land_area_ha') ?: 0,
                post('land_certificate')    ?: null,
                post('planting_year')       ?: null,
                post('plant_variety')       ?: null,
                (float) post('credit_total')     ?: 0,
                (float) post('credit_remaining') ?: 0,
                (float) post('deduction_pct')    ?: 30,
                post('credit_start_date') ?: null,
                post('credit_end_date')   ?: null,
                post('status') ?: 'active',
                post('notes')  ?: null,
                'admin',
            ]);
            set_message('success', 'Plasma farmer added successfully!');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('plasma_farmers.php');
    }

    if ($action === 'update') {
        try {
            $db->prepare("
                UPDATE plasma_farmers SET
                    farmer_name=?, id_number=?, phone=?,
                    address=?, village=?, district=?, regency=?, province=?,
                    company_id=?, business_unit_id=?, division_id=?,
                    kud_name=?, kud_member_no=?,
                    land_area_ha=?, land_certificate=?, planting_year=?, plant_variety=?,
                    credit_total=?, credit_remaining=?, deduction_pct=?,
                    credit_start_date=?, credit_end_date=?,
                    status=?, notes=?, updated_by='admin'
                WHERE id=?
            ")->execute([
                post('farmer_name'),
                post('id_number')  ?: null,
                post('phone')      ?: null,
                post('address')    ?: null,
                post('village')    ?: null,
                post('district')   ?: null,
                post('regency')    ?: null,
                post('province')   ?: null,
                (int) post('company_id'),
                post('business_unit_id') ? (int) post('business_unit_id') : null,
                post('division_id')       ? (int) post('division_id')       : null,
                post('kud_name')        ?: null,
                post('kud_member_no')   ?: null,
                (float) post('land_area_ha'),
                post('land_certificate')    ?: null,
                post('planting_year')       ?: null,
                post('plant_variety')       ?: null,
                (float) post('credit_total'),
                (float) post('credit_remaining'),
                (float) post('deduction_pct'),
                post('credit_start_date') ?: null,
                post('credit_end_date')   ?: null,
                post('status'),
                post('notes') ?: null,
                (int) post('farmer_id'),
            ]);
            set_message('success', 'Farmer record updated!');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect('plasma_farmers.php');
    }

    if ($action === 'delete') {
        $db->prepare("UPDATE plasma_farmers SET status='exited', updated_by='admin' WHERE id=?")
           ->execute([(int) post('farmer_id')]);
        set_message('success', 'Farmer marked as exited.');
        redirect('plasma_farmers.php');
    }
}

require_once 'includes/header.php';

// ─── Filters ─────────────────────────────────────────────────────────────────
$company_id = (int) get('company_id', 0);
$bu_id      = (int) get('business_unit_id', 0);
$div_id     = (int) get('division_id', 0);
$status_f   = get('status', '');
$kud_f      = get('kud', '');
$search     = get('search', '');

// ─── Summary stats ────────────────────────────────────────────────────────────
$stats = $db->query("
    SELECT COUNT(*)                                                  AS total,
           COALESCE(SUM(land_area_ha), 0)                           AS total_ha,
           COALESCE(SUM(credit_total), 0)                           AS credit_extended,
           COALESCE(SUM(credit_remaining), 0)                       AS credit_outstanding,
           SUM(CASE WHEN status='active'    THEN 1 ELSE 0 END)      AS active_count,
           SUM(CASE WHEN status='graduated' THEN 1 ELSE 0 END)      AS graduated_count,
           SUM(CASE WHEN credit_end_date < CURDATE()
               AND credit_remaining > 0 THEN 1 ELSE 0 END)         AS overdue_count
    FROM plasma_farmers
")->fetch();

// ─── Dropdowns ───────────────────────────────────────────────────────────────
$companies   = $db->query("SELECT company_id, company_name FROM companies ORDER BY company_name")->fetchAll();
$bus         = $db->query("SELECT business_unit_id, unit_name, company_id FROM business_units ORDER BY unit_name")->fetchAll();
$divs        = $db->query("
    SELECT d.division_id, d.division_code, d.division_name, bu.unit_code
    FROM divisions d
    LEFT JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    ORDER BY bu.unit_code, d.division_code
")->fetchAll();
$kudList     = $db->query("SELECT DISTINCT kud_name FROM plasma_farmers WHERE kud_name IS NOT NULL ORDER BY kud_name")->fetchAll(PDO::FETCH_COLUMN);

// ─── Main list ────────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];
if ($company_id) { $where[] = 'pf.company_id=?';        $params[] = $company_id; }
if ($bu_id)      { $where[] = 'pf.business_unit_id=?';  $params[] = $bu_id; }
if ($div_id)     { $where[] = 'pf.division_id=?';       $params[] = $div_id; }
if ($status_f)   { $where[] = 'pf.status=?';            $params[] = $status_f; }
if ($kud_f)      { $where[] = 'pf.kud_name=?';          $params[] = $kud_f; }
if ($search)     {
    $where[]  = '(pf.farmer_name LIKE ? OR pf.farmer_code LIKE ? OR pf.id_number LIKE ?)';
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

$stmt = $db->prepare("
    SELECT pf.*,
           co.company_name,
           bu.unit_name  AS estate_name,
           d.division_code, d.division_name,
           ROUND((pf.credit_total - pf.credit_remaining) / NULLIF(pf.credit_total, 0) * 100, 1) AS repaid_pct,
           CASE
               WHEN pf.credit_remaining <= 0                              THEN 'Clear'
               WHEN pf.credit_end_date < CURDATE()                        THEN 'Overdue'
               WHEN pf.credit_remaining / NULLIF(pf.credit_total, 0) > 0.80 THEN 'Early Stage'
               WHEN pf.credit_remaining / NULLIF(pf.credit_total, 0) > 0.40 THEN 'Mid Stage'
               ELSE 'Near Completion'
           END AS credit_stage
    FROM plasma_farmers pf
    JOIN  companies co         ON pf.company_id       = co.company_id
    LEFT JOIN business_units bu ON pf.business_unit_id = bu.business_unit_id
    LEFT JOIN divisions d       ON pf.division_id      = d.division_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY pf.status ASC, pf.farmer_code ASC
");
$stmt->execute($params);
$farmers = $stmt->fetchAll();
?>

<div class="content-wrapper">
    <!-- Page header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-people-fill"></i> Plasma Farmers</h1>
                <p class="text-muted mb-0">Smallholder master · land area · KUD · credit &amp; loan repayment</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                <i class="bi bi-plus-circle"></i> Add Farmer
            </button>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card stat-card text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Total Farmers</h6>
                    <h4 class="mb-0 text-primary"><?= $stats['total'] ?></h4>
                    <small class="text-muted"><?= $stats['active_count'] ?> active</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-success border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Total Plasma Area</h6>
                    <h5 class="mb-0 text-success"><?= number_format($stats['total_ha'], 2) ?> ha</h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-info border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Credit Extended</h6>
                    <h5 class="mb-0" style="color:#0891b2">Rp <?= number_format($stats['credit_extended'] / 1e6, 1) ?>M</h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-warning border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Outstanding Credit</h6>
                    <h5 class="mb-0 text-warning">Rp <?= number_format($stats['credit_outstanding'] / 1e6, 1) ?>M</h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-secondary border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Graduated</h6>
                    <h5 class="mb-0 text-secondary"><?= $stats['graduated_count'] ?></h5>
                    <small class="text-muted">credit fully repaid</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card border-start border-danger border-4 text-center">
                <div class="card-body py-3">
                    <h6 class="text-muted small mb-1">Overdue Credit</h6>
                    <h5 class="mb-0 text-danger"><?= $stats['overdue_count'] ?></h5>
                    <small class="text-muted">past target date</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <select name="company_id" class="form-select form-select-sm">
                        <option value="">All Companies</option>
                        <?php foreach ($companies as $co): ?>
                        <option value="<?= $co['company_id'] ?>" <?= $company_id == $co['company_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($co['company_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="business_unit_id" class="form-select form-select-sm">
                        <option value="">All Estates</option>
                        <?php foreach ($bus as $b): ?>
                        <option value="<?= $b['business_unit_id'] ?>" <?= $bu_id == $b['business_unit_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['unit_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="division_id" class="form-select form-select-sm">
                        <option value="">All Divisions</option>
                        <?php foreach ($divs as $dv): ?>
                        <option value="<?= $dv['division_id'] ?>" <?= $div_id == $dv['division_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dv['unit_code'] . ' / ' . $dv['division_code']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'graduated' => 'Graduated', 'exited' => 'Exited'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $status_f === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Name / code / NIK…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm me-1"><i class="bi bi-search"></i> Filter</button>
                    <a href="plasma_farmers.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-header"><i class="bi bi-table"></i> Plasma Farmers (<?= count($farmers) ?>)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Farmer Name</th>
                            <th>Estate / Division</th>
                            <th>KUD</th>
                            <th class="text-end">Land (ha)</th>
                            <th>Plant Year</th>
                            <th class="text-end">Credit Total</th>
                            <th class="text-end">Outstanding</th>
                            <th>Repaid</th>
                            <th>Deduction</th>
                            <th>Credit Status</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($farmers)): ?>
                        <tr><td colspan="13" class="text-center text-muted py-4">No farmers found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($farmers as $f):
                            $creditStageColor = [
                                'Clear'           => 'success',
                                'Overdue'         => 'danger',
                                'Early Stage'     => 'secondary',
                                'Mid Stage'       => 'info',
                                'Near Completion' => 'warning',
                            ][$f['credit_stage']] ?? 'secondary';
                            $statusColor = ['active' => 'success', 'inactive' => 'secondary', 'graduated' => 'primary', 'exited' => 'danger'][$f['status']] ?? 'secondary';
                            $repaid   = (float) $f['repaid_pct'];
                            $barColor = $repaid >= 80 ? '#22c55e' : ($repaid >= 40 ? '#f59e0b' : '#ef4444');
                        ?>
                        <tr>
                            <td><code><?= htmlspecialchars($f['farmer_code']) ?></code></td>
                            <td>
                                <strong><?= htmlspecialchars($f['farmer_name']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars(trim($f['village'] . ', ' . $f['district'], ', ')) ?></small>
                            </td>
                            <td>
                                <small><?= htmlspecialchars($f['estate_name'] ?? '—') ?></small>
                                <?php if ($f['division_code']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($f['division_code']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?= htmlspecialchars($f['kud_name'] ?? '—') ?></small><br>
                                <small class="text-muted"><?= htmlspecialchars($f['kud_member_no'] ?? '') ?></small>
                            </td>
                            <td class="text-end"><?= number_format($f['land_area_ha'], 4) ?></td>
                            <td class="text-center"><?= $f['planting_year'] ?: '—' ?></td>
                            <td class="text-end">Rp <?= number_format($f['credit_total'], 0) ?></td>
                            <td class="text-end <?= $f['credit_remaining'] > 0 ? 'fw-bold text-warning' : '' ?>">
                                Rp <?= number_format($f['credit_remaining'], 0) ?>
                            </td>
                            <td style="min-width:90px">
                                <div style="background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden">
                                    <div style="width:<?= min(100, $repaid) ?>%;background:<?= $barColor ?>;height:100%"></div>
                                </div>
                                <small class="text-muted"><?= $repaid ?>%</small>
                            </td>
                            <td class="text-center"><span class="badge bg-secondary"><?= $f['deduction_pct'] ?>%</span></td>
                            <td><span class="badge bg-<?= $creditStageColor ?>"><?= $f['credit_stage'] ?></span></td>
                            <td><span class="badge bg-<?= $statusColor ?>"><?= ucfirst($f['status']) ?></span></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary me-1"
                                        data-bs-toggle="modal" data-bs-target="#editModal"
                                        data-farmer='<?= htmlspecialchars(json_encode($f), ENT_QUOTES) ?>'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ($f['status'] !== 'exited'): ?>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Mark this farmer as exited from the plasma scheme?')">
                                    <input type="hidden" name="action"    value="delete">
                                    <input type="hidden" name="farmer_id" value="<?= $f['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-box-arrow-right"></i>
                                    </button>
                                </form>
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
</div>

<?php
// ─── Build option strings for modals ─────────────────────────────────────────
$co_opts = '';
foreach ($companies as $co)
    $co_opts .= "<option value='{$co['company_id']}'>" . htmlspecialchars($co['company_name']) . "</option>";

$bu_opts = '';
foreach ($bus as $b)
    $bu_opts .= "<option value='{$b['business_unit_id']}' data-company='{$b['company_id']}'>"
             . htmlspecialchars($b['unit_name']) . "</option>";

$div_opts = '';
foreach ($divs as $dv)
    $div_opts .= "<option value='{$dv['division_id']}' data-bu='{$dv['division_id']}'>"
              . htmlspecialchars($dv['unit_code'] . ' / ' . $dv['division_code'] . ' – ' . $dv['division_name'])
              . "</option>";

// ─── Render form fields helper (shared between create & edit modals) ──────────
function render_farmer_form(string $pfx, string $co_opts, string $bu_opts, string $div_opts): string {
    $req = '<span class="text-danger">*</span>';
    return <<<HTML
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Full Name {$req}</label>
        <input type="text" name="farmer_name" id="{$pfx}_farmer_name" class="form-control" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">NIK / KTP</label>
        <input type="text" name="id_number" id="{$pfx}_id_number" class="form-control" maxlength="20">
      </div>
      <div class="col-md-4">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" id="{$pfx}_phone" class="form-control">
      </div>

      <div class="col-md-4">
        <label class="form-label">Company {$req}</label>
        <select name="company_id" id="{$pfx}_company_id" class="form-select" required>
          <option value="">— Select —</option>{$co_opts}
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Nucleus Estate (BU)</label>
        <select name="business_unit_id" id="{$pfx}_business_unit_id" class="form-select">
          <option value="">— None —</option>{$bu_opts}
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Division (Afdeling)</label>
        <select name="division_id" id="{$pfx}_division_id" class="form-select">
          <option value="">— None —</option>{$div_opts}
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Status</label>
        <select name="status" id="{$pfx}_status" class="form-select">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="graduated">Graduated</option>
          <option value="exited">Exited</option>
        </select>
      </div>

      <div class="col-md-3"><label class="form-label">Village (Desa)</label><input type="text" name="village" id="{$pfx}_village" class="form-control"></div>
      <div class="col-md-3"><label class="form-label">District (Kecamatan)</label><input type="text" name="district" id="{$pfx}_district" class="form-control"></div>
      <div class="col-md-3"><label class="form-label">Regency (Kabupaten)</label><input type="text" name="regency" id="{$pfx}_regency" class="form-control"></div>
      <div class="col-md-3"><label class="form-label">Province</label><input type="text" name="province" id="{$pfx}_province" class="form-control"></div>
      <div class="col-12"><label class="form-label">Address</label><textarea name="address" id="{$pfx}_address" class="form-control" rows="2"></textarea></div>

      <div class="col-md-4">
        <label class="form-label">KUD Name</label>
        <input type="text" name="kud_name" id="{$pfx}_kud_name" class="form-control">
      </div>
      <div class="col-md-4">
        <label class="form-label">KUD Member No.</label>
        <input type="text" name="kud_member_no" id="{$pfx}_kud_member_no" class="form-control">
      </div>
      <div class="col-md-4">
        <label class="form-label">Land Certificate No.</label>
        <input type="text" name="land_certificate" id="{$pfx}_land_certificate" class="form-control">
      </div>

      <div class="col-md-3"><label class="form-label">Land Area (ha) {$req}</label><input type="number" name="land_area_ha" id="{$pfx}_land_area_ha" class="form-control" step="0.0001" min="0" required></div>
      <div class="col-md-3"><label class="form-label">Planting Year</label><input type="number" name="planting_year" id="{$pfx}_planting_year" class="form-control" min="1980" max="2035" placeholder="e.g. 2008"></div>
      <div class="col-md-6"><label class="form-label">Plant Variety / Species</label><input type="text" name="plant_variety" id="{$pfx}_plant_variety" class="form-control" placeholder="e.g. DxP Tenera"></div>

      <div class="col-12"><hr class="my-1"><h6 class="text-muted small text-uppercase fw-bold">Credit / Loan from Inti</h6></div>
      <div class="col-md-4"><label class="form-label">Total Credit Extended (IDR)</label><input type="number" name="credit_total" id="{$pfx}_credit_total" class="form-control" step="1" min="0" value="0"></div>
      <div class="col-md-4"><label class="form-label">Outstanding Balance (IDR)</label><input type="number" name="credit_remaining" id="{$pfx}_credit_remaining" class="form-control" step="1" min="0" value="0"></div>
      <div class="col-md-4"><label class="form-label">Deduction % (per FFB payment)</label><input type="number" name="deduction_pct" id="{$pfx}_deduction_pct" class="form-control" step="0.01" min="0" max="100" value="30"></div>
      <div class="col-md-4"><label class="form-label">Credit Start Date</label><input type="date" name="credit_start_date" id="{$pfx}_credit_start_date" class="form-control"></div>
      <div class="col-md-4"><label class="form-label">Expected Full Repayment Date</label><input type="date" name="credit_end_date" id="{$pfx}_credit_end_date" class="form-control"></div>
      <div class="col-md-4"></div>

      <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" id="{$pfx}_notes" class="form-control" rows="2"></textarea></div>
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
          <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add Plasma Farmer</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php echo render_farmer_form('c', $co_opts, $bu_opts, $div_opts); ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Add Farmer</button>
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
        <input type="hidden" name="action"    value="update">
        <input type="hidden" name="farmer_id" id="e_farmer_id">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Plasma Farmer — <span id="e_farmer_code_label"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php echo render_farmer_form('e', $co_opts, $bu_opts, $div_opts); ?>
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
document.getElementById('editModal').addEventListener('show.bs.modal', function(e) {
    var f = JSON.parse(e.relatedTarget.dataset.farmer);
    document.getElementById('e_farmer_id').value = f.id;
    document.getElementById('e_farmer_code_label').textContent = f.farmer_code;
    var textFields = ['farmer_name','id_number','phone','address','village','district','regency','province',
                      'kud_name','kud_member_no','land_certificate','land_area_ha','planting_year','plant_variety',
                      'credit_total','credit_remaining','deduction_pct','credit_start_date','credit_end_date','notes'];
    textFields.forEach(function(fld) {
        var el = document.getElementById('e_' + fld);
        if (el) el.value = f[fld] || '';
    });
    // Select fields
    ['company_id','business_unit_id','division_id','status'].forEach(function(fld) {
        var sel = document.getElementById('e_' + fld);
        if (!sel) return;
        for (var i = 0; i < sel.options.length; i++) {
            if (String(sel.options[i].value) === String(f[fld] || '')) {
                sel.selectedIndex = i;
                break;
            }
        }
    });
});
</script>
JS;
require_once 'includes/footer.php';
?>
