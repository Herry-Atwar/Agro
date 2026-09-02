<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// ── Resolve active scope filter ──────────────────────────────────────────────
// Rules:
//   • If user has a non-null session value → locked (cannot change)
//   • If user has null session value       → use GET param (user can pick)
// The three levels cascade: BU list filtered by company, division by BU.

$sess_company = $_SESSION['company_id']       ?? null;  // int or null
$sess_bu      = $_SESSION['business_unit_id'] ?? null;  // int or null
$sess_div     = $_SESSION['division_id']      ?? null;  // int or null

// Effective filter values
$f_company = $sess_company !== null ? (int)$sess_company : (isset($_GET['company_id'])  && $_GET['company_id']  !== '' ? (int)$_GET['company_id']  : null);
$f_bu      = $sess_bu      !== null ? (int)$sess_bu      : (isset($_GET['business_unit_id']) && $_GET['business_unit_id'] !== '' ? (int)$_GET['business_unit_id'] : null);
$f_div     = $sess_div     !== null ? (int)$sess_div     : (isset($_GET['division_id']) && $_GET['division_id'] !== '' ? (int)$_GET['division_id'] : null);

// Locked flags
$lock_company = ($sess_company !== null);
$lock_bu      = ($sess_bu      !== null);
$lock_div     = ($sess_div     !== null);

// ── Dropdown options for unlocked filters ────────────────────────────────────
$companies_opts = $db->query("SELECT company_id, company_name FROM companies WHERE status='Active' ORDER BY company_name")->fetchAll();
$bus_opts = $db->query("SELECT business_unit_id, unit_name, company_id FROM business_units WHERE status='Active' ORDER BY unit_name")->fetchAll();
$div_opts = $db->query("SELECT division_id, division_name, business_unit_id FROM divisions WHERE status='Active' ORDER BY division_name")->fetchAll();

// JSON for cascade JS (only needed when at least one filter is unlocked)
$bus_json = json_encode(array_map(fn($r)=>['id'=>(int)$r['business_unit_id'],'name'=>$r['unit_name'],'company_id'=>(int)$r['company_id']], $bus_opts));
$div_json = json_encode(array_map(fn($r)=>['id'=>(int)$r['division_id'],'name'=>$r['division_name'],'bu_id'=>(int)$r['business_unit_id']], $div_opts));

// ── Helper: build WHERE clause + params for blocks queries ───────────────────
function blocks_scope($f_company, $f_bu, $f_div): array {
    $where = []; $params = [];
    if ($f_company !== null) { $where[] = 'b.company_id = ?';       $params[] = $f_company; }
    if ($f_bu      !== null) { $where[] = 'b.business_unit_id = ?'; $params[] = $f_bu; }
    if ($f_div     !== null) { $where[] = 'b.division_id = ?';      $params[] = $f_div; }
    return [empty($where) ? '' : ('AND ' . implode(' AND ', $where)), $params];
}

function bu_scope($f_company): array {
    if ($f_company === null) return ['', []];
    return ['AND company_id = ?', [$f_company]];
}

function div_scope($f_bu): array {
    if ($f_bu === null) return ['', []];
    return ['AND d.business_unit_id = ?', [$f_bu]];
}

[$bw, $bp] = blocks_scope($f_company, $f_bu, $f_div);
[$buw, $bup] = bu_scope($f_company);
[$dw, $dp] = div_scope($f_bu);

// ── Stats ────────────────────────────────────────────────────────────────────
$stats = [];

// Companies count (always global or scoped by company)
$stmt = $db->prepare("SELECT COUNT(*) as total FROM companies WHERE status = 'Active'" . ($f_company ? " AND company_id = ?" : ""));
$stmt->execute($f_company ? [$f_company] : []);
$stats['companies'] = $stmt->fetch()['total'];

// Business Units
$stmt = $db->prepare("SELECT COUNT(*) as total FROM business_units WHERE status = 'Active' $buw");
$stmt->execute($bup);
$stats['business_units'] = $stmt->fetch()['total'];

