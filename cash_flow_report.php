<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';

$db         = getDB();
$page_title = __('cfr_title');

// ── Filters ───────────────────────────────────────────────────────────────────
$f_company  = intval($_GET['company']   ?? 0);
$f_year     = intval($_GET['year']      ?? date('Y'));
$f_month    = intval($_GET['month']     ?? 0);   // 0 = full year
$f_compare  = intval($_GET['compare']   ?? 0);   // compare with prior period

// Build date range
if ($f_month > 0) {
    $date_from = sprintf('%04d-%02d-01', $f_year, $f_month);
    $date_to   = date('Y-m-t', strtotime($date_from));
    $period_label = date('F Y', strtotime($date_from));
    // Prior period: same month previous year
    $prior_from = sprintf('%04d-%02d-01', $f_year - 1, $f_month);
    $prior_to   = date('Y-m-t', strtotime($prior_from));
    $prior_label = date('F Y', strtotime($prior_from));
} else {
    $date_from = sprintf('%04d-01-01', $f_year);
    $date_to   = sprintf('%04d-12-31', $f_year);
    $period_label = (string)$f_year;
    $prior_from = sprintf('%04d-01-01', $f_year - 1);
    $prior_to   = sprintf('%04d-12-31', $f_year - 1);
    $prior_label = (string)($f_year - 1);
}

