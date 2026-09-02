<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';

$db = getDB();
$page_title = __('cb_fx_title');

// ── POST handlers ─────────────────────────────────────────────────────────────
if (is_post()) {
    $action = post('action');

    if ($action === 'add') {
        try {
            $db->prepare("
                INSERT INTO exchange_rates
                    (currency_from, currency_to, rate_date, rate, rate_type, notes, created_by)
                VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    rate=VALUES(rate), rate_type=VALUES(rate_type),
                    notes=VALUES(notes), updated_at=NOW()
            ")->execute([
                strtoupper(trim(post('currency_from'))),
                'IDR',
                post('rate_date'),
                post('rate'),
                post('rate_type') ?: 'Manual',
                post('notes'),
                $_SESSION['username'] ?? 'admin',
            ]);
            set_message('success', __('cb_fx_msg_saved'));
        } catch (PDOException $e) {
            set_message('error', __('cb_fx_err_save') . $e->getMessage());
        }
        redirect('exchange_rates.php');
    }

    if ($action === 'delete') {
        try {
            $db->prepare("DELETE FROM exchange_rates WHERE id=?")->execute([intval(post('id'))]);
            set_message('success', __('cb_fx_msg_deleted'));
        } catch (PDOException $e) {
            set_message('error', __('cb_fx_err_delete') . $e->getMessage());
        }
        redirect('exchange_rates.php');
    }
}