// Divisions
$stmt = $db->prepare("SELECT COUNT(*) as total FROM divisions d WHERE status = 'Active' $dw");
$stmt->execute($dp);
$stats['divisions'] = $stmt->fetch()['total'];

// Block stats (all scoped)
$block_base = "FROM blocks b WHERE 1=1 $bw";
$p = $bp;

$stats['blocks']      = $db->prepare("SELECT COUNT(*) as v $block_base")->execute($p) ? (function($s,$p){$s->execute($p);return $s->fetch()['v'];})(  $db->prepare("SELECT COUNT(*)       as v $block_base"), $p) : 0;
$stats['total_area']  = (function($db,$q,$p){$s=$db->prepare($q);$s->execute($p);return $s->fetch()['v']??0;})($db,"SELECT SUM(area)          as v $block_base",$p);
$stats['tm_area']     = (function($db,$q,$p){$s=$db->prepare($q);$s->execute($p);return $s->fetch()['v']??0;})($db,"SELECT SUM(area)          as v $block_base AND b.status='TM'",$p);
$stats['tbm_area']    = (function($db,$q,$p){$s=$db->prepare($q);$s->execute($p);return $s->fetch()['v']??0;})($db,"SELECT SUM(area)          as v $block_base AND b.status='TBM'",$p);
$stats['hl_area']     = (function($db,$q,$p){$s=$db->prepare($q);$s->execute($p);return $s->fetch()['v']??0;})($db,"SELECT SUM(area)          as v $block_base AND b.status='HL'",$p);
$stats['hp_area']     = (function($db,$q,$p){$s=$db->prepare($q);$s->execute($p);return $s->fetch()['v']??0;})($db,"SELECT SUM(area)          as v $block_base AND b.status='HP'",$p);
$stats['hpt_area']    = (function($db,$q,$p){$s=$db->prepare($q);$s->execute($p);return $s->fetch()['v']??0;})($db,"SELECT SUM(area)          as v $block_base AND b.status='HPT'",$p);
$stats['other_area']  = (function($db,$q,$p){$s=$db->prepare($q);$s->execute($p);return $s->fetch()['v']??0;})($db,"SELECT SUM(area)          as v $block_base AND b.status NOT IN ('TM','TBM','HL','HP','HPT')",$p);
$stats['total_plants']= (function($db,$q,$p){$s=$db->prepare($q);$s->execute($p);return $s->fetch()['v']??0;})($db,"SELECT SUM(total_plants)  as v $block_base",$p);