// ── Core query: aggregate by cf_category + cf_subcategory ────────────────────
function fetch_cf_data(PDO $db, string $from, string $to, int $company_id): array {
    $where  = "ct.status='posted' AND ct.transaction_date BETWEEN ? AND ? AND ct.cf_category != 'inter_account'";
    $params = [$from, $to];
    if ($company_id) { $where .= " AND ct.company_id=?"; $params[] = $company_id; }

    $stmt = $db->prepare("
        SELECT
            ct.cf_category,
            ct.cf_subcategory_id,
            COALESCE(cs.code,  'UNCLASSIFIED')  AS cf_code,
            COALESCE(cs.name,  'Unclassified')  AS cf_name,
            COALESCE(cs.name_id,'Unclassified') AS cf_name_id,
            COALESCE(cs.display_order, 999)      AS display_order,
            SUM(ct.amount_idr)                   AS total_idr
        FROM cash_transactions ct
        LEFT JOIN cf_subcategories cs ON ct.cf_subcategory_id = cs.id
        WHERE $where
        GROUP BY ct.cf_category, ct.cf_subcategory_id, cs.code, cs.name, cs.name_id, cs.display_order
        ORDER BY cs.display_order, ct.cf_category
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Opening cash balance (before date_from across all bank accounts) ──────────
function fetch_opening_cash(PDO $db, string $before_date, int $company_id): float {
    $where  = "ba.status='active'";
    $params = [];
    if ($company_id) { $where .= " AND ba.company_id=?"; $params[] = $company_id; }

    // Sum of all bank account opening balances
    $s1 = $db->prepare("SELECT COALESCE(SUM(opening_balance),0) FROM bank_accounts ba WHERE $where");
    $s1->execute($params);
    $opening = floatval($s1->fetchColumn());

    // All posted transactions before the period
    $where2  = "ct.status='posted' AND ct.transaction_date < ?";
    $params2 = [$before_date];
    if ($company_id) { $where2 .= " AND ct.company_id=?"; $params2[] = $company_id; }

    // Include inter_account transfers to get true cash movement
    $s2 = $db->prepare("
        SELECT COALESCE(SUM(CASE ct.transaction_type WHEN 'receipt' THEN ct.amount_idr ELSE -ct.amount_idr END),0)
        FROM cash_transactions ct
        WHERE $where2
    ");
    $s2->execute($params2);
    return $opening + floatval($s2->fetchColumn());
}

// ── FX effect on cash (difference between opening/closing at current vs historical rate) ──
// Simplified: report the unrealised FX as "effect of exchange rate changes"
function fetch_fx_effect(PDO $db, string $from, string $to, int $company_id): float {
    // We report amount_idr - amount_foreign for non-IDR transactions in the period
    // as the embedded FX impact. Proper revaluation is done separately in fx_revaluation.php.
    $where  = "ct.status='posted' AND ct.transaction_date BETWEEN ? AND ? AND ct.currency_code != 'IDR'";
    $params = [$from, $to];
    if ($company_id) { $where .= " AND ct.company_id=?"; $params[] = $company_id; }
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(
            CASE ct.transaction_type
                WHEN 'receipt' THEN  (ct.amount_idr - ct.amount_foreign)
                ELSE                -(ct.amount_idr - ct.amount_foreign)
            END
        ),0)
        FROM cash_transactions ct WHERE $where
    ");
    $stmt->execute($params);
    return floatval($stmt->fetchColumn());
}

// ── Build section totals ──────────────────────────────────────────────────────
function build_sections(array $rows): array {
    $sections = [
        'operating_receipt'  => ['label' => 'A. OPERATING ACTIVITIES',   'items' => [], 'total_receipts' => 0, 'total_payments' => 0],
        'operating_payment'  => ['label' => '',                           'items' => [], 'total_receipts' => 0, 'total_payments' => 0],
        'investing_receipt'  => ['label' => 'B. INVESTING ACTIVITIES',   'items' => [], 'total_receipts' => 0, 'total_payments' => 0],
        'investing_payment'  => ['label' => '',                           'items' => [], 'total_receipts' => 0, 'total_payments' => 0],
        'financing_receipt'  => ['label' => 'C. FINANCING ACTIVITIES',   'items' => [], 'total_receipts' => 0, 'total_payments' => 0],
        'financing_payment'  => ['label' => '',                           'items' => [], 'total_receipts' => 0, 'total_payments' => 0],
    ];
    foreach ($rows as $r) {
        $cat = $r['cf_category'];
        if (!isset($sections[$cat])) continue;
        $sections[$cat]['items'][] = $r;
        $sections[$cat]['total_receipts'] += (str_ends_with($cat,'receipt') ? $r['total_idr'] : 0);
        $sections[$cat]['total_payments'] += (str_ends_with($cat,'payment') ? $r['total_idr'] : 0);
    }
    return $sections;
}

function section_net(array $sections, string $prefix): float {
    return ($sections[$prefix.'_receipt']['total_receipts'] ?? 0)
         - ($sections[$prefix.'_payment']['total_payments'] ?? 0);
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$cf_rows       = fetch_cf_data($db, $date_from, $date_to, $f_company);
$sections      = build_sections($cf_rows);
$opening_bal   = fetch_opening_cash($db, $date_from, $f_company);
$fx_effect     = fetch_fx_effect($db, $date_from, $date_to, $f_company);
$net_operating = section_net($sections, 'operating');
$net_investing = section_net($sections, 'investing');
$net_financing = section_net($sections, 'financing');
$net_change    = $net_operating + $net_investing + $net_financing;
$closing_bal   = $opening_bal + $net_change + $fx_effect;

// Prior period (for comparison)
$prior_sections = $prior_opening = $prior_fx = $prior_net_op = $prior_net_inv = $prior_net_fin = $prior_net_chg = $prior_closing = null;
if ($f_compare) {
    $prior_rows      = fetch_cf_data($db, $prior_from, $prior_to, $f_company);
    $prior_sections  = build_sections($prior_rows);
    $prior_opening   = fetch_opening_cash($db, $prior_from, $f_company);
    $prior_fx        = fetch_fx_effect($db, $prior_from, $prior_to, $f_company);
    $prior_net_op    = section_net($prior_sections, 'operating');
    $prior_net_inv   = section_net($prior_sections, 'investing');
    $prior_net_fin   = section_net($prior_sections, 'financing');
    $prior_net_chg   = $prior_net_op + $prior_net_inv + $prior_net_fin;
    $prior_closing   = $prior_opening + $prior_net_chg + $prior_fx;
}

// Dropdowns
$companies = $db->query("SELECT company_id, company_name FROM companies WHERE status='Active' ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);
$years     = range(date('Y'), date('Y') - 5);

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt(float $v, bool $parens = true): string {
    if ($v == 0) return '<span class="text-muted">—</span>';
    $fmt = 'Rp ' . number_format(abs($v), 0, ',', '.');
    if ($v < 0 && $parens) return '<span class="text-danger">(' . $fmt . ')</span>';
    if ($v < 0)            return '<span class="text-danger">-' . $fmt . '</span>';
    return $fmt;
}
function fmt_signed(float $v): string {
    if ($v == 0) return '<span class="text-muted">—</span>';
    $fmt = number_format(abs($v), 0, ',', '.');
    return $v >= 0
        ? '<span class="text-success">Rp ' . $fmt . '</span>'
        : '<span class="text-danger">(Rp ' . $fmt . ')</span>';
}

require_once 'includes/header.php';
?>

<div class="content-wrapper">
    <!-- Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-graph-down-arrow"></i> <?php echo __('cfr_title'); ?></h1>
                <p class="text-muted mb-0"><?php echo __('cfr_subtitle'); ?></p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer"></i> <?php echo __('cfr_print'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1"><?php echo __('company'); ?></label>
                    <select name="company" class="form-select form-select-sm">
                        <option value="0">— <?php echo __('cfr_all_companies'); ?> —</option>
                        <?php foreach ($companies as $c): ?>
                        <option value="<?php echo $c['company_id']; ?>" <?php if($f_company==$c['company_id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($c['company_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('cfr_year'); ?></label>
                    <select name="year" class="form-select form-select-sm">
                        <?php foreach ($years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php if($f_year==$y) echo 'selected'; ?>><?php echo $y; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo __('cfr_month'); ?></label>
                    <select name="month" class="form-select form-select-sm">
                        <option value="0" <?php if(!$f_month) echo 'selected'; ?>><?php echo __('cfr_full_year'); ?></option>
                        <?php for ($m=1; $m<=12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php if($f_month==$m) echo 'selected'; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">&nbsp;</label>
                    <div class="form-check form-switch mt-1">
                        <input class="form-check-input" type="checkbox" name="compare" value="1" id="chkCompare" <?php if($f_compare) echo 'checked'; ?>>
                        <label class="form-check-label small" for="chkCompare"><?php echo __('cfr_compare_prior'); ?></label>
                    </div>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i> <?php echo __('cfr_apply'); ?></button>
                    <a href="cash_flow_report.php" class="btn btn-outline-secondary btn-sm px-2"><i class="bi bi-x"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary KPI cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <h3 style="font-size:1.1rem;" class="<?php echo $net_operating>=0?'text-success':'text-danger'; ?>">
                        <?php echo 'Rp '.number_format($net_operating,0,',','.'); ?>
                    </h3>
                    <p><?php echo __('cfr_kpi_operating'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100" style="border-left-color:#7c5cd8;">
                <div class="card-body">
                    <h3 style="font-size:1.1rem;" class="<?php echo $net_investing>=0?'text-success':'text-danger'; ?>">
                        <?php echo 'Rp '.number_format($net_investing,0,',','.'); ?>
                    </h3>
                    <p><?php echo __('cfr_kpi_investing'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100" style="border-left-color:#0891b2;">
                <div class="card-body">
                    <h3 style="font-size:1.1rem;" class="<?php echo $net_financing>=0?'text-success':'text-danger'; ?>">
                        <?php echo 'Rp '.number_format($net_financing,0,',','.'); ?>
                    </h3>
                    <p><?php echo __('cfr_kpi_financing'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100" style="border-left-color:<?php echo $closing_bal>=0?'#198754':'#dc3545'; ?>;">
                <div class="card-body">
                    <h3 style="font-size:1.1rem;" class="<?php echo $closing_bal>=0?'text-success':'text-danger'; ?>">
                        <?php echo 'Rp '.number_format($closing_bal,0,',','.'); ?>
                    </h3>
                    <p><?php echo __('cfr_kpi_closing'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Cash Flow Statement -->
    <div class="card" id="cfReportCard">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-earmark-text"></i> <?php echo __('cfr_statement_header'); ?></span>
                <span class="text-white-50 small">
                    <?php echo __('cfr_method_label'); ?> &middot; <?php echo htmlspecialchars($period_label); ?>
                </span>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0" id="cfTable" style="font-size:0.9rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:55%;"><?php echo __('cfr_col_item'); ?></th>
                        <th class="text-end" style="width:20%;"><?php echo htmlspecialchars($period_label); ?></th>
                        <?php if ($f_compare): ?>
                        <th class="text-end" style="width:12%;"><?php echo htmlspecialchars($prior_label); ?></th>
                        <th class="text-end" style="width:13%;"><?php echo __('cfr_col_change'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                // ── Render a section (receipt + payment) ─────────────────────
                function render_section(string $prefix, string $section_letter, string $section_title,
                                        array $sections, ?array $prior_sections, bool $compare,
                                        float $net, ?float $prior_net): void {
                    $cat_r = $prefix . '_receipt';
                    $cat_p = $prefix . '_payment';
                    $cols  = $compare ? 4 : 2;
                    ?>
                    <!-- Section header -->
                    <tr class="table-secondary">
                        <td colspan="<?php echo $cols; ?>" class="fw-bold py-2" style="letter-spacing:0.3px;">
                            <?php echo htmlspecialchars($section_title); ?>
                        </td>
                    </tr>
                    <?php
                    // Receipts
                    foreach ($sections[$cat_r]['items'] as $r): ?>
                    <tr>
                        <td class="ps-4 text-success">
                            <i class="bi bi-arrow-down-short text-success"></i>
                            <?php echo htmlspecialchars($r['cf_name']); ?>
                            <small class="text-muted ms-1">(<?php echo $r['cf_code']; ?>)</small>
                        </td>
                        <td class="text-end text-success"><?php echo fmt($r['total_idr']); ?></td>
                        <?php if ($compare):
                            $pv = 0;
                            if ($prior_sections) {
                                foreach ($prior_sections[$cat_r]['items'] as $p) {
                                    if ($p['cf_subcategory_id'] == $r['cf_subcategory_id']) { $pv = $p['total_idr']; break; }
                                }
                            }
                            $chg = $r['total_idr'] - $pv;
                        ?>
                        <td class="text-end text-muted"><?php echo fmt($pv); ?></td>
                        <td class="text-end"><?php echo fmt_signed($chg); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach;
                    // Payments
                    foreach ($sections[$cat_p]['items'] as $r): ?>
                    <tr>
                        <td class="ps-4 text-danger">
                            <i class="bi bi-arrow-up-short text-danger"></i>
                            <?php echo htmlspecialchars($r['cf_name']); ?>
                            <small class="text-muted ms-1">(<?php echo $r['cf_code']; ?>)</small>
                        </td>
                        <td class="text-end text-danger">(Rp <?php echo number_format($r['total_idr'],0,',','.'); ?>)</td>
                        <?php if ($compare):
                            $pv = 0;
                            if ($prior_sections) {
                                foreach ($prior_sections[$cat_p]['items'] as $p) {
                                    if ($p['cf_subcategory_id'] == $r['cf_subcategory_id']) { $pv = $p['total_idr']; break; }
                                }
                            }
                            $chg = (-$r['total_idr']) - (-$pv);
                        ?>
                        <td class="text-end text-muted">(Rp <?php echo number_format($pv,0,',','.'); ?>)</td>
                        <td class="text-end"><?php echo fmt_signed($chg); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Section net -->
                    <tr class="fw-bold border-top" style="background:#f8f9fa;">
                        <td class="ps-3"><?php echo __('cfr_net_label'); ?> <?php echo $section_letter; ?></td>
                        <td class="text-end <?php echo $net>=0?'text-success':'text-danger'; ?>">
                            <?php echo fmt_signed($net); ?>
                        </td>
                        <?php if ($compare && $prior_net !== null): ?>
                        <td class="text-end text-muted"><?php echo fmt_signed($prior_net); ?></td>
                        <td class="text-end"><?php echo fmt_signed($net - $prior_net); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php
                }

                render_section('operating', 'A', __('cfr_section_operating'), $sections, $prior_sections, (bool)$f_compare, $net_operating, $prior_net_op);
                render_section('investing', 'B', __('cfr_section_investing'), $sections, $prior_sections, (bool)$f_compare, $net_investing, $prior_net_inv);
                render_section('financing', 'C', __('cfr_section_financing'), $sections, $prior_sections, (bool)$f_compare, $net_financing, $prior_net_fin);
                ?>

                <!-- NET CHANGE IN CASH -->
                <tr class="fw-bold border-top border-2" style="background:#e9ecef;">
                    <td class="ps-2"><?php echo __('cfr_net_change'); ?></td>
                    <td class="text-end <?php echo $net_change>=0?'text-success':'text-danger'; ?> fs-6">
                        <?php echo fmt_signed($net_change); ?>
                    </td>
                    <?php if ($f_compare): ?>
                    <td class="text-end text-muted"><?php echo fmt_signed($prior_net_chg); ?></td>
                    <td class="text-end"><?php echo fmt_signed($net_change - $prior_net_chg); ?></td>
                    <?php endif; ?>
                </tr>

                <!-- Opening balance -->
                <tr>
                    <td class="ps-3 text-muted"><?php echo __('cfr_opening_bal'); ?> <small><?php echo htmlspecialchars($date_from); ?></small></td>
                    <td class="text-end"><?php echo fmt($opening_bal, false); ?></td>
                    <?php if ($f_compare): ?>
                    <td class="text-end text-muted"><?php echo fmt($prior_opening, false); ?></td>
                    <td class="text-end"><?php echo fmt_signed($opening_bal - $prior_opening); ?></td>
                    <?php endif; ?>
                </tr>

                <!-- FX Effect -->
                <?php if ($fx_effect != 0 || ($f_compare && $prior_fx != 0)): ?>
                <tr>
                    <td class="ps-3 text-muted fst-italic"><?php echo __('cfr_fx_effect'); ?></td>
                    <td class="text-end"><?php echo fmt_signed($fx_effect); ?></td>
                    <?php if ($f_compare): ?>
                    <td class="text-end text-muted"><?php echo fmt_signed($prior_fx); ?></td>
                    <td class="text-end"><?php echo fmt_signed($fx_effect - $prior_fx); ?></td>
                    <?php endif; ?>
                </tr>
                <?php endif; ?>

                <!-- CLOSING BALANCE -->
                <tr class="fw-bold border-top border-2" style="background:#d1ecf1;">
                    <td class="ps-2"><?php echo __('cfr_closing_bal'); ?> <small><?php echo htmlspecialchars($date_to); ?></small></td>
                    <td class="text-end <?php echo $closing_bal>=0?'text-success':'text-danger'; ?> fs-6">
                        <strong><?php echo 'Rp '.number_format($closing_bal,0,',','.'); ?></strong>
                    </td>
                    <?php if ($f_compare): ?>
                    <td class="text-end text-muted fw-bold"><?php echo 'Rp '.number_format($prior_closing,0,',','.'); ?></td>
                    <td class="text-end"><?php echo fmt_signed($closing_bal - $prior_closing); ?></td>
                    <?php endif; ?>
                </tr>

                </tbody>
            </table>
        </div>
        <div class="card-footer text-muted small d-flex justify-content-between">
            <span><i class="bi bi-info-circle"></i> <?php echo __('cfr_method_note'); ?></span>
            <span><?php echo __('cfr_generated'); ?>: <?php echo date('d/m/Y H:i'); ?></span>
        </div>
    </div>
</div>

<style>
@media print {
    .sidebar, #sidebarToggle, nav.navbar, .page-header .btn,
    .card:not(#cfReportCard), form { display: none !important; }
    .content-wrapper { padding: 0 !important; }
    #cfReportCard { box-shadow: none !important; border: 1px solid #ccc !important; }
    #cfTable { font-size: 0.82rem; }
}
</style>

<?php require_once 'includes/footer.php'; ?>