// ── JSON endpoint: latest rate for a currency pair ─────────────────────────────
if (isset($_GET['latest_rate'])) {
    $cur = strtoupper(trim($_GET['latest_rate']));
    $row = $db->prepare("
        SELECT rate, rate_date, rate_type
        FROM exchange_rates
        WHERE currency_from=? AND currency_to='IDR'
        ORDER BY rate_date DESC LIMIT 1
    ");
    $row->execute([$cur]);
    header('Content-Type: application/json');
    echo json_encode($row->fetch(PDO::FETCH_ASSOC) ?: ['rate' => null]);
    exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_currency = trim($_GET['currency'] ?? '');
$filter_from     = trim($_GET['date_from'] ?? '');
$filter_to       = trim($_GET['date_to']   ?? date('Y-m-d'));

$where  = ['currency_to = ?'];
$params = ['IDR'];

if ($filter_currency) { $where[] = 'currency_from = ?'; $params[] = strtoupper($filter_currency); }
if ($filter_from)     { $where[] = 'rate_date >= ?';    $params[] = $filter_from; }
if ($filter_to)       { $where[] = 'rate_date <= ?';    $params[] = $filter_to; }

$sql   = "SELECT * FROM exchange_rates WHERE " . implode(' AND ', $where) . " ORDER BY rate_date DESC, currency_from ASC";
$stmt  = $db->prepare($sql);
$stmt->execute($params);
$rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Latest rate per currency (for summary row)
$latest = $db->query("
    SELECT er.*
    FROM exchange_rates er
    INNER JOIN (
        SELECT currency_from, MAX(rate_date) AS max_date
        FROM exchange_rates WHERE currency_to='IDR'
        GROUP BY currency_from
    ) mx ON er.currency_from=mx.currency_from AND er.rate_date=mx.max_date
    WHERE er.currency_to='IDR'
    ORDER BY er.currency_from
")->fetchAll(PDO::FETCH_ASSOC);

$currencies = ['USD','EUR','MYR','SGD','JPY','GBP','AUD','CNY','SAR'];

require_once 'includes/header.php';
?>

<div class="content-wrapper">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-currency-exchange"></i> <?php echo __('cb_fx_title'); ?></h1>
                <p class="text-muted mb-0"><?php echo __('cb_fx_subtitle'); ?></p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#fxModal">
                <i class="bi bi-plus-lg"></i> <?php echo __('cb_fx_add_btn'); ?>
            </button>
        </div>
    </div>

    <!-- Latest rates summary -->
    <?php if (!empty($latest)): ?>
    <div class="row g-3 mb-4">
        <?php foreach ($latest as $lr): ?>
        <div class="col-6 col-md-3 col-lg-2">
            <div class="card h-100 text-center">
                <div class="card-body py-2 px-3">
                    <div class="fw-bold text-primary fs-5"><?php echo htmlspecialchars($lr['currency_from']); ?></div>
                    <div class="fw-semibold">Rp <?php echo number_format($lr['rate'], 2, ',', '.'); ?></div>
                    <small class="text-muted"><?php echo date('d/m/Y', strtotime($lr['rate_date'])); ?></small>
                    <br><span class="badge bg-<?php echo $lr['rate_type']==='BI' ? 'success' : ($lr['rate_type']==='Reuters' ? 'info' : 'secondary'); ?> mt-1" style="font-size:0.7rem;"><?php echo $lr['rate_type']; ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1"><?php echo __('cb_fx_filter_currency'); ?></label>
                    <select name="currency" class="form-select form-select-sm">
                        <option value="">— <?php echo __('cb_fx_all_currencies'); ?> —</option>
                        <?php foreach ($currencies as $cur): ?>
                        <option value="<?php echo $cur; ?>" <?php echo ($filter_currency === $cur) ? 'selected' : ''; ?>><?php echo $cur; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1"><?php echo __('cb_fx_date_from'); ?></label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_from); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1"><?php echo __('cb_fx_date_to'); ?></label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_to); ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> <?php echo __('search'); ?></button>
                    <a href="exchange_rates.php" class="btn btn-sm btn-outline-secondary"><?php echo __('reset'); ?></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Rates table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table"></i> <?php echo __('cb_fx_list_header'); ?></span>
            <small class="text-white-50"><?php echo count($rates); ?> <?php echo __('records'); ?></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th><?php echo __('cb_fx_col_date'); ?></th>
                            <th><?php echo __('cb_fx_col_from'); ?></th>
                            <th><?php echo __('cb_fx_col_to'); ?></th>
                            <th class="text-end"><?php echo __('cb_fx_col_rate'); ?></th>
                            <th><?php echo __('cb_fx_col_type'); ?></th>
                            <th><?php echo __('cb_fx_col_notes'); ?></th>
                            <th><?php echo __('cb_fx_col_by'); ?></th>
                            <th><?php echo __('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rates)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4"><?php echo __('no_data'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($rates as $r): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($r['rate_date'])); ?></td>
                            <td><strong class="text-primary"><?php echo htmlspecialchars($r['currency_from']); ?></strong></td>
                            <td><strong>IDR</strong></td>
                            <td class="text-end"><strong>Rp <?php echo number_format($r['rate'], 6, '.', ','); ?></strong></td>
                            <td>
                                <span class="badge bg-<?php echo $r['rate_type']==='BI' ? 'success' : ($r['rate_type']==='Reuters' ? 'info text-dark' : 'secondary'); ?>">
                                    <?php echo htmlspecialchars($r['rate_type']); ?>
                                </span>
                            </td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($r['notes'] ?? ''); ?></small></td>
                            <td><small><?php echo htmlspecialchars($r['created_by'] ?? ''); ?></small></td>
                            <td>
                                <form method="POST" class="d-inline" onsubmit="return confirm(<?php echo json_encode(__('confirm_delete')); ?>)">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id"     value="<?php echo $r['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="<?php echo __('delete'); ?>">
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
</div>

<!-- Add Rate Modal -->
<div class="modal fade" id="fxModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> <?php echo __('cb_fx_modal_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="alert alert-info py-2 small">
                        <i class="bi bi-info-circle"></i> <?php echo __('cb_fx_modal_hint'); ?>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo __('cb_fx_field_from'); ?> *</label>
                            <select name="currency_from" class="form-select" required>
                                <?php foreach ($currencies as $cur): ?>
                                <option value="<?php echo $cur; ?>"><?php echo $cur; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo __('cb_fx_field_date'); ?> *</label>
                            <input type="date" name="rate_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo __('cb_fx_field_rate'); ?> *
                                <small class="fw-normal text-muted">(per 1 unit → IDR)</small>
                            </label>
                            <input type="number" name="rate" class="form-control" step="0.000001" min="0.000001" required placeholder="e.g. 15750.000000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo __('cb_fx_field_type'); ?></label>
                            <select name="rate_type" class="form-select">
                                <option value="Manual" selected>Manual</option>
                                <option value="BI">BI (Bank Indonesia)</option>
                                <option value="Reuters">Reuters</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('cb_fx_field_notes'); ?></label>
                            <input type="text" name="notes" class="form-control" maxlength="255" placeholder="e.g. BI Rate source, Reuters mid">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> <?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
