<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/lang.php';

$db = getDB();
$page_title = __('plasma_dash_title');
require_once 'includes/header.php';

// ── Filter: year ──────────────────────────────────────────────────────────────
$yr = (int) get('year', date('Y'));
$yr_list = range(date('Y'), 2024);

// ══════════════════════════════════════════════════════════════════════════════
// QUERY BLOCK
// ══════════════════════════════════════════════════════════════════════════════

// 1. Farmer portfolio totals
$portfolio = $db->query("
    SELECT
        COUNT(*)                                                   AS total_farmers,
        SUM(CASE WHEN status='active'    THEN 1 ELSE 0 END)        AS active,
        SUM(CASE WHEN status='graduated' THEN 1 ELSE 0 END)        AS graduated,
        SUM(CASE WHEN status='inactive' OR status='exited'
                      THEN 1 ELSE 0 END)                           AS inactive,
        COALESCE(SUM(land_area_ha), 0)                             AS total_ha,
        COALESCE(SUM(credit_total), 0)                             AS credit_total,
        COALESCE(SUM(credit_remaining), 0)                         AS credit_remaining,
        SUM(CASE WHEN credit_end_date < CURDATE()
                  AND credit_remaining > 0 THEN 1 ELSE 0 END)      AS overdue
    FROM plasma_farmers
")->fetch();

// 2. FFB delivery stats for selected year
$ffb = $db->query("
    SELECT
        COUNT(*)                                                        AS total_trips,
        SUM(CASE WHEN status='accepted'  THEN 1 ELSE 0 END)            AS accepted_trips,
        SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END)            AS pending_trips,
        SUM(CASE WHEN status='rejected'  THEN 1 ELSE 0 END)            AS rejected_trips,
        COALESCE(SUM(CASE WHEN status='accepted' THEN net_weight_kg ELSE 0 END), 0) AS accepted_kg,
        COALESCE(SUM(net_weight_kg), 0)                                 AS total_kg,
        COUNT(DISTINCT farmer_id)                                       AS active_farmers
    FROM plasma_ffb_deliveries
    WHERE YEAR(delivery_date) = $yr
")->fetch();

// 3. Payment statement stats for selected year
$pay = $db->query("
    SELECT
        COUNT(*)                                                        AS total_stmts,
        SUM(CASE WHEN status='draft'     THEN 1 ELSE 0 END)            AS draft,
        SUM(CASE WHEN status='posted'    THEN 1 ELSE 0 END)            AS posted,
        SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END)            AS cancelled,
        SUM(CASE WHEN status='posted'    THEN journal_posted ELSE 0 END) AS gl_posted,
        COALESCE(SUM(CASE WHEN status='posted' THEN gross_amount   ELSE 0 END), 0) AS gross,
        COALESCE(SUM(CASE WHEN status='posted' THEN loan_deduction ELSE 0 END), 0) AS loan_ded,
        COALESCE(SUM(CASE WHEN status='posted' THEN other_deduction ELSE 0 END),0) AS other_ded,
        COALESCE(SUM(CASE WHEN status='posted' THEN net_payout     ELSE 0 END), 0) AS net_payout,
        COALESCE(SUM(CASE WHEN status='posted' THEN (credit_before - credit_after) ELSE 0 END), 0) AS credit_repaid
    FROM plasma_payments
    WHERE YEAR(period_start) = $yr
")->fetch();

// 4. Monthly FFB tonnage trend (accepted) for selected year
$monthly_ffb = $db->query("
    SELECT MONTH(delivery_date) AS mo,
           MONTHNAME(delivery_date) AS mo_name,
           COALESCE(SUM(net_weight_kg), 0) AS net_kg,
           COUNT(*) AS trips,
           COUNT(DISTINCT farmer_id) AS farmers
    FROM   plasma_ffb_deliveries
    WHERE  YEAR(delivery_date) = $yr AND status = 'accepted'
    GROUP  BY MONTH(delivery_date)
    ORDER  BY MONTH(delivery_date)
")->fetchAll();

// 5. Monthly payment trend for selected year
$monthly_pay = $db->query("
    SELECT MONTH(period_start) AS mo,
           MONTHNAME(period_start) AS mo_name,
           COALESCE(SUM(gross_amount), 0)   AS gross,
           COALESCE(SUM(loan_deduction), 0) AS loan_ded,
           COALESCE(SUM(net_payout), 0)     AS net_payout,
           COUNT(*) AS stmts
    FROM   plasma_payments
    WHERE  YEAR(period_start) = $yr AND status = 'posted'
    GROUP  BY MONTH(period_start)
    ORDER  BY MONTH(period_start)
")->fetchAll();

// 6. Per-farmer summary for selected year
$farmer_summary = $db->query("
    SELECT pf.id, pf.farmer_code, pf.farmer_name, pf.kud_name,
           pf.land_area_ha, pf.credit_remaining, pf.deduction_pct, pf.status,
           bu.unit_name  AS estate_name,
           COALESCE(d.trips, 0)      AS trips,
           COALESCE(d.net_kg, 0)     AS net_kg,
           COALESCE(p.gross, 0)      AS gross,
           COALESCE(p.net_payout, 0) AS net_payout,
           COALESCE(p.loan_repaid,0) AS loan_repaid,
           COALESCE(p.stmts, 0)      AS stmts
    FROM   plasma_farmers pf
    LEFT JOIN business_units bu ON pf.business_unit_id = bu.business_unit_id
    LEFT JOIN (
        SELECT farmer_id,
               COUNT(*) AS trips,
               SUM(CASE WHEN status='accepted' THEN net_weight_kg ELSE 0 END) AS net_kg
        FROM   plasma_ffb_deliveries
        WHERE  YEAR(delivery_date) = $yr
        GROUP  BY farmer_id
    ) d ON d.farmer_id = pf.id
    LEFT JOIN (
        SELECT farmer_id,
               COUNT(*) AS stmts,
               SUM(CASE WHEN status='posted' THEN gross_amount   ELSE 0 END) AS gross,
               SUM(CASE WHEN status='posted' THEN net_payout     ELSE 0 END) AS net_payout,
               SUM(CASE WHEN status='posted' THEN (credit_before - credit_after) ELSE 0 END) AS loan_repaid
        FROM   plasma_payments
        WHERE  YEAR(period_start) = $yr
        GROUP  BY farmer_id
    ) p ON p.farmer_id = pf.id
    WHERE  pf.status IN ('active','graduated')
    ORDER  BY net_kg DESC
")->fetchAll();

// 7. Quality grade breakdown for selected year
$quality = $db->query("
    SELECT quality_grade,
           COUNT(*) AS trips,
           SUM(net_weight_kg) AS net_kg
    FROM   plasma_ffb_deliveries
    WHERE  YEAR(delivery_date) = $yr AND status = 'accepted'
    GROUP  BY quality_grade
    ORDER  BY FIELD(quality_grade,'Premium','Grade A','Grade B','Grade C','Reject')
")->fetchAll();

// 8. Draft payments not yet GL-posted (need attention)
$pending_gl = $db->query("
    SELECT p.id, p.payment_no, p.farmer_id, p.period_start, p.period_end,
           p.net_payout, p.status, p.journal_posted,
           pf.farmer_name, pf.farmer_code
    FROM   plasma_payments p
    JOIN   plasma_farmers pf ON p.farmer_id = pf.id
    WHERE  p.status = 'posted' AND p.journal_posted = 0
    ORDER  BY p.period_start DESC
    LIMIT  10
")->fetchAll();

// ── Helper: build chart label/data arrays ─────────────────────────────────────
$months_short = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

$ffb_chart  = array_fill(0, 12, 0);
$pay_chart  = ['gross' => array_fill(0, 12, 0), 'net' => array_fill(0, 12, 0)];

foreach ($monthly_ffb as $r) { $ffb_chart[$r['mo'] - 1] = round($r['net_kg'] / 1000, 2); }
foreach ($monthly_pay as $r) {
    $pay_chart['gross'][$r['mo'] - 1] = round($r['gross']    / 1e6, 3);
    $pay_chart['net'][$r['mo'] - 1]   = round($r['net_payout'] / 1e6, 3);
}

$credit_pct = $portfolio['credit_total'] > 0
    ? round(($portfolio['credit_total'] - $portfolio['credit_remaining']) / $portfolio['credit_total'] * 100, 1)
    : 0;

$quality_colors = ['Premium' => '#16a34a','Grade A' => '#2563eb','Grade B' => '#d97706','Grade C' => '#9333ea','Reject' => '#dc2626'];
?>

<div class="content-wrapper">

<!-- ── Page header ─────────────────────────────────────────────────────────── -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="bi bi-bar-chart-line"></i> <?php echo __('plasma_dash_title'); ?></h1>
            <p class="text-muted mb-0"><?php echo __('plasma_dash_subtitle'); ?></p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <form method="GET" class="d-flex gap-2 align-items-center">
                <label class="text-muted small mb-0"><?php echo __('plasma_dash_year'); ?>:</label>
                <select name="year" class="form-select form-select-sm" style="width:90px" onchange="this.form.submit()">
                    <?php foreach ($yr_list as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $yr ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="plasma_farmers.php"       class="btn btn-sm btn-outline-secondary"><i class="bi bi-people-fill"></i> <?php echo __('nav_plasma_farmers'); ?></a>
            <a href="plasma_ffb_deliveries.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-truck"></i> <?php echo __('nav_plasma_ffb_deliveries'); ?></a>
            <a href="plasma_payments.php"       class="btn btn-sm btn-outline-secondary"><i class="bi bi-receipt"></i> <?php echo __('nav_plasma_payments'); ?></a>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     ROW 1 — Farmer Portfolio KPIs (always — not year-filtered)
════════════════════════════════════════════════════════════════════════════ -->
<h6 class="text-muted text-uppercase fw-bold mb-2" style="letter-spacing:.6px;font-size:.75rem">
    <i class="bi bi-people-fill me-1"></i><?php echo __('plasma_dash_portfolio'); ?>
</h6>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <div class="card h-100 text-center border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-primary"><?= $portfolio['total_farmers'] ?></div>
                <div class="small text-muted"><?php echo __('plasma_dash_total_farmers'); ?></div>
                <div class="mt-1">
                    <span class="badge bg-success"><?= $portfolio['active'] ?> <?php echo __('active'); ?></span>
                    <span class="badge bg-secondary"><?= $portfolio['graduated'] ?> <?php echo __('plasma_dash_graduated'); ?></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card h-100 text-center border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-success"><?= number_format($portfolio['total_ha'], 1) ?></div>
                <div class="small text-muted"><?php echo __('plasma_dash_total_ha'); ?></div>
                <div class="small text-muted mt-1"><?php echo __('plasma_dash_ha_per_farmer'); ?>:
                    <?= $portfolio['total_farmers'] > 0 ? number_format($portfolio['total_ha'] / $portfolio['total_farmers'], 2) : '—' ?> ha
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card h-100 text-center border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="fw-bold text-info" style="font-size:1.3rem">Rp <?= number_format($portfolio['credit_total'] / 1e6, 1) ?>M</div>
                <div class="small text-muted"><?php echo __('plasma_dash_credit_extended'); ?></div>
                <div class="small text-success mt-1"><?= $credit_pct ?>% <?php echo __('plasma_dash_repaid'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card h-100 text-center border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="fw-bold text-warning" style="font-size:1.3rem">Rp <?= number_format($portfolio['credit_remaining'] / 1e6, 1) ?>M</div>
                <div class="small text-muted"><?php echo __('plasma_dash_outstanding'); ?></div>
                <?php if ($portfolio['overdue'] > 0): ?>
                    <span class="badge bg-danger mt-1"><?= $portfolio['overdue'] ?> <?php echo __('plasma_dash_overdue'); ?></span>
                <?php else: ?>
                    <div class="small text-success mt-1">✓ <?php echo __('plasma_dash_no_overdue'); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="small text-muted mb-2"><?php echo __('plasma_dash_credit_progress'); ?></div>
                <div class="progress mb-2" style="height:18px">
                    <div class="progress-bar bg-success" style="width:<?= $credit_pct ?>%">
                        <?= $credit_pct ?>%
                    </div>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span><?php echo __('plasma_dash_repaid'); ?>: Rp <?= number_format(($portfolio['credit_total'] - $portfolio['credit_remaining']) / 1e6, 1) ?>M</span>
                    <span><?php echo __('plasma_dash_balance'); ?>: Rp <?= number_format($portfolio['credit_remaining'] / 1e6, 1) ?>M</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     ROW 2 — Year KPIs  (year-filtered)
════════════════════════════════════════════════════════════════════════════ -->
<h6 class="text-muted text-uppercase fw-bold mb-2" style="letter-spacing:.6px;font-size:.75rem">
    <i class="bi bi-calendar3 me-1"></i><?php echo __('plasma_dash_year_kpi'); ?> — <?= $yr ?>
</h6>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <div class="card h-100 text-center border-start border-4 border-primary shadow-sm">
            <div class="card-body py-3">
                <div class="fw-bold text-primary" style="font-size:1.4rem"><?= number_format($ffb['accepted_kg'] / 1000, 1) ?> t</div>
                <div class="small text-muted"><?php echo __('plasma_dash_accepted_ffb'); ?></div>
                <div class="small text-muted mt-1"><?= $ffb['accepted_trips'] ?> <?php echo __('plasma_dash_trips'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card h-100 text-center border-start border-4 border-info shadow-sm">
            <div class="card-body py-3">
                <div class="fw-bold text-info" style="font-size:1.4rem"><?= $ffb['active_farmers'] ?></div>
                <div class="small text-muted"><?php echo __('plasma_dash_delivering_farmers'); ?></div>
                <div class="small text-muted mt-1"><?= number_format($ffb['accepted_kg'] / 1000 / max($ffb['active_farmers'],1), 1) ?> t/<?php echo __('plasma_dash_farmer'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card h-100 text-center border-start border-4 border-success shadow-sm">
            <div class="card-body py-3">
                <div class="fw-bold text-success" style="font-size:1.3rem">Rp <?= number_format($pay['gross'] / 1e6, 1) ?>M</div>
                <div class="small text-muted"><?php echo __('plasma_dash_gross_ffb'); ?></div>
                <div class="small text-muted mt-1"><?= $pay['posted'] ?> <?php echo __('plasma_dash_stmts_posted'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card h-100 text-center border-start border-4 border-warning shadow-sm">
            <div class="card-body py-3">
                <div class="fw-bold text-warning" style="font-size:1.3rem">Rp <?= number_format($pay['loan_ded'] / 1e6, 1) ?>M</div>
                <div class="small text-muted"><?php echo __('plasma_dash_loan_collected'); ?></div>
                <div class="small text-muted mt-1">
                    <?= $pay['gross'] > 0 ? number_format($pay['loan_ded'] / $pay['gross'] * 100, 1) : '0' ?>%
                    <?php echo __('plasma_dash_of_gross'); ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card h-100 text-center border-start border-4 border-success shadow-sm">
            <div class="card-body py-3">
                <div class="fw-bold text-success" style="font-size:1.3rem">Rp <?= number_format($pay['net_payout'] / 1e6, 1) ?>M</div>
                <div class="small text-muted"><?php echo __('plasma_dash_net_payout'); ?></div>
                <div class="small text-muted mt-1">
                    <?= $pay['gross'] > 0 ? number_format($pay['net_payout'] / $pay['gross'] * 100, 1) : '0' ?>%
                    <?php echo __('plasma_dash_of_gross'); ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card h-100 text-center border-start border-4 <?= $pay['draft'] > 0 ? 'border-danger' : 'border-secondary' ?> shadow-sm">
            <div class="card-body py-3">
                <div class="fw-bold <?= $pay['draft'] > 0 ? 'text-danger' : 'text-secondary' ?>" style="font-size:1.4rem"><?= $pay['draft'] ?></div>
                <div class="small text-muted"><?php echo __('plasma_dash_draft_stmts'); ?></div>
                <?php if (($pay['posted'] - $pay['gl_posted']) > 0): ?>
                    <span class="badge bg-warning text-dark mt-1"><?= $pay['posted'] - $pay['gl_posted'] ?> <?php echo __('plasma_dash_pending_gl'); ?></span>
                <?php else: ?>
                    <div class="small text-success mt-1">✓ GL <?php echo __('plasma_gl_posted'); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     ROW 3 — Charts (monthly trends)
════════════════════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">

    <!-- Monthly FFB Tonnage -->
    <div class="col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold"><i class="bi bi-bar-chart-fill text-primary me-1"></i><?php echo __('plasma_dash_monthly_ffb_chart'); ?> <?= $yr ?></span>
            </div>
            <div class="card-body">
                <div style="width:90%;margin:0 auto">
                    <canvas id="chartFFB" height="120"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Quality Grade Breakdown -->
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold"><i class="bi bi-award me-1 text-warning"></i><?php echo __('plasma_dash_quality_chart'); ?> <?= $yr ?></span>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if (empty($quality)): ?>
                    <p class="text-muted"><?php echo __('no_data'); ?></p>
                <?php else: ?>
                <div style="width:50%;max-width:50%;margin:0 auto">
                    <canvas id="chartQuality" height="160"></canvas>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Payments Chart -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold"><i class="bi bi-graph-up-arrow text-success me-1"></i><?php echo __('plasma_dash_monthly_pay_chart'); ?> <?= $yr ?> (Rp million)</span>
            </div>
            <div class="card-body">
                <canvas id="chartPay" height="80"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     ROW 4 — Per-Farmer Performance Table + Attention Panel
════════════════════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">

    <!-- Per-farmer table -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-people me-1"></i><?php echo __('plasma_dash_farmer_performance'); ?> — <?= $yr ?></span>
                <a href="plasma_farmers.php" class="btn btn-sm btn-outline-secondary"><?php echo __('plasma_dash_view_all'); ?></a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php echo __('plasma_dash_col_farmer'); ?></th>
                                <th><?php echo __('plasma_dash_col_estate'); ?></th>
                                <th class="text-end"><?php echo __('plasma_dash_col_ha'); ?></th>
                                <th class="text-end"><?php echo __('plasma_dash_col_trips'); ?></th>
                                <th class="text-end"><?php echo __('plasma_dash_col_net_kg'); ?></th>
                                <th class="text-end"><?php echo __('plasma_dash_col_gross'); ?></th>
                                <th class="text-end"><?php echo __('plasma_dash_col_net_pay'); ?></th>
                                <th class="text-end"><?php echo __('plasma_dash_col_loan_rep'); ?></th>
                                <th><?php echo __('plasma_dash_col_credit'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($farmer_summary)): ?>
                                <tr><td colspan="9" class="text-center text-muted py-3"><?php echo __('no_data'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($farmer_summary as $f):
                                    $st_color = ['active'=>'success','graduated'=>'primary','inactive'=>'secondary','exited'=>'dark'][$f['status']] ?? 'secondary';
                                    $yield_ha  = $f['land_area_ha'] > 0 ? $f['net_kg'] / $f['land_area_ha'] : 0;
                                ?>
                                <tr>
                                    <td>
                                        <a href="plasma_farmers.php" class="text-decoration-none fw-semibold"><?= htmlspecialchars($f['farmer_name']) ?></a><br>
                                        <small class="text-muted"><?= htmlspecialchars($f['farmer_code']) ?></small>
                                    </td>
                                    <td><small class="text-muted"><?= htmlspecialchars($f['estate_name'] ?? '—') ?></small></td>
                                    <td class="text-end"><?= number_format($f['land_area_ha'], 2) ?></td>
                                    <td class="text-end"><?= $f['trips'] ?: '—' ?></td>
                                    <td class="text-end">
                                        <?= $f['net_kg'] > 0 ? number_format($f['net_kg'] / 1000, 2) . ' t' : '<span class="text-muted">—</span>' ?>
                                        <?php if ($f['land_area_ha'] > 0 && $f['net_kg'] > 0): ?>
                                            <br><small class="text-muted"><?= number_format($yield_ha / 1000, 2) ?> t/ha</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= $f['gross'] > 0 ? 'Rp '.number_format($f['gross']/1e6,2).'M' : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-end text-success fw-semibold"><?= $f['net_payout'] > 0 ? 'Rp '.number_format($f['net_payout']/1e6,2).'M' : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-end text-warning"><?= $f['loan_repaid'] > 0 ? 'Rp '.number_format($f['loan_repaid']/1e6,2).'M' : '<span class="text-muted">—</span>' ?></td>
                                    <td>
                                        <span class="badge bg-<?= $st_color ?>"><?= ucfirst($f['status']) ?></span>
                                        <?php if ($f['credit_remaining'] > 0): ?>
                                            <br><small class="text-muted">Rp <?= number_format($f['credit_remaining']/1e6,1) ?>M</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($farmer_summary)): ?>
                        <tfoot class="table-light fw-semibold">
                            <tr>
                                <td colspan="4" class="text-end"><?php echo __('plasma_dash_totals'); ?>:</td>
                                <td class="text-end"><?= number_format(array_sum(array_column($farmer_summary, 'net_kg')) / 1000, 2) ?> t</td>
                                <td class="text-end">Rp <?= number_format(array_sum(array_column($farmer_summary, 'gross')) / 1e6, 2) ?>M</td>
                                <td class="text-end text-success">Rp <?= number_format(array_sum(array_column($farmer_summary, 'net_payout')) / 1e6, 2) ?>M</td>
                                <td class="text-end text-warning">Rp <?= number_format(array_sum(array_column($farmer_summary, 'loan_repaid')) / 1e6, 2) ?>M</td>
                                <td></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Attention / Action Required Panel -->
    <div class="col-md-4">

        <!-- Draft payments needing posting -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-warning"><i class="bi bi-exclamation-triangle me-1"></i><?php echo __('plasma_dash_attention'); ?></span>
            </div>
            <div class="card-body p-0">
                <?php if ($pay['draft'] > 0): ?>
                <div class="list-group list-group-flush">
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                        <div>
                            <div class="fw-semibold small"><?php echo __('plasma_dash_draft_pending'); ?></div>
                            <div class="text-muted" style="font-size:.78rem"><?php echo __('plasma_dash_draft_hint'); ?></div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-warning text-dark"><?= $pay['draft'] ?></span>
                            <a href="plasma_payments.php?status=draft&year=<?= $yr ?>" class="btn btn-xs btn-outline-warning" style="font-size:.75rem;padding:2px 8px"><?php echo __('plasma_dash_review'); ?></a>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                    <div class="p-3 text-center text-muted small">✓ <?php echo __('plasma_dash_no_draft'); ?></div>
                <?php endif; ?>

                <?php if (!empty($pending_gl)): ?>
                <div class="list-group list-group-flush border-top">
                    <div class="list-group-item py-2 bg-light">
                        <div class="fw-semibold small text-primary"><i class="bi bi-journal-arrow-up me-1"></i><?php echo __('plasma_dash_pending_gl_title'); ?></div>
                    </div>
                    <?php foreach ($pending_gl as $pg): ?>
                    <div class="list-group-item py-1 d-flex justify-content-between align-items-center">
                        <div>
                            <code style="font-size:.75rem"><?= htmlspecialchars($pg['payment_no']) ?></code><br>
                            <span style="font-size:.75rem" class="text-muted"><?= htmlspecialchars($pg['farmer_name']) ?> · <?= date('M Y', strtotime($pg['period_start'])) ?></span>
                        </div>
                        <form method="POST" action="plasma_payments.php" class="d-inline">
                            <input type="hidden" name="action"     value="post_journals">
                            <input type="hidden" name="payment_id" value="<?= $pg['id'] ?>">
                            <button type="submit" class="btn btn-outline-primary"
                                    style="font-size:.72rem;padding:2px 8px"
                                    onclick="return confirm('<?php echo __('plasma_gl_post_confirm'); ?>')">
                                <i class="bi bi-journal-arrow-up"></i> GL
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($ffb['pending_trips'] > 0): ?>
                <div class="list-group list-group-flush border-top">
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                        <div>
                            <div class="fw-semibold small"><?php echo __('plasma_dash_pending_deliveries'); ?></div>
                            <div class="text-muted" style="font-size:.78rem"><?php echo __('plasma_dash_pending_del_hint'); ?></div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary"><?= $ffb['pending_trips'] ?></span>
                            <a href="plasma_ffb_deliveries.php?status=pending" class="btn btn-outline-secondary" style="font-size:.75rem;padding:2px 8px"><?php echo __('plasma_dash_review'); ?></a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($ffb['rejected_trips'] > 0): ?>
                <div class="list-group list-group-flush border-top">
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                        <div>
                            <div class="fw-semibold small text-danger"><?php echo __('plasma_dash_rejected_deliveries'); ?></div>
                        </div>
                        <span class="badge bg-danger"><?= $ffb['rejected_trips'] ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quality summary -->
        <?php if (!empty($quality)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold small"><i class="bi bi-award me-1"></i><?php echo __('plasma_dash_quality_summary'); ?> <?= $yr ?></span>
            </div>
            <div class="card-body py-2 px-3">
                <?php
                $total_q_kg = array_sum(array_column($quality, 'net_kg'));
                foreach ($quality as $q):
                    $pct = $total_q_kg > 0 ? round($q['net_kg'] / $total_q_kg * 100, 1) : 0;
                    $color = $quality_colors[$q['quality_grade']] ?? '#888';
                ?>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge" style="background:<?= $color ?>;min-width:72px"><?= $q['quality_grade'] ?></span>
                    <div class="flex-grow-1">
                        <div class="progress" style="height:12px">
                            <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                        </div>
                    </div>
                    <span class="small text-muted" style="width:90px;text-align:right">
                        <?= number_format($q['net_kg'] / 1000, 1) ?>t (<?= $pct ?>%)
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div><!-- /ROW 4 -->

</div><!-- /content-wrapper -->

<?php
// Pre-build all JS data as plain strings — no PHP tags inside the script block
$js_months        = json_encode($months_short);
$js_ffb_data      = json_encode(array_values($ffb_chart));
$js_gross_data    = json_encode(array_values($pay_chart['gross']));
$js_net_data      = json_encode(array_values($pay_chart['net']));
$js_q_labels      = json_encode(array_column($quality, 'quality_grade'));
$js_q_data        = json_encode(array_map(fn($q) => round($q['net_kg'] / 1000, 2), $quality));
$js_q_colors      = json_encode(array_map(fn($q) => $quality_colors[$q['quality_grade']] ?? '#888', $quality));
$js_lbl_ffb       = json_encode(addslashes(__('plasma_dash_accepted_ffb')) . ' (ton)');
$js_lbl_gross     = json_encode(addslashes(__('plasma_dash_gross_ffb')) . ' (Rp M)');
$js_lbl_net       = json_encode(addslashes(__('plasma_dash_net_payout')) . ' (Rp M)');
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    var months = <?= $js_months ?>;

    // ── FFB Monthly Tonnage bar chart ─────────────────────────────────────────
    new Chart(document.getElementById('chartFFB'), {
        type: 'bar',
        data: {
            labels: months,
            datasets: [{
                label: <?= $js_lbl_ffb ?>,
                data: <?= $js_ffb_data ?>,
                backgroundColor: 'rgba(37,99,235,0.7)',
                borderColor: '#2563eb',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'ton' } }
            }
        }
    });

    // ── Payment monthly trend line chart ──────────────────────────────────────
    new Chart(document.getElementById('chartPay'), {
        type: 'line',
        data: {
            labels: months,
            datasets: [
                {
                    label: <?= $js_lbl_gross ?>,
                    data: <?= $js_gross_data ?>,
                    borderColor: '#0891b2',
                    backgroundColor: 'rgba(8,145,178,0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 4
                },
                {
                    label: <?= $js_lbl_net ?>,
                    data: <?= $js_net_data ?>,
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22,163,74,0.08)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true, title: { display: true, text: 'Rp million' } } }
        }
    });

    // ── Quality doughnut chart ────────────────────────────────────────────────
    var qCanvas = document.getElementById('chartQuality');
    if (qCanvas) {
        new Chart(qCanvas, {
            type: 'doughnut',
            data: {
                labels: <?= $js_q_labels ?>,
                datasets: [{
                    data: <?= $js_q_data ?>,
                    backgroundColor: <?= $js_q_colors ?>,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'right', labels: { font: { size: 11 } } },
                    tooltip: { callbacks: {
                        label: function(ctx) { return ctx.label + ': ' + ctx.parsed.toFixed(2) + ' t'; }
                    }}
                }
            }
        });
    }
})();
</script>
<?php
require_once 'includes/footer.php';
?>