// Recent Companies — scoped to user's company_id if set, otherwise all (Admin)
$user_company_id = $_SESSION['company_id'] ?? null;
if ($user_company_id !== null) {
    $stmt = $db->prepare("SELECT * FROM companies WHERE company_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([(int)$user_company_id]);
} else {
    $stmt = $db->query("SELECT * FROM companies WHERE status='Active' AND company_id >= 1000 ORDER BY created_at DESC LIMIT 5");
}
$recent_companies = $stmt->fetchAll();

// Business Units by Type (scoped)
$stmt = $db->prepare("SELECT unit_type, COUNT(*) as count, SUM(total_area) as total_area FROM business_units WHERE status='Active' $buw GROUP BY unit_type");
$stmt->execute($bup);
$bu_by_type = $stmt->fetchAll();

// Blocks by Status (scoped)
$stmt = $db->prepare("SELECT b.status, COUNT(*) as count, SUM(b.area) as total_area $block_base GROUP BY b.status");
$stmt->execute($bp);
$blocks_by_status = $stmt->fetchAll();

// Area by Business Unit pie (scoped)
$stmt = $db->prepare("
    SELECT bu.unit_name, SUM(CAST(b.area AS DECIMAL(15,2))) AS total_area
    FROM blocks b
    LEFT JOIN business_units bu ON b.business_unit_id = bu.business_unit_id
    WHERE bu.business_unit_id IS NOT NULL $bw
    GROUP BY bu.business_unit_id, bu.unit_name
    HAVING total_area > 0
    ORDER BY total_area DESC
");
$stmt->execute($bp);
$area_by_business_unit = $stmt->fetchAll();

// Area by Division pie (scoped)
// Note: blocks are assigned to leaf divisions — no parent_division_id filter needed
$stmt = $db->prepare("
    SELECT d.division_name, SUM(CAST(b.area AS DECIMAL(15,2))) AS total_area
    FROM blocks b
    INNER JOIN divisions d ON b.division_id = d.division_id
    WHERE 1=1 $bw
    GROUP BY d.division_id, d.division_name
    HAVING total_area > 0
    ORDER BY total_area DESC
");
$stmt->execute($bp);
$area_by_division = $stmt->fetchAll();

// Planting Years (scoped)
$stmt = $db->prepare("
    SELECT py.year, COUNT(b.block_id) as block_count, SUM(b.area) as total_area
    FROM planting_years py
    LEFT JOIN blocks b ON py.planting_year_id = b.planting_year_id AND 1=1 $bw
    GROUP BY py.year
    ORDER BY py.year DESC
    LIMIT 10
");
$stmt->execute($bp);
$planting_years = $stmt->fetchAll();

// ── Helper: get label for current filter ─────────────────────────────────────
function scope_label($id, array $list, string $id_key, string $name_key): string {
    if ($id === null) return '';
    foreach ($list as $r) { if ((int)$r[$id_key] === $id) return $r[$name_key]; }
    return '#' . $id;
}
$label_company = scope_label($f_company, $companies_opts, 'company_id', 'company_name');
$label_bu      = scope_label($f_bu,      $bus_opts,       'business_unit_id', 'unit_name');
$label_div     = scope_label($f_div,     $div_opts,       'division_id', 'division_name');

// ── Page ─────────────────────────────────────────────────────────────────────
$page_title = "Dashboard";
require_once 'includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-speedometer2"></i> Dashboard</h1>
        <p class="text-muted mb-0">Plantation Master Data Overview</p>
    </div>
</div>

<!-- ── Scope Filter Bar ──────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" action="index.php" id="scopeForm" class="row g-2 align-items-center">
            <div class="col-auto">
                <small class="text-muted fw-semibold"><i class="bi bi-funnel"></i> Scope:</small>
            </div>

            <!-- Company -->
            <div class="col-auto">
                <?php if ($lock_company): ?>
                    <span class="badge bg-primary py-2 px-3" title="Locked to your account scope">
                        <i class="bi bi-lock-fill me-1"></i><?php echo htmlspecialchars($label_company); ?>
                    </span>
                <?php else: ?>
                    <select name="company_id" id="f_company" class="form-select form-select-sm" style="min-width:170px;">
                        <option value="">All Companies</option>
                        <?php foreach ($companies_opts as $c): ?>
                            <option value="<?php echo $c['company_id']; ?>" <?php echo $f_company === (int)$c['company_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="col-auto text-muted"><i class="bi bi-chevron-right" style="font-size:.7rem;"></i></div>

            <!-- Business Unit — filtered server-side by selected company -->
            <div class="col-auto">
                <?php if ($lock_bu): ?>
                    <span class="badge bg-success py-2 px-3" title="Locked to your account scope">
                        <i class="bi bi-lock-fill me-1"></i><?php echo htmlspecialchars($label_bu); ?>
                    </span>
                <?php else: ?>
                    <select name="business_unit_id" id="f_bu" class="form-select form-select-sm" style="min-width:170px;">
                        <option value="">All Business Units</option>
                        <?php foreach ($bus_opts as $b):
                            // server-side filter: only show BUs for the selected company
                            if ($f_company !== null && (int)$b['company_id'] !== $f_company) continue;
                        ?>
                            <option value="<?php echo $b['business_unit_id']; ?>"
                                    data-company="<?php echo $b['company_id']; ?>"
                                    <?php echo $f_bu === (int)$b['business_unit_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['unit_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="col-auto text-muted"><i class="bi bi-chevron-right" style="font-size:.7rem;"></i></div>

            <!-- Division — filtered server-side by selected BU -->
            <div class="col-auto">
                <?php if ($lock_div): ?>
                    <span class="badge bg-info py-2 px-3" title="Locked to your account scope">
                        <i class="bi bi-lock-fill me-1"></i><?php echo htmlspecialchars($label_div); ?>
                    </span>
                <?php else: ?>
                    <select name="division_id" id="f_div" class="form-select form-select-sm" style="min-width:160px;">
                        <option value="">All Divisions</option>
                        <?php
                        // Build set of BU ids that belong to the selected company (for company-only filter)
                        $company_bu_ids = [];
                        if ($f_company !== null) {
                            foreach ($bus_opts as $b) {
                                if ((int)$b['company_id'] === $f_company) {
                                    $company_bu_ids[] = (int)$b['business_unit_id'];
                                }
                            }
                        }
                        foreach ($div_opts as $d):
                            if ($f_bu !== null && (int)$d['business_unit_id'] !== $f_bu) continue;
                            // company selected, no BU: only show divisions whose BU belongs to that company
                            if ($f_bu === null && $f_company !== null && !in_array((int)$d['business_unit_id'], $company_bu_ids)) continue;
                        ?>
                            <option value="<?php echo $d['division_id']; ?>"
                                    data-bu="<?php echo $d['business_unit_id']; ?>"
                                    <?php echo $f_div === (int)$d['division_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['division_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <?php if (!$lock_company || !$lock_bu || !$lock_div): ?>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-clockwise"></i> Apply</button>
                <?php if ($f_company || $f_bu || $f_div): ?>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary ms-1"><i class="bi bi-x"></i> Reset</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Active scope summary pill -->
            <?php
            $active_scopes = array_filter([
                $label_company ? ($lock_company ? null : $label_company) : null,
                $label_bu      ? ($lock_bu      ? null : $label_bu)      : null,
                $label_div     ? ($lock_div     ? null : $label_div)     : null,
            ]);
            ?>
            <?php if (!empty($active_scopes)): ?>
            <div class="col-auto ms-auto">
                <small class="text-success"><i class="bi bi-filter-circle-fill"></i> Filtered: <?php echo implode(' › ', $active_scopes); ?></small>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ── Statistics Cards ─────────────────────────────────────────────────── -->
<div class="row mb-3">
    <div class="col-md-3">
        <a href="companies.php" class="text-decoration-none">
            <div class="card stat-card bg-primary text-white" style="cursor:pointer;">
                <div class="card-body py-2">
                    <h4 class="mb-1 text-white"><?php echo number_format($stats['companies']); ?></h4>
                    <p class="mb-0 small text-white"><i class="bi bi-building"></i> Companies</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="business_units.php" class="text-decoration-none">
            <div class="card stat-card bg-success text-white" style="cursor:pointer;">
                <div class="card-body py-2">
                    <h4 class="mb-1 text-white"><?php echo number_format($stats['business_units']); ?></h4>
                    <p class="mb-0 small text-white"><i class="bi bi-diagram-3"></i> Business Units</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="divisions.php" class="text-decoration-none">
            <div class="card stat-card bg-info text-white" style="cursor:pointer;">
                <div class="card-body py-2">
                    <h4 class="mb-1 text-white"><?php echo number_format($stats['divisions']); ?></h4>
                    <p class="mb-0 small text-white"><i class="bi bi-grid-3x3"></i> Divisions</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="blocks.php" class="text-decoration-none">
            <div class="card stat-card bg-warning text-dark" style="cursor:pointer;">
                <div class="card-body py-2">
                    <h4 class="mb-1 text-dark"><?php echo number_format($stats['blocks']); ?></h4>
                    <p class="mb-0 small text-dark"><i class="bi bi-grid"></i> Total Blocks</p>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Area Statistics -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card stat-card bg-secondary text-white">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo format_number($stats['total_area']); ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-map"></i> Total Area (Ha)</p>
            </div>
        </div>
    </div>
    <?php if ($stats['tm_area'] > 0): ?>
    <div class="col-md-3">
        <div class="card stat-card bg-success text-white">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo format_number($stats['tm_area']); ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-check-circle"></i> TM Area (Ha)</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($stats['tbm_area'] > 0): ?>
    <div class="col-md-3">
        <div class="card stat-card bg-warning text-dark">
            <div class="card-body py-2">
                <h4 class="mb-1 text-dark"><?php echo format_number($stats['tbm_area']); ?></h4>
                <p class="mb-0 small text-dark"><i class="bi bi-hourglass-split"></i> TBM Area (Ha)</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-md-3">
        <div class="card stat-card bg-info text-white">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo number_format($stats['total_plants']); ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-tree"></i> Total Plants</p>
            </div>
        </div>
    </div>
</div>

<!-- HL / HP / HPT / Others Area -->
<?php if ($stats['hl_area'] > 0 || $stats['hp_area'] > 0 || $stats['hpt_area'] > 0 || $stats['other_area'] > 0): ?>
<div class="row mb-3">
    <?php if ($stats['hl_area'] > 0): ?>
    <div class="col-md-3">
        <div class="card stat-card text-white" style="background-color:#0288d1;">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo format_number($stats['hl_area']); ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-water"></i> HL Area (Ha)</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($stats['hp_area'] > 0): ?>
    <div class="col-md-3">
        <div class="card stat-card text-white" style="background-color:#1565c0;">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo format_number($stats['hp_area']); ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-tree-fill"></i> HP Area (Ha)</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($stats['hpt_area'] > 0): ?>
    <div class="col-md-3">
        <div class="card stat-card text-white" style="background-color:#4a148c;">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo format_number($stats['hpt_area']); ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-shield-fill"></i> HPT Area (Ha)</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($stats['other_area'] > 0): ?>
    <div class="col-md-3">
        <div class="card stat-card text-white" style="background-color:#37474f;">
            <div class="card-body py-2">
                <h4 class="mb-1 text-white"><?php echo format_number($stats['other_area']); ?></h4>
                <p class="mb-0 small text-white"><i class="bi bi-question-circle"></i> Others Area (Ha)</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($area_by_business_unit) || !empty($area_by_division)): ?>
<!-- Area Charts -->
<div class="row mb-4">
    <?php if (!empty($area_by_business_unit)): ?>
    <div class="col-12 col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-diagram-3-fill"></i> Area (Ha) by Business Unit</div>
            <div class="card-body"><div id="businessUnitPieChart" style="height:380px;"></div></div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($area_by_division)): ?>
    <div class="col-12 col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart-fill"></i> Area (Ha) by Division</div>
            <div class="card-body"><div id="divisionPieChart" style="height:380px;"></div></div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row">
    <!-- Business Units by Type -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-pie-chart"></i> Business Units by Type</div>
            <div class="card-body">
                <table class="table table-sm">
                    <thead><tr><th>Type</th><th class="text-end">Count</th><th class="text-end">Total Area (Ha)</th></tr></thead>
                    <tbody>
                        <?php foreach ($bu_by_type as $row): ?>
                        <tr>
                            <td><i class="bi bi-circle-fill text-primary"></i> <?php echo $row['unit_type']; ?></td>
                            <td class="text-end"><strong><?php echo number_format($row['count']); ?></strong></td>
                            <td class="text-end"><?php echo format_number($row['total_area']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- Blocks by Status -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-bar-chart"></i> Blocks by Status</div>
            <div class="card-body">
                <table class="table table-sm">
                    <thead><tr><th>Status</th><th class="text-end">Blocks</th><th class="text-end">Total Area (Ha)</th></tr></thead>
                    <tbody>
                        <?php foreach ($blocks_by_status as $row): ?>
                        <tr>
                            <td><?php echo get_status_badge($row['status']); ?></td>
                            <td class="text-end"><strong><?php echo number_format($row['count']); ?></strong></td>
                            <td class="text-end"><?php echo format_number($row['total_area']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Recent Companies -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-building"></i>
                <?php if ($user_company_id !== null): ?>
                    My Company
                <?php else: ?>
                    Recent Companies
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush">
                    <?php foreach ($recent_companies as $company): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1"><?php echo htmlspecialchars($company['company_name']); ?></h6>
                                <small class="text-muted"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($company['province']); ?></small>
                            </div>
                            <?php echo get_status_badge($company['status']); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-center mt-3">
                    <a href="companies.php" class="btn btn-sm btn-outline-primary">View All Companies</a>
                </div>
            </div>
        </div>
    </div>
    <!-- Planting Years Summary -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-calendar-event"></i> Planting Years Summary</div>
            <div class="card-body">
                <table class="table table-sm">
                    <thead><tr><th>Year</th><th class="text-end">Blocks</th><th class="text-end">Area (Ha)</th></tr></thead>
                    <tbody>
                        <?php foreach ($planting_years as $row): ?>
                        <tr>
                            <td><strong><?php echo $row['year']; ?></strong></td>
                            <td class="text-end"><?php echo number_format($row['block_count']); ?></td>
                            <td class="text-end"><?php echo format_number($row['total_area']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-center mt-3">
                    <a href="planting_years.php" class="btn btn-sm btn-outline-primary">View All Planting Years</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card">
    <div class="card-header"><i class="bi bi-lightning"></i> Quick Actions</div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-2"><a href="companies.php" class="btn btn-outline-primary w-100"><i class="bi bi-building"></i> Manage Companies</a></div>
            <div class="col-md-3 mb-2"><a href="business_units.php" class="btn btn-outline-success w-100"><i class="bi bi-diagram-3"></i> Manage Business Units</a></div>
            <div class="col-md-3 mb-2"><a href="divisions.php" class="btn btn-outline-info w-100"><i class="bi bi-grid-3x3"></i> Manage Divisions</a></div>
            <div class="col-md-3 mb-2"><a href="blocks.php" class="btn btn-outline-warning w-100"><i class="bi bi-grid"></i> Manage Blocks</a></div>
        </div>
    </div>
</div>

<?php if (!empty($area_by_business_unit) || !empty($area_by_division)): ?>
<script src="js/highcharts.js"></script>
<script src="js/highcharts-3d.js"></script>
<?php if (!empty($area_by_business_unit)): ?>
<script>
(function () {
    var seriesData = <?php echo json_encode(array_values(array_map(function($r) {
        return ['name' => $r['unit_name'], 'y' => round((float)$r['total_area'], 2)];
    }, $area_by_business_unit))); ?>;
    Highcharts.chart('businessUnitPieChart', {
        colors: ['#4caf50','#8bc34a','#fdd835','#aed581','#cddc39','#66bb6a','#d4e157','#81c784'],
        chart: { type:'pie', options3d:{enabled:true,alpha:45,beta:0}, backgroundColor:'transparent', margin:[0,0,0,0], spacing:[10,10,10,10] },
        title: { text:'' },
        tooltip: { pointFormatter: function(){ return '<b>'+this.y.toLocaleString('id-ID',{minimumFractionDigits:2})+' Ha</b><br/>'+this.percentage.toFixed(1)+'%'; } },
        plotOptions: { pie: { depth:45, allowPointSelect:true, cursor:'pointer', dataLabels:{ enabled:true, formatter:function(){ return this.percentage>2?'<b>'+this.point.name+'</b><br/>'+this.percentage.toFixed(1)+'%':null; }, style:{fontSize:'11px'} }, showInLegend:true } },
        legend: { enabled:true, layout:'vertical', align:'right', verticalAlign:'middle', itemStyle:{fontSize:'12px'} },
        credits: { enabled:false },
        series: [{ name:'Area (Ha)', colorByPoint:true, data:seriesData }]
    });
})();
</script>
<?php endif; ?>
<?php if (!empty($area_by_division)): ?>
<script>
(function () {
    var seriesData = <?php echo json_encode(array_values(array_map(function($r) {
        return ['name' => $r['division_name'], 'y' => round((float)$r['total_area'], 2)];
    }, array_filter($area_by_division, function($r){ return (float)$r['total_area'] > 0; })))); ?>;
    Highcharts.chart('divisionPieChart', {
        chart: { type:'pie', options3d:{enabled:true,alpha:45,beta:0}, backgroundColor:'transparent', margin:[0,0,0,0], spacing:[10,10,10,10] },
        title: { text:'' },
        tooltip: { pointFormatter: function(){ return '<b>'+this.y.toLocaleString('id-ID',{minimumFractionDigits:2})+' Ha</b><br/>'+this.percentage.toFixed(1)+'%'; } },
        plotOptions: { pie: { innerSize:'40%', depth:45, allowPointSelect:true, cursor:'pointer', dataLabels:{ enabled:true, formatter:function(){ return this.percentage>2?'<b>'+this.point.name+'</b><br/>'+this.percentage.toFixed(1)+'%':null; }, style:{fontSize:'11px'} }, showInLegend:true } },
        legend: { enabled:true, layout:'vertical', align:'right', verticalAlign:'middle', itemStyle:{fontSize:'12px'} },
        credits: { enabled:false },
        series: [{ name:'Area (Ha)', colorByPoint:true, data:seriesData }]
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>

<!-- ── Cascade JS — live change events only (initial state set server-side) ── -->
<?php if (!$lock_company || !$lock_bu || !$lock_div): ?>
<script>
(function () {
    var ALL_BUS  = <?php echo $bus_json; ?>;
    var ALL_DIVS = <?php echo $div_json; ?>;

    // Locked values injected server-side (0 means not locked / no value)
    var LOCKED_COMPANY = <?php echo $f_company !== null ? (int)$f_company : 0; ?>;
    var LOCKED_BU      = <?php echo $f_bu      !== null ? (int)$f_bu      : 0; ?>;

    var cSel  = document.getElementById('f_company');
    var buSel = document.getElementById('f_bu');
    var dSel  = document.getElementById('f_div');

    // Read effective company id: prefer live select, fall back to locked value
    function getCId() { return cSel ? (parseInt(cSel.value) || 0) : LOCKED_COMPANY; }
    function getBuId() { return buSel ? (parseInt(buSel.value) || 0) : LOCKED_BU; }

    // Rebuild BU dropdown then reset Division
    function rebuildBU() {
        if (!buSel) return;
        var cId = getCId();
        var filtered = cId ? ALL_BUS.filter(function(b){ return b.company_id === cId; }) : ALL_BUS;
        buSel.innerHTML = '<option value="">All Business Units</option>';
        filtered.forEach(function(b) {
            var o = document.createElement('option');
            o.value = b.id;
            o.textContent = b.name;
            o.dataset.company = b.company_id;
            buSel.appendChild(o);
        });
        rebuildDiv(); // reset divisions when company changes
    }

    // Rebuild Division dropdown
    function rebuildDiv() {
        if (!dSel) return;
        var buId = getBuId();
        var cId  = getCId();
        var filtered;
        if (buId) {
            // BU selected → show only divisions of that BU
            filtered = ALL_DIVS.filter(function(d){ return d.bu_id === buId; });
        } else if (cId) {
            // Company selected but no BU → show only divisions belonging to that company's BUs
            var companyBuIds = ALL_BUS.filter(function(b){ return b.company_id === cId; }).map(function(b){ return b.id; });
            filtered = ALL_DIVS.filter(function(d){ return companyBuIds.indexOf(d.bu_id) !== -1; });
        } else {
            filtered = ALL_DIVS;
        }
        dSel.innerHTML = '<option value="">All Divisions</option>';
        filtered.forEach(function(d) {
            var o = document.createElement('option');
            o.value = d.id;
            o.textContent = d.name;
            o.dataset.bu = d.bu_id;
            dSel.appendChild(o);
        });
    }

    // Company change → rebuild BU (which also rebuilds Division)
    if (cSel) cSel.addEventListener('change', rebuildBU);
    // BU change → rebuild Division only
    if (buSel) buSel.addEventListener('change', rebuildDiv);

    // Auto-submit on any change so the page reloads with correct scoped data
    [cSel, buSel, dSel].forEach(function(sel) {
        if (sel) sel.addEventListener('change', function() {
            document.getElementById('scopeForm').submit();
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
