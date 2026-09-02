<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Cash Forecast";

// ─── Pre-flight: verify required tables exist ─────────────────────────────────
(function() use ($db) {
    $required = [
        'cf_subcategories'       => 'database/cash_bank_schema.sql',
        'cash_forecast_scenarios'=> 'database/ar_ap_cashforecast_schema.sql',
        'cash_forecast_lines'    => 'database/ar_ap_cashforecast_schema.sql',
        'ap_bills'               => 'database/ar_ap_cashforecast_schema.sql',
    ];
    $missing = [];
    foreach ($required as $table => $sqlFile) {
        $exists = $db->query("SHOW TABLES LIKE " . $db->quote($table))->fetchColumn();
        if (!$exists) {
            $missing[$table] = $sqlFile;
        }
    }
    if (empty($missing)) return;

    // Render friendly error and stop
    $page_title = "Cash Forecast — Setup Required";
    require_once 'includes/header.php';
    echo '<div class="content-wrapper"><div class="page-header">';
    echo '<h1><i class="bi bi-exclamation-triangle-fill text-warning"></i> Cash Forecast — Database Setup Required</h1>';
    echo '<p class="text-muted mb-0">The following tables are missing from the cloud database.</p>';
    echo '</div>';
    echo '<div class="alert alert-danger mt-3"><strong>Missing tables detected on this server:</strong><ul class="mb-0 mt-2">';
    $files = array_unique(array_values($missing));
    foreach ($missing as $t => $f) {
        echo '<li><code>' . htmlspecialchars($t) . '</code> &nbsp;→ run <code>' . htmlspecialchars($f) . '</code></li>';
    }
    echo '</ul></div>';
    echo '<div class="card"><div class="card-header"><i class="bi bi-wrench-adjustable"></i> How to fix</div>';
    echo '<div class="card-body"><ol>';
    echo '<li>Log in to <strong>Hostinger phpMyAdmin</strong> and select database <code>u208932211_inodesain</code>.</li>';
    foreach ($files as $f) {
        echo '<li>Open the <strong>SQL</strong> tab and paste/import <code>' . htmlspecialchars($f) . '</code> from your project.</li>';
    }
    echo '<li>Refresh this page — it will load normally once all tables exist.</li>';
    echo '</ol></div></div></div>';
    require_once 'includes/footer.php';
    exit;
})();

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // Create scenario
    if ($action === 'create_scenario') {
        try {
            $db->prepare("
                INSERT INTO cash_forecast_scenarios
                    (company_id, scenario_name, scenario_type, period_from, period_to,
                     period_unit, opening_balance, notes, status, created_by)
                VALUES (?,?,?,?,?, ?,?,?,'active','admin')
            ")->execute([
                (int)  post('company_id'),
                post('scenario_name'),
                post('scenario_type') ?: 'base',
                post('period_from'),
                post('period_to'),
                post('period_unit') ?: 'weekly',
                (float) post('opening_balance') ?: 0,
                post('notes'),
            ]);
            $sid = $db->lastInsertId();
            set_message('success', 'Scenario created!');
            redirect("cash_forecast.php?scenario_id=$sid");
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
            redirect('cash_forecast.php');
        }
    }

    // Add manual forecast line
    if ($action === 'add_line') {
        try {
            $db->prepare("
                INSERT INTO cash_forecast_lines
                    (scenario_id, forecast_date, flow_direction, cf_subcategory_id,
                     source_type, amount_idr, probability_pct, description, created_by)
                VALUES (?,?,?,?, 'manual',?,?,?,'admin')
            ")->execute([
                (int)  post('scenario_id'),
                post('forecast_date'),
                post('flow_direction'),
                post('cf_subcategory_id') ?: null,
                (float) post('amount_idr'),
                (int)  post('probability_pct') ?: 100,
                post('description'),
            ]);
            set_message('success', 'Forecast line added.');
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect("cash_forecast.php?scenario_id=" . (int) post('scenario_id'));
    }

    // Delete forecast line
    if ($action === 'delete_line') {
        $db->prepare("DELETE FROM cash_forecast_lines WHERE id=?")->execute([(int)post('line_id')]);
        set_message('success', 'Line deleted.');
        redirect("cash_forecast.php?scenario_id=" . (int)post('scenario_id'));
    }

    // Rebuild scenario from AR + AP (auto-populate)
    if ($action === 'auto_populate') {
        try {
            $sid    = (int) post('scenario_id');
            $source = post('populate_source'); // 'ar' | 'ap' | 'both'
            $scen   = $db->prepare("SELECT * FROM cash_forecast_scenarios WHERE id=?")->execute([$sid]) ? $db->query("SELECT * FROM cash_forecast_scenarios WHERE id=$sid")->fetch() : null;
            if (!$scen) throw new RuntimeException("Scenario not found");

            $inserted = 0;

            // Remove old auto lines for this scenario
            $db->prepare("DELETE FROM cash_forecast_lines WHERE scenario_id=? AND source_type IN ('ar_invoice','ap_bill')")->execute([$sid]);

            if (in_array($source, ['ar','both'])) {
                // Pull AR: outstanding invoices with expected_collection_date or due_date within period
                $arRows = $db->prepare("
                    SELECT s.sale_id,
                           COALESCE(s.expected_collection_date, s.due_date) AS forecast_date,
                           s.outstanding_amount,
                           s.invoice_number, c.customer_name,
                           'CF-OR-01' AS cf_code
                    FROM sales s
                    JOIN customers c ON s.customer_id = c.customer_id
                    WHERE s.company_id = ?
                      AND s.payment_status IN ('pending','partial')
                      AND s.outstanding_amount > 0
                      AND s.credit_risk NOT IN ('bad')
                      AND COALESCE(s.expected_collection_date, s.due_date) BETWEEN ? AND ?
                ");
                $arRows->execute([$scen['company_id'], $scen['period_from'], $scen['period_to']]);
                $cfOrId = $db->query("SELECT id FROM cf_subcategories WHERE code='CF-OR-01' LIMIT 1")->fetchColumn() ?: null;

                $insLine = $db->prepare("
                    INSERT INTO cash_forecast_lines
                        (scenario_id, forecast_date, flow_direction, cf_subcategory_id,
                         source_type, source_ar_sale_id, amount_idr, probability_pct,
                         description, created_by)
                    VALUES (?, ?, 'inflow', ?, 'ar_invoice', ?, ?, ?, ?, 'admin')
                ");
                foreach ($arRows as $row) {
                    $insLine->execute([
                        $sid,
                        $row['forecast_date'],
                        $cfOrId,
                        $row['sale_id'],
                        $row['outstanding_amount'],
                        90,
                        'AR: ' . $row['invoice_number'] . ' – ' . $row['customer_name'],
                    ]);
                    $inserted++;
                }
            }

            if (in_array($source, ['ap','both'])) {
                // Pull AP: approved bills due within period
                $apRows = $db->prepare("
                    SELECT b.id, COALESCE(b.planned_payment_date, b.due_date) AS forecast_date,
                           b.outstanding_amount, b.vendor_name, b.bill_number,
                           b.cf_subcategory_id
                    FROM ap_bills b
                    WHERE b.company_id = ?
                      AND b.status IN ('draft','approved')
                      AND b.outstanding_amount > 0
                      AND COALESCE(b.planned_payment_date, b.due_date) BETWEEN ? AND ?
                ");
                $apRows->execute([$scen['company_id'], $scen['period_from'], $scen['period_to']]);

                $insAP = $db->prepare("
                    INSERT INTO cash_forecast_lines
                        (scenario_id, forecast_date, flow_direction, cf_subcategory_id,
                         source_type, source_ap_bill_id, amount_idr, probability_pct,
                         description, created_by)
                    VALUES (?, ?, 'outflow', ?, 'ap_bill', ?, ?, 100, ?, 'admin')
                ");
                foreach ($apRows as $row) {
                    $insAP->execute([
                        $sid,
                        $row['forecast_date'],
                        $row['cf_subcategory_id'],
                        $row['id'],
                        $row['outstanding_amount'],
                        'AP: ' . $row['bill_number'] . ' – ' . $row['vendor_name'],
                    ]);
                    $inserted++;
                }
            }

            set_message('success', "Auto-populated $inserted forecast lines from " . strtoupper($source) . ".");
        } catch (Exception $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
        redirect("cash_forecast.php?scenario_id=" . (int)post('scenario_id'));
    }
}

require_once 'includes/header.php';

// ─── Load scenario list ────────────────────────────────────────────────────────
$scenarios = $db->query("
    SELECT s.*, co.company_name
    FROM cash_forecast_scenarios s
    JOIN companies co ON s.company_id = co.company_id
    ORDER BY s.created_at DESC
")->fetchAll();

$scenario_id = (int) get('scenario_id', 0);
$scenario    = null;
$lines       = [];
$timeline    = [];
$cfSubs      = $db->query("SELECT id, code, name, cf_category FROM cf_subcategories ORDER BY display_order")->fetchAll();
$companies   = $db->query("SELECT company_id, company_name FROM companies ORDER BY company_name")->fetchAll();

if ($scenario_id) {
    $scenario = $db->prepare("
        SELECT s.*, co.company_name
        FROM cash_forecast_scenarios s
        JOIN companies co ON s.company_id = co.company_id
        WHERE s.id = ?
    ");
    $scenario->execute([$scenario_id]);
    $scenario = $scenario->fetch();

    if ($scenario) {
        // Load all lines
        $lines = $db->prepare("
            SELECT fl.*, cs.code AS cf_code, cs.name AS cf_name
            FROM cash_forecast_lines fl
            LEFT JOIN cf_subcategories cs ON fl.cf_subcategory_id = cs.id
            WHERE fl.scenario_id = ?
            ORDER BY fl.forecast_date ASC, fl.flow_direction DESC
        ");
        $lines->execute([$scenario_id]);
        $lines = $lines->fetchAll();

        // Build weekly/monthly timeline for the chart
        $interval = $scenario['period_unit'] === 'daily'   ? 'P1D'
                  : ($scenario['period_unit'] === 'monthly' ? 'P1M' : 'P7D');

        $cur = new DateTime($scenario['period_from']);
        $end = new DateTime($scenario['period_to']);
        $runningBalance = (float) $scenario['opening_balance'];

        $buckets = [];
        while ($cur <= $end) {
            $key = $cur->format('Y-m-d');
            $buckets[$key] = ['label' => $cur->format('d M'), 'inflow' => 0, 'outflow' => 0];
            $cur->add(new DateInterval($interval));
        }

        foreach ($lines as $ln) {
            // assign line to nearest bucket
            $ld = $ln['forecast_date'];
            $closestKey = null;
            $closestDiff = PHP_INT_MAX;
            foreach (array_keys($buckets) as $bk) {
                $diff = abs(strtotime($ld) - strtotime($bk));
                if ($diff < $closestDiff) { $closestDiff = $diff; $closestKey = $bk; }
            }
            if ($closestKey) {
                if ($ln['flow_direction'] === 'inflow')
                    $buckets[$closestKey]['inflow']  += (float)$ln['weighted_amount_idr'];
                else
                    $buckets[$closestKey]['outflow'] += (float)$ln['weighted_amount_idr'];
            }
        }

        // Build cumulative timeline with running balance
        $balance = (float) $scenario['opening_balance'];
        foreach ($buckets as $bk => &$bv) {
            $balance += $bv['inflow'] - $bv['outflow'];
            $bv['balance'] = $balance;
        }
        $timeline = $buckets;

        // Totals
        $totalInflow  = array_sum(array_column(array_filter($lines, fn($l) => $l['flow_direction']==='inflow'),  'weighted_amount_idr'));
        $totalOutflow = array_sum(array_column(array_filter($lines, fn($l) => $l['flow_direction']==='outflow'), 'weighted_amount_idr'));
        $closingBalance = (float)$scenario['opening_balance'] + $totalInflow - $totalOutflow;
    }
}
?>

<div class="content-wrapper">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-graph-up-arrow"></i> Cash Forecast</h1>
                <p class="text-muted mb-0">Rolling cash projection · AR inflows · AP outflows · manual entries</p>
            </div>
            <div>
                <a href="ar_receivables.php" class="btn btn-outline-primary me-2"><i class="bi bi-receipt-cutoff"></i> AR</a>
                <a href="ap_bills.php"       class="btn btn-outline-danger me-2"><i class="bi bi-file-earmark-text"></i> AP</a>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createScenarioModal">
                    <i class="bi bi-plus-circle"></i> New Scenario
                </button>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Scenario list sidebar -->
        <div class="col-md-3">
            <div class="card">
                <div class="card-header"><i class="bi bi-collection"></i> Scenarios</div>
                <div class="list-group list-group-flush">
                    <?php if (empty($scenarios)): ?>
                        <div class="list-group-item text-muted small">No scenarios yet. Create one →</div>
                    <?php else: ?>
                        <?php foreach ($scenarios as $sc): ?>
                            <a href="cash_forecast.php?scenario_id=<?= $sc['id'] ?>"
                               class="list-group-item list-group-item-action <?= $scenario_id == $sc['id'] ? 'active' : '' ?>">
                                <div class="d-flex justify-content-between">
                                    <strong class="small"><?= htmlspecialchars($sc['scenario_name']) ?></strong>
                                    <span class="badge bg-<?= ['base'=>'primary','optimistic'=>'success','pessimistic'=>'warning','stress'=>'danger'][$sc['scenario_type']] ?? 'secondary' ?> ms-1">
                                        <?= ucfirst($sc['scenario_type']) ?>
                                    </span>
                                </div>
                                <small class="text-muted d-block"><?= $sc['company_name'] ?></small>
                                <small class="text-muted"><?= format_date($sc['period_from']) ?> – <?= format_date($sc['period_to']) ?></small>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main content -->
        <div class="col-md-9">
            <?php if (!$scenario): ?>
                <div class="card text-center py-5">
                    <div class="card-body">
                        <i class="bi bi-graph-up-arrow display-4 text-muted"></i>
                        <h5 class="mt-3 text-muted">Select or create a forecast scenario</h5>
                        <p class="text-muted small">A scenario defines the period, company, and opening cash balance for your forecast.</p>
                        <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#createScenarioModal">
                            <i class="bi bi-plus-circle"></i> New Scenario
                        </button>
                    </div>
                </div>
            <?php else: ?>

                <!-- Scenario header -->
                <div class="card mb-3">
                    <div class="card-body py-2">
                        <div class="row g-2 align-items-center">
                            <div class="col-auto">
                                <h5 class="mb-0"><?= htmlspecialchars($scenario['scenario_name']) ?></h5>
                                <small class="text-muted"><?= $scenario['company_name'] ?> · <?= format_date($scenario['period_from']) ?> – <?= format_date($scenario['period_to']) ?> · <?= ucfirst($scenario['period_unit']) ?></small>
                            </div>
                            <div class="col-auto ms-auto">
                                <!-- Auto-populate from AR/AP -->
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="auto_populate">
                                    <input type="hidden" name="scenario_id" value="<?= $scenario_id ?>">
                                    <select name="populate_source" class="form-select form-select-sm d-inline-block" style="width:auto">
                                        <option value="both">AR + AP</option>
                                        <option value="ar">AR only</option>
                                        <option value="ap">AP only</option>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-primary ms-1"
                                            onclick="return confirm('This will replace all auto-lines in this scenario. Proceed?')">
                                        <i class="bi bi-arrow-repeat"></i> Auto-populate
                                    </button>
                                </form>
                                <button class="btn btn-sm btn-outline-success ms-2"
                                        data-bs-toggle="modal" data-bs-target="#addLineModal">
                                    <i class="bi bi-plus"></i> Add Line
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary stat cards -->
                <?php if (isset($totalInflow)): ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <div class="card stat-card text-center">
                            <div class="card-body py-3">
                                <h6 class="text-muted small mb-1">Opening Balance</h6>
                                <h5 class="mb-0">Rp <?= number_format($scenario['opening_balance'],0) ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card border-start border-success border-4 text-center">
                            <div class="card-body py-3">
                                <h6 class="text-muted small mb-1">Total Inflows</h6>
                                <h5 class="mb-0 text-success">+ Rp <?= number_format($totalInflow,0) ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card border-start border-danger border-4 text-center">
                            <div class="card-body py-3">
                                <h6 class="text-muted small mb-1">Total Outflows</h6>
                                <h5 class="mb-0 text-danger">– Rp <?= number_format($totalOutflow,0) ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card border-start <?= $closingBalance >= 0 ? 'border-primary' : 'border-danger' ?> border-4 text-center">
                            <div class="card-body py-3">
                                <h6 class="text-muted small mb-1">Closing Balance</h6>
                                <h5 class="mb-0 <?= $closingBalance >= 0 ? 'text-primary' : 'text-danger' ?>">
                                    Rp <?= number_format($closingBalance,0) ?>
                                </h5>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Timeline bar chart (pure HTML/CSS) -->
                <?php if (!empty($timeline)): ?>
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-bar-chart-line"></i> Cash Flow Timeline</div>
                    <div class="card-body">
                        <div style="overflow-x:auto">
                            <div style="display:flex;gap:6px;align-items:flex-end;min-height:120px;padding-bottom:24px;position:relative;min-width:<?= count($timeline)*70 ?>px">
                                <?php
                                $maxVal = 1;
                                foreach ($timeline as $bv) $maxVal = max($maxVal, $bv['inflow'], $bv['outflow']);
                                foreach ($timeline as $bk => $bv):
                                    $inH  = round(($bv['inflow']  / $maxVal) * 100);
                                    $outH = round(($bv['outflow'] / $maxVal) * 100);
                                    $balColor = $bv['balance'] >= 0 ? '#3b82d4' : '#ef4444';
                                ?>
                                <div style="flex:1;text-align:center;min-width:60px">
                                    <div style="display:flex;justify-content:center;align-items:flex-end;gap:2px;height:100px">
                                        <div title="Inflow: Rp <?= number_format($bv['inflow'],0) ?>"
                                             style="width:14px;height:<?= $inH ?>%;background:#22c55e;border-radius:2px 2px 0 0;min-height:<?= $bv['inflow']>0?'2':'0' ?>px"></div>
                                        <div title="Outflow: Rp <?= number_format($bv['outflow'],0) ?>"
                                             style="width:14px;height:<?= $outH ?>%;background:#ef4444;border-radius:2px 2px 0 0;min-height:<?= $bv['outflow']>0?'2':'0' ?>px"></div>
                                    </div>
                                    <div style="font-size:0.65rem;color:#555;margin-top:4px;white-space:nowrap"><?= $bv['label'] ?></div>
                                    <div style="font-size:0.6rem;color:<?= $balColor ?>;font-weight:700"><?= number_format($bv['balance']/1e6,1) ?>M</div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-3 mt-1">
                            <small><span style="display:inline-block;width:12px;height:12px;background:#22c55e;border-radius:2px"></span> Inflow</small>
                            <small><span style="display:inline-block;width:12px;height:12px;background:#ef4444;border-radius:2px"></span> Outflow</small>
                            <small class="text-muted">Balance labels in millions IDR</small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Forecast lines table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between">
                        <span><i class="bi bi-list-ul"></i> Forecast Lines (<?= count($lines) ?>)</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Direction</th>
                                        <th>CF Code</th>
                                        <th>Description</th>
                                        <th>Source</th>
                                        <th class="text-end">Amount (IDR)</th>
                                        <th class="text-end">Prob %</th>
                                        <th class="text-end">Weighted</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($lines)): ?>
                                        <tr><td colspan="9" class="text-center text-muted py-4">
                                            No forecast lines yet. Use <strong>Auto-populate</strong> or <strong>Add Line</strong>.
                                        </td></tr>
                                    <?php else: ?>
                                        <?php foreach ($lines as $ln): ?>
                                        <tr>
                                            <td><?= format_date($ln['forecast_date']) ?></td>
                                            <td>
                                                <?php if ($ln['flow_direction']==='inflow'): ?>
                                                    <span class="badge bg-success"><i class="bi bi-arrow-down"></i> Inflow</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger"><i class="bi bi-arrow-up"></i> Outflow</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $ln['cf_code'] ? "<code>{$ln['cf_code']}</code>" : '<span class="text-muted">—</span>' ?></td>
                                            <td class="small"><?= htmlspecialchars($ln['description']) ?></td>
                                            <td>
                                                <?php
                                                $srcBadge = ['ar_invoice'=>'primary','ap_bill'=>'warning','budget'=>'info','manual'=>'secondary'];
                                                $sc = $srcBadge[$ln['source_type']] ?? 'secondary';
                                                echo "<span class='badge bg-$sc'>" . ucfirst(str_replace('_',' ',$ln['source_type'])) . "</span>";
                                                ?>
                                            </td>
                                            <td class="text-end">Rp <?= number_format($ln['amount_idr'],0) ?></td>
                                            <td class="text-end"><?= $ln['probability_pct'] ?>%</td>
                                            <td class="text-end fw-bold">Rp <?= number_format($ln['weighted_amount_idr'],0) ?></td>
                                            <td>
                                                <?php if ($ln['source_type'] === 'manual'): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this line?')">
                                                    <input type="hidden" name="action" value="delete_line">
                                                    <input type="hidden" name="line_id" value="<?= $ln['id'] ?>">
                                                    <input type="hidden" name="scenario_id" value="<?= $scenario_id ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (!empty($lines) && isset($totalInflow)): ?>
                                <tfoot class="table-light fw-bold">
                                    <tr>
                                        <td colspan="5">Totals (weighted)</td>
                                        <td class="text-end" colspan="2"></td>
                                        <td class="text-end">
                                            <span class="text-success">+<?= number_format($totalInflow,0) ?></span> /
                                            <span class="text-danger">-<?= number_format($totalOutflow,0) ?></span>
                                        </td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create Scenario Modal -->
<div class="modal fade" id="createScenarioModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_scenario">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> New Forecast Scenario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Scenario Name <span class="text-danger">*</span></label>
                        <input type="text" name="scenario_name" class="form-control" placeholder="e.g. Base Case Aug 2025" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">Company <span class="text-danger">*</span></label>
                            <select name="company_id" class="form-select" required>
                                <option value="">— Select —</option>
                                <?php foreach ($companies as $co): ?>
                                    <option value="<?= $co['company_id'] ?>"><?= htmlspecialchars($co['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Scenario Type</label>
                            <select name="scenario_type" class="form-select">
                                <option value="base">Base Case</option>
                                <option value="optimistic">Optimistic</option>
                                <option value="pessimistic">Pessimistic</option>
                                <option value="stress">Stress Test</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Period From <span class="text-danger">*</span></label>
                            <input type="date" name="period_from" class="form-control" value="<?= date('Y-m-01') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Period To <span class="text-danger">*</span></label>
                            <input type="date" name="period_to"   class="form-control" value="<?= date('Y-m-t', strtotime('+2 months')) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Period Unit</label>
                            <select name="period_unit" class="form-select">
                                <option value="weekly" selected>Weekly</option>
                                <option value="daily">Daily</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Opening Cash Balance (IDR)</label>
                            <input type="number" name="opening_balance" class="form-control" step="1" value="0" placeholder="0">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create Scenario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Manual Line Modal -->
<div class="modal fade" id="addLineModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_line">
                <input type="hidden" name="scenario_id" value="<?= $scenario_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus"></i> Add Forecast Line</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" name="forecast_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Direction <span class="text-danger">*</span></label>
                            <select name="flow_direction" class="form-select" required>
                                <option value="inflow">Inflow (receipt)</option>
                                <option value="outflow">Outflow (payment)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">CF Classification</label>
                            <select name="cf_subcategory_id" class="form-select">
                                <option value="">— None —</option>
                                <?php foreach ($cfSubs as $cf): ?>
                                    <option value="<?= $cf['id'] ?>"><?= htmlspecialchars($cf['code'] . ' – ' . $cf['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-8">
                            <label class="form-label">Amount (IDR) <span class="text-danger">*</span></label>
                            <input type="number" name="amount_idr" class="form-control" step="1" min="0" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Probability %</label>
                            <input type="number" name="probability_pct" class="form-control" min="0" max="100" value="100">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" name="description" class="form-control" required placeholder="e.g. Payroll July 2025">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Add Line</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
