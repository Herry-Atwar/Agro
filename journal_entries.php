<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = __('pt_journal_entries');

// -------------------------------------------------------
// Current user defaults — read from session (set by login_user())
// -------------------------------------------------------
$default_company_id  = !empty($_SESSION['company_id'])       ? (int)$_SESSION['company_id']       : null;
$default_bu_id       = !empty($_SESSION['business_unit_id']) ? (int)$_SESSION['business_unit_id'] : null;
$default_division_id = !empty($_SESSION['division_id'])      ? (int)$_SESSION['division_id']      : null;

// Load today's currency rates for JS
$rates_stmt = $db->prepare("
    SELECT from_currency, rate
    FROM currency_rates
    WHERE rate_date = (
        SELECT MAX(rate_date) FROM currency_rates cr2
        WHERE cr2.from_currency = currency_rates.from_currency
          AND cr2.to_currency = 'IDR'
    ) AND to_currency = 'IDR'
");
$rates_stmt->execute();
$currency_rates_map = [];
foreach ($rates_stmt->fetchAll() as $r) {
    $currency_rates_map[$r['from_currency']] = (float)$r['rate'];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'create_entry') {
        try {
            $db->beginTransaction();
            
            // Generate reference number: JE-{DivisionCode}-YYYYMM-NNNN
            // Resolve division_code from the submitted division_id
            $entry_date_val  = post('entry_date') ?: date('Y-m-d');
            $v_yyyymm        = date('Ym', strtotime($entry_date_val));
            $submitted_div   = post('division_id') ?: null;

            $div_code = null;
            if ($submitted_div) {
                $dstmt = $db->prepare("SELECT division_code FROM divisions WHERE division_id = ? LIMIT 1");
                $dstmt->execute([$submitted_div]);
                $div_code = $dstmt->fetchColumn() ?: null;
            }

            // Build prefix and pattern to find next sequence for this division+month
            if ($div_code) {
                $ref_prefix  = 'JE-' . $div_code . '-' . $v_yyyymm . '-';
            } else {
                $ref_prefix  = 'JE-' . $v_yyyymm . '-';
            }

            $stmt = $db->prepare("
                SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reference_number, '-', -1) AS UNSIGNED)), 0) + 1 AS next_num
                FROM journal_entries
                WHERE reference_number LIKE ?
            ");
            $stmt->execute([$ref_prefix . '%']);
            $next_num = (int)$stmt->fetchColumn();
            $reference_number = $ref_prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);
            
            // Currency fields
            $currency_code  = post('currency_code', 'IDR');
            $exchange_rate  = floatval(post('exchange_rate', 1));
            if ($currency_code === 'IDR' || $exchange_rate <= 0) {
                $currency_code = 'IDR';
                $exchange_rate = 1.0;
            }

            // Insert journal entry header
            $stmt = $db->prepare("
                INSERT INTO journal_entries (
                    entry_date, entry_type, reference_number, description,
                    company_id, business_unit_id, division_id, block_id,
                    planting_year_id, activity_id,
                    currency_code, exchange_rate,
                    status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
            ");
            
            $stmt->execute([
                post('entry_date'),
                post('entry_type'),
                $reference_number,
                post('description'),
                post('company_id') ?: null,
                post('business_unit_id') ?: null,
                post('division_id') ?: null,
                post('block_id') ?: null,
                post('planting_year_id') ?: null,
                post('activity_id') ?: null,
                $currency_code,
                $exchange_rate,
                'Admin' // Replace with actual user
            ]);
            
            $journal_entry_id = $db->lastInsertId();
            
            // Insert journal entry lines
            $line_accounts        = post('line_account', []);
            $line_debits          = post('line_debit', []);
            $line_credits         = post('line_credit', []);
            $line_descriptions    = post('line_description', []);
            $line_cost_categories = post('line_cost_category', []);
            $line_fc_amounts      = post('line_fc_amount', []);
            $line_block_ids       = post('line_block_id', []);
            $line_activity_ids    = post('line_activity_id', []);

            $line_number = 1;
            foreach ($line_accounts as $index => $gl_account_id) {
                if (empty($gl_account_id)) continue;

                $debit  = floatval($line_debits[$index]  ?? 0);
                $credit = floatval($line_credits[$index] ?? 0);

                if ($debit == 0 && $credit == 0) continue;

                // Foreign-currency amount entered by user
                $fc_amount  = floatval($line_fc_amounts[$index] ?? 0);
                $is_foreign = ($currency_code !== 'IDR' && $fc_amount > 0);

                $base_debit  = $debit;
                $base_credit = $credit;

                $line_currency = $is_foreign ? $currency_code : 'IDR';
                $line_ex_rate  = $is_foreign ? $exchange_rate  : 1.0;
                $fc_debit_val  = ($is_foreign && $debit  > 0) ? $fc_amount : 0;
                $fc_credit_val = ($is_foreign && $credit > 0) ? $fc_amount : 0;

                $stmt = $db->prepare("
                    INSERT INTO journal_entry_lines (
                        journal_entry_id, line_number, gl_account_id,
                        activity_id,
                        debit_amount, credit_amount,
                        currency_code, exchange_rate,
                        base_currency_debit, base_currency_credit,
                        description, cost_category, cost_type,
                        company_id, business_unit_id, division_id, block_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $journal_entry_id,
                    $line_number++,
                    $gl_account_id,
                    $line_activity_ids[$index] ?: null,
                    $is_foreign ? $fc_debit_val  : $debit,
                    $is_foreign ? $fc_credit_val : $credit,
                    $line_currency,
                    $line_ex_rate,
                    $base_debit,
                    $base_credit,
                    $line_descriptions[$index]    ?? null,
                    $line_cost_categories[$index] ?? null,
                    'direct',
                    post('company_id') ?: null,
                    post('business_unit_id') ?: null,
                    post('division_id') ?: null,
                    $line_block_ids[$index] ?: null
                ]);
            }

            // Recalculate header totals from lines (replaces missing DB triggers)
            $db->prepare("
                UPDATE journal_entries je
                SET total_debit  = (SELECT COALESCE(SUM(base_currency_debit),  0) FROM journal_entry_lines WHERE journal_entry_id = je.id),
                    total_credit = (SELECT COALESCE(SUM(base_currency_credit), 0) FROM journal_entry_lines WHERE journal_entry_id = je.id),
                    updated_at   = NOW()
                WHERE je.id = ?
            ")->execute([$journal_entry_id]);

            $db->commit();
            set_message('success', "Journal entry $reference_number created successfully!");
            redirect('journal_entries.php');
            
        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', 'Error creating journal entry: ' . $e->getMessage());
        }
    } elseif ($action === 'post_entry') {
        try {
            $stmt = $db->prepare("
                UPDATE journal_entries
                SET status = 'posted', posted_date = NOW(), posted_by = ?, updated_at = NOW()
                WHERE id = ? AND status = 'draft'
            ");
            $stmt->execute(['Admin', post('entry_id')]);
            
            set_message('success', 'Journal entry posted successfully!');
            redirect('journal_entries.php');
        } catch (Exception $e) {
            set_message('error', 'Error posting entry: ' . $e->getMessage());
        }
    } elseif ($action === 'delete_entry') {
        try {
            $stmt = $db->prepare("DELETE FROM journal_entries WHERE id = ? AND status = 'draft'");
            $stmt->execute([post('entry_id')]);
            
            set_message('success', 'Journal entry deleted successfully!');
            redirect('journal_entries.php');
        } catch (Exception $e) {
            set_message('error', 'Error deleting entry: ' . $e->getMessage());
        }
    } elseif ($action === 'update_entry') {
        try {
            $entry_id = post('entry_id');

            $currency_code = post('currency_code', 'IDR');
            $exchange_rate = floatval(post('exchange_rate', 1));
            if ($currency_code === 'IDR' || $exchange_rate <= 0) {
                $currency_code = 'IDR';
                $exchange_rate = 1.0;
            }

            $db->beginTransaction();

            // Update header (draft only)
            $stmt = $db->prepare("
                UPDATE journal_entries
                SET entry_date        = ?,
                    entry_type        = ?,
                    description       = ?,
                    company_id        = ?,
                    business_unit_id  = ?,
                    division_id       = ?,
                    currency_code     = ?,
                    exchange_rate     = ?,
                    updated_at        = NOW()
                WHERE id = ? AND status = 'draft'
            ");
            $stmt->execute([
                post('entry_date'),
                post('entry_type'),
                post('description'),
                post('company_id') ?: null,
                post('business_unit_id') ?: null,
                post('division_id') ?: null,
                $currency_code,
                $exchange_rate,
                $entry_id,
            ]);

            // Replace all lines
            $del = $db->prepare("DELETE FROM journal_entry_lines WHERE journal_entry_id = ?");
            $del->execute([$entry_id]);

            $line_accounts        = post('line_account', []);
            $line_debits          = post('line_debit', []);
            $line_credits         = post('line_credit', []);
            $line_descriptions    = post('line_description', []);
            $line_cost_categories = post('line_cost_category', []);
            $line_fc_amounts      = post('line_fc_amount', []);
            $line_block_ids       = post('line_block_id', []);
            $line_activity_ids    = post('line_activity_id', []);

            $line_number = 1;
            foreach ($line_accounts as $index => $gl_account_id) {
                if (empty($gl_account_id)) continue;

                $debit  = floatval($line_debits[$index]  ?? 0);
                $credit = floatval($line_credits[$index] ?? 0);
                if ($debit == 0 && $credit == 0) continue;

                $fc_amount  = floatval($line_fc_amounts[$index] ?? 0);
                $is_foreign = ($currency_code !== 'IDR' && $fc_amount > 0);

                $base_debit  = $debit;
                $base_credit = $credit;

                $line_currency = $is_foreign ? $currency_code : 'IDR';
                $line_ex_rate  = $is_foreign ? $exchange_rate  : 1.0;
                $fc_debit_val  = ($is_foreign && $debit  > 0) ? $fc_amount : 0;
                $fc_credit_val = ($is_foreign && $credit > 0) ? $fc_amount : 0;

                $stmt = $db->prepare("
                    INSERT INTO journal_entry_lines (
                        journal_entry_id, line_number, gl_account_id,
                        activity_id,
                        debit_amount, credit_amount,
                        currency_code, exchange_rate,
                        base_currency_debit, base_currency_credit,
                        description, cost_category, cost_type,
                        company_id, business_unit_id, division_id, block_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $entry_id,
                    $line_number++,
                    $gl_account_id,
                    $line_activity_ids[$index] ?: null,
                    $is_foreign ? $fc_debit_val  : $debit,
                    $is_foreign ? $fc_credit_val : $credit,
                    $line_currency,
                    $line_ex_rate,
                    $base_debit,
                    $base_credit,
                    $line_descriptions[$index]    ?? null,
                    $line_cost_categories[$index] ?? null,
                    'direct',
                    post('company_id') ?: null,
                    post('business_unit_id') ?: null,
                    post('division_id') ?: null,
                    $line_block_ids[$index] ?: null,
                ]);
            }

            // Recalculate header totals from lines (replaces missing DB triggers)
            $db->prepare("
                UPDATE journal_entries je
                SET total_debit  = (SELECT COALESCE(SUM(base_currency_debit),  0) FROM journal_entry_lines WHERE journal_entry_id = je.id),
                    total_credit = (SELECT COALESCE(SUM(base_currency_credit), 0) FROM journal_entry_lines WHERE journal_entry_id = je.id),
                    updated_at   = NOW()
                WHERE je.id = ?
            ")->execute([$entry_id]);

            $db->commit();
            set_message('success', 'Journal entry updated successfully!');
            redirect('journal_entries.php');

        } catch (Exception $e) {
            $db->rollBack();
            set_message('error', 'Error updating journal entry: ' . $e->getMessage());
        }
    }
}

// Get filters
$status_filter = get('status', '');
$type_filter = get('type', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');
$search = get('search', '');

// Build query
$where_clauses = [];
$params = [];

// ── Auto-filter by company / BU / division from session ──────────────────────
if ($default_company_id) {
    $where_clauses[] = "je.company_id = ?";
    $params[] = $default_company_id;
}
if ($default_bu_id) {
    $where_clauses[] = "je.business_unit_id = ?";
    $params[] = $default_bu_id;
}
if ($default_division_id) {
    $where_clauses[] = "je.division_id = ?";
    $params[] = $default_division_id;
}

if ($status_filter) {
    $where_clauses[] = "je.status = ?";
    $params[] = $status_filter;
}

if ($type_filter) {
    $where_clauses[] = "je.entry_type = ?";
    $params[] = $type_filter;
}

if ($date_from) {
    $where_clauses[] = "je.entry_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_clauses[] = "je.entry_date <= ?";
    $params[] = $date_to;
}

if ($search) {
    $where_clauses[] = "(je.reference_number LIKE ? OR je.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Fetch journal entries
$sql = "
    SELECT 
        je.*,
        c.company_name,
        bu.unit_name as estate_name,
        d.division_name,
        b.block_code,
        a.activity_name,
        CASE 
            WHEN je.total_debit = je.total_credit THEN 'Balanced'
            ELSE 'Unbalanced'
        END as balance_status
    FROM journal_entries je
    LEFT JOIN companies c ON je.company_id = c.company_id
    LEFT JOIN business_units bu ON je.business_unit_id = bu.business_unit_id
    LEFT JOIN divisions d ON je.division_id = d.division_id
    LEFT JOIN blocks b ON je.block_id = b.block_id
    LEFT JOIN activities a ON je.activity_id = a.id
    $where_sql
    ORDER BY je.entry_date DESC, je.id DESC
    LIMIT 50
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Pre-load lines for all draft entries so JS can populate the edit modal
$draft_ids = array_column(
    array_filter($entries, fn($e) => $e['status'] === 'draft'),
    'id'
);
$entries_lines_map = [];
if ($draft_ids) {
    $in  = implode(',', array_map('intval', $draft_ids));
    $lns = $db->query("
        SELECT journal_entry_id, line_number, gl_account_id,
               activity_id, block_id,
               debit_amount, credit_amount,
               currency_code, base_currency_debit, base_currency_credit,
               cost_category, description
        FROM journal_entry_lines
        WHERE journal_entry_id IN ($in)
        ORDER BY journal_entry_id, line_number
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lns as $ln) {
        $entries_lines_map[$ln['journal_entry_id']][] = $ln;
    }
}

// Get statistics — sama scope dengan list (filter company/BU/division)
$stats_where = [];
$stats_params = [];
if ($default_company_id)  { $stats_where[] = "company_id = ?";        $stats_params[] = $default_company_id; }
if ($default_bu_id)       { $stats_where[] = "business_unit_id = ?";  $stats_params[] = $default_bu_id; }
if ($default_division_id) { $stats_where[] = "division_id = ?";       $stats_params[] = $default_division_id; }
$stats_sql = "SELECT
        COUNT(*) as total_entries,
        SUM(CASE WHEN status = 'draft'   THEN 1 ELSE 0 END) as draft_count,
        SUM(CASE WHEN status = 'posted'  THEN 1 ELSE 0 END) as posted_count,
        SUM(CASE WHEN total_debit != total_credit THEN 1 ELSE 0 END) as unbalanced_count
    FROM journal_entries"
    . ($stats_where ? ' WHERE ' . implode(' AND ', $stats_where) : '');
$stats_stmt = $db->prepare($stats_sql);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch();

// Get GL accounts for dropdown
$gl_accounts_stmt = $db->query("
    SELECT id, account_code, account_name, account_type
    FROM general_ledger_accounts
    WHERE is_active = 1
    ORDER BY account_code
");
$gl_accounts = $gl_accounts_stmt->fetchAll();

// Get activities for line dropdown
$activities_stmt = $db->query("
    SELECT id, activity_code, activity_name
    FROM activities
    WHERE is_active = 1
    ORDER BY activity_code
");
$activities = $activities_stmt->fetchAll();

// Get companies, business units, etc. for filters
$companies_stmt = $db->query("SELECT company_id, company_name FROM companies WHERE status='Active' ORDER BY company_name");
$companies = $companies_stmt->fetchAll();

// BU list — scope to user's company if locked, else all active
if ($default_company_id) {
    $stmt = $db->prepare("SELECT business_unit_id, unit_name, company_id FROM business_units WHERE status='Active' AND company_id = ? ORDER BY unit_name");
    $stmt->execute([$default_company_id]);
} else {
    $stmt = $db->query("SELECT business_unit_id, unit_name, company_id FROM business_units WHERE status='Active' ORDER BY unit_name");
}
$all_bus = $stmt->fetchAll();

// Division list — scope to user's BU if locked, else all active
if ($default_bu_id) {
    $stmt = $db->prepare("SELECT division_id, division_name, business_unit_id FROM divisions WHERE status='Active' AND business_unit_id = ? ORDER BY division_name");
    $stmt->execute([$default_bu_id]);
} elseif ($default_company_id) {
    $stmt = $db->prepare("SELECT d.division_id, d.division_name, d.business_unit_id FROM divisions d JOIN business_units bu ON d.business_unit_id = bu.business_unit_id WHERE d.status='Active' AND bu.company_id = ? ORDER BY d.division_name");
    $stmt->execute([$default_company_id]);
} else {
    $stmt = $db->query("SELECT division_id, division_name, business_unit_id FROM divisions WHERE status='Active' ORDER BY division_name");
}
$all_divisions = $stmt->fetchAll();
// All blocks with division_id for client-side filtering (per-line select)
$stmt = $db->query("SELECT block_id AS id, CONCAT(block_code, ' — ', COALESCE(block_name,'')) AS name, division_id FROM blocks ORDER BY block_code");
$all_blocks = $stmt->fetchAll();
// Flag: company is locked to the user's company
$company_locked = !empty($default_company_id);

// Get all distinct currencies with their latest rates for the form
$avail_currencies_stmt = $db->query("
    SELECT cr.from_currency, cr.rate, cr.rate_date
    FROM currency_rates cr
    INNER JOIN (
        SELECT from_currency, MAX(rate_date) as max_date
        FROM currency_rates WHERE to_currency = 'IDR'
        GROUP BY from_currency
    ) latest ON cr.from_currency = latest.from_currency AND cr.rate_date = latest.max_date
    WHERE cr.to_currency = 'IDR'
    ORDER BY cr.from_currency
");
$avail_currencies = $avail_currencies_stmt->fetchAll();

require_once 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 style="color: #166c82;"><i class="bi bi-journal-text" style="color: #166c82;"></i> <?php echo $page_title; ?></h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item active">Journal Entries</li>
                </ol>
            </nav>
        </div>
    </div>

    <?php display_message(); ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white" style="background-color: #3065b0;">
                <div class="card-body">
                    <h5 class="card-title">Total Entries</h5>
                    <h2><?php echo number_format($stats['total_entries']); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Draft</h5>
                    <h2><?php echo number_format($stats['draft_count']); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Posted</h5>
                    <h2><?php echo number_format($stats['posted_count']); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5 class="card-title">Unbalanced</h5>
                    <h2><?php echo number_format($stats['unbalanced_count']); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters and New Entry Button -->
    <div class="row mb-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center text-white" style="background-color: #166c82;">
                    <span><i class="bi bi-funnel"></i> Filters</span>
                    <button type="button" class="btn btn-custom-primary" data-bs-toggle="modal" data-bs-target="#newEntryModal">
                        <i class="bi bi-plus-circle"></i> New Journal Entry
                    </button>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="draft" <?php echo $status_filter == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="posted" <?php echo $status_filter == 'posted' ? 'selected' : ''; ?>>Posted</option>
                                <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="manual">Manual</option>
                                <option value="block_cost">Block Cost</option>
                                <option value="payroll">Payroll</option>
                                <option value="material_purchase">Material Purchase</option>
                                <option value="equipment_depreciation">Equipment Depreciation</option>
                                <option value="overhead_allocation">Overhead Allocation</option>
                                <option value="adjustment">Adjustment</option>
                                <option value="sales_invoice">Sales Invoice</option>
                                <option value="cash_receipt">Cash Receipt</option>
                                <option value="export_sale">Export Sale</option>
                                <option value="fx_revaluation">FX Revaluation</option>
                                <option value="plasma_ffb_purchase">Plasma FFB Purchase</option>
                                <option value="plasma_loan_repayment">Plasma Loan Repayment</option>
                                <option value="plasma_payment_transfer">Plasma Payment Transfer</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date From</label>
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control form-control-sm" placeholder="Reference or description" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-sm w-100 btn-custom-filter">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Journal Entries List -->
    <div class="card">
        <div class="card-header text-white" style="background-color: #166c82;">
            <i class="bi bi-list"></i> Journal Entries (<?php echo count($entries); ?> entries)
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th style="width: 10%;">Date</th>
                            <th style="width: 12%;">Reference</th>
                            <th style="width: 10%;">Type</th>
                            <th style="width: 25%;">Description</th>
                            <th style="width: 10%;">Division</th>
                            <th class="text-end" style="width: 10%;">Debit</th>
                            <th class="text-end" style="width: 10%;">Credit</th>
                            <th style="width: 8%;">Status</th>
                            <th class="text-center" style="width: 5%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entries)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">No journal entries found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($entries as $entry): ?>
                                <tr class="<?php echo $entry['balance_status'] == 'Unbalanced' ? 'table-danger' : ''; ?>">
                                    <td><?php echo date('d/m/Y', strtotime($entry['entry_date'])); ?></td>
                                    <td>
                                        <a href="journal_entry_detail.php?id=<?php echo $entry['id']; ?>" class="text-decoration-none">
                                            <code><?php echo htmlspecialchars($entry['reference_number']); ?></code>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo ucfirst(str_replace('_', ' ', $entry['entry_type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(substr($entry['description'], 0, 50)); ?>
                                        <?php if (strlen($entry['description']) > 50) echo '...'; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($entry['division_name'])): ?>
                                            <small><?php echo htmlspecialchars($entry['division_name']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">Rp <?php echo format_number($entry['total_debit'], 0); ?></td>
                                    <td class="text-end">Rp <?php echo format_number($entry['total_credit'], 0); ?></td>
                                    <td>
                                        <?php
                                        $status_colors = [
                                            'draft' => 'warning',
                                            'posted' => 'success',
                                            'approved' => 'primary',
                                            'cancelled' => 'danger'
                                        ];
                                        $color = $status_colors[$entry['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>">
                                            <?php echo ucfirst($entry['status']); ?>
                                        </span>
                                        <?php if ($entry['balance_status'] == 'Unbalanced'): ?>
                                            <br><small class="text-danger">Unbalanced!</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="journal_entry_detail.php?id=<?php echo $entry['id']; ?>" class="btn btn-sm btn-info" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($entry['status'] == 'draft'): ?>
                                            <button type="button" class="btn btn-sm btn-warning"
                                                title="Edit Entry"
                                                onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                                                    'id'               => $entry['id'],
                                                    'entry_date'       => substr($entry['entry_date'], 0, 10),
                                                    'entry_type'       => $entry['entry_type'],
                                                    'description'      => $entry['description'],
                                                    'currency_code'    => $entry['currency_code'],
                                                    'exchange_rate'    => $entry['exchange_rate'],
                                                    'company_id'       => $entry['company_id'],
                                                    'business_unit_id' => $entry['business_unit_id'],
                                                    'division_id'      => $entry['division_id'],
                                                    'lines'            => $entries_lines_map[$entry['id']] ?? [],
                                                ]), ENT_QUOTES); ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Post this entry?');">
                                                <input type="hidden" name="action" value="post_entry">
                                                <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                <button type="submit" class="btn btn-sm" style="background-color: #166c82; color: white;" title="Post Entry">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this entry?');">
                                                <input type="hidden" name="action" value="delete_entry">
                                                <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                                    <i class="bi bi-trash"></i>
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

<!-- New Journal Entry Modal — FULLSCREEN -->
<div class="modal fade" id="newEntryModal" tabindex="-1">
    <div class="modal-dialog modal-xl" style="max-width:80%; height:90vh;">
        <div class="modal-content">
            <form method="POST" id="journalEntryForm">
                <input type="hidden" name="action" id="formAction" value="create_entry">
                <input type="hidden" name="entry_id" id="formEntryId" value="">

                <!-- ── Modal Header ── -->
                <div class="modal-header py-2" style="background:#166c82; color:#fff;">
                    <h5 class="modal-title mb-0" id="modalTitle"><i class="bi bi-plus-circle me-2"></i>New Journal Entry</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <!-- ── Modal Body — two-column layout ── -->
                <div class="modal-body p-0 d-flex flex-column" style="overflow:hidden; height:calc(90vh - 120px);">

                    <!-- Top band: header fields -->
                    <div class="p-3 border-bottom bg-light flex-shrink-0">
                        <div class="row g-2">
                            <!-- Row 1: Date / Type / Description / Currency / Exchange Rate -->
                            <div class="col-md-2">
                                <label class="form-label form-label-sm mb-1">Entry Date <span class="text-danger">*</span></label>
                                <input type="date" name="entry_date" id="entryDateInput" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label form-label-sm mb-1">Entry Type <span class="text-danger">*</span></label>
                                <select name="entry_type" class="form-select form-select-sm" required>
                                    <option value="manual">Manual Entry</option>
                                    <option value="block_cost">Block Cost</option>
                                    <option value="payroll">Payroll</option>
                                    <option value="material_purchase">Material Purchase</option>
                                    <option value="equipment_depreciation">Equipment Depreciation</option>
                                    <option value="overhead_allocation">Overhead Allocation</option>
                                    <option value="adjustment">Adjustment</option>
                                    <option value="sales_invoice">Sales Invoice</option>
                                    <option value="cash_receipt">Cash Receipt</option>
                                    <option value="export_sale">Export Sale</option>
                                    <option value="fx_revaluation">FX Revaluation</option>
                                    <option value="plasma_ffb_purchase">Plasma FFB Purchase</option>
                                    <option value="plasma_loan_repayment">Plasma Loan Repayment</option>
                                    <option value="plasma_payment_transfer">Plasma Payment Transfer</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label form-label-sm mb-1">Description <span class="text-danger">*</span></label>
                                <input type="text" name="description" class="form-control form-control-sm" required
                                       placeholder="Journal entry description">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label form-label-sm mb-1">Currency</label>
                                <select name="currency_code" id="currencyCode" class="form-select form-select-sm">
                                    <option value="IDR" selected>IDR — Rupiah</option>
                                    <?php foreach ($avail_currencies as $cur): ?>
                                        <option value="<?php echo $cur['from_currency']; ?>"
                                                data-rate="<?php echo $cur['rate']; ?>">
                                            <?php echo htmlspecialchars($cur['from_currency']); ?>
                                            (<?php echo number_format($cur['rate'], 0); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2" id="exchangeRateGroup" style="display:none;">
                                <label class="form-label form-label-sm mb-1">
                                    Rate (1 <span id="fcLabel">FC</span> = IDR) <span class="text-danger">*</span>
                                </label>
                                <input type="number" name="exchange_rate" id="exchangeRate"
                                       class="form-control form-control-sm" step="0.000001" min="0.000001"
                                       placeholder="e.g. 16250">
                            </div>
                        </div>

                        <!-- Row 2: Dimensions (Company → Estate → Division) -->
                        <div class="row g-2 mt-1">
                            <div class="col-md-4">
                                <label class="form-label form-label-sm mb-1">Company</label>
                                <select name="company_id" id="je_company_id"
                                        class="form-select form-select-sm"
                                        <?php echo $company_locked ? 'disabled' : ''; ?>>
                                    <option value="">— Select Company —</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?php echo $company['company_id']; ?>"
                                            <?php echo ($company['company_id'] == $default_company_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($company['company_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($company_locked): ?>
                                    <input type="hidden" name="company_id" value="<?php echo $default_company_id; ?>">
                                    <small class="text-muted" style="font-size:0.7rem;">
                                        <i class="bi bi-lock-fill"></i> Fixed to your account
                                    </small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label form-label-sm mb-1">Estate / Business Unit</label>
                                <select name="business_unit_id" id="je_bu_id" class="form-select form-select-sm">
                                    <option value="">— Select Estate —</option>
                                    <?php foreach ($all_bus as $bu): ?>
                                        <option value="<?php echo $bu['business_unit_id']; ?>"
                                                data-company-id="<?php echo $bu['company_id']; ?>"
                                            <?php echo ($bu['business_unit_id'] == $default_bu_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($bu['unit_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label form-label-sm mb-1">Division / Afdeling</label>
                                <select name="division_id" id="je_division_id" class="form-select form-select-sm">
                                    <option value="">— Select Division —</option>
                                    <?php foreach ($all_divisions as $div): ?>
                                        <option value="<?php echo $div['division_id']; ?>"
                                                data-bu-id="<?php echo $div['business_unit_id']; ?>"
                                            <?php echo ($div['division_id'] == $default_division_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($div['division_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <!-- END top band -->

                    <!-- Middle: scrollable journal lines table -->
                    <div class="flex-grow-1 overflow-auto p-3">

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 fw-semibold">
                                <i class="bi bi-list-ol me-1"></i>Journal Entry Lines
                            </h6>
                            <div class="d-flex align-items-center gap-2">
                                <span id="linesHeader" class="text-muted small"></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="addLineBtn">
                                    <i class="bi bi-plus"></i> Add Line
                                </button>
                            </div>
                        </div>

                        <table class="table table-sm table-bordered align-middle" id="linesTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width:36px;">#</th>
                                    <th style="min-width:200px;">GL Account</th>
                                    <th style="min-width:160px;">Activity</th>
                                    <th style="width:120px;">Block</th>
                                    <th id="th_fc" style="width:100px; display:none;">FC Amount</th>
                                    <th style="width:120px;">Debit (IDR)</th>
                                    <th style="width:120px;">Credit (IDR)</th>
                                    <th style="width:110px;">Cost Category</th>
                                    <th>Line Description</th>
                                    <th style="width:36px;"></th>
                                </tr>
                            </thead>
                            <tbody id="journalLines">
                                <tr class="journal-line">
                                    <td class="text-muted line-num">1</td>
                                    <td>
                                        <select name="line_account[]" class="form-select form-select-sm" required>
                                            <option value="">Select GL Account</option>
                                            <?php foreach ($gl_accounts as $account): ?>
                                                <option value="<?php echo $account['id']; ?>">
                                                    <?php echo $account['account_code']; ?> — <?php echo htmlspecialchars($account['account_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="line_activity_id[]" class="form-select form-select-sm">
                                            <option value="">— Activity —</option>
                                            <?php foreach ($activities as $act): ?>
                                                <option value="<?php echo $act['id']; ?>">
                                                    <?php echo $act['activity_code']; ?> — <?php echo htmlspecialchars($act['activity_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="line_block_id[]" class="form-select form-select-sm line-block-sel">
                                            <option value="">—</option>
                                            <?php foreach ($all_blocks as $blk): ?>
                                                <option value="<?php echo $blk['id']; ?>"
                                                        data-division-id="<?php echo $blk['division_id']; ?>">
                                                    <?php echo htmlspecialchars($blk['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="col-fc" style="display:none;">
                                        <input type="number" name="line_fc_amount[]"
                                               class="form-control form-control-sm fc-amount-input"
                                               placeholder="0.0000" step="0.0001" min="0">
                                    </td>
                                    <td>
                                        <input type="number" name="line_debit[]"
                                               class="form-control form-control-sm debit-input text-end"
                                               placeholder="0.00" step="0.01" min="0">
                                    </td>
                                    <td>
                                        <input type="number" name="line_credit[]"
                                               class="form-control form-control-sm credit-input text-end"
                                               placeholder="0.00" step="0.01" min="0">
                                    </td>
                                    <td>
                                        <select name="line_cost_category[]" class="form-select form-select-sm">
                                            <option value="">—</option>
                                            <option value="labor">Labor</option>
                                            <option value="material">Material</option>
                                            <option value="vehicle_equipment">Vehicle/Equipment</option>
                                            <option value="overhead">Overhead</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="line_description[]"
                                               class="form-control form-control-sm"
                                               placeholder="Optional note">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-line" disabled title="Remove line">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- END journal lines -->

                    <!-- Bottom totals band -->
                    <div class="p-2 px-3 border-top bg-light flex-shrink-0 d-flex align-items-center justify-content-between">
                        <div id="balanceWarning" class="alert alert-warning mb-0 py-1 px-3 d-none" style="font-size:0.85rem;">
                            <i class="bi bi-exclamation-triangle"></i> Entry is not balanced — Debit must equal Credit.
                        </div>
                        <div class="ms-auto">
                            <table class="table table-sm table-borderless mb-0" style="min-width:320px;">
                                <tr>
                                    <td class="text-end text-muted pe-3">Total Debit (IDR):</td>
                                    <td class="text-end fw-bold" id="totalDebit" style="min-width:140px;">Rp 0</td>
                                </tr>
                                <tr>
                                    <td class="text-end text-muted pe-3">Total Credit (IDR):</td>
                                    <td class="text-end fw-bold" id="totalCredit">Rp 0</td>
                                </tr>
                                <tr class="table-info">
                                    <td class="text-end pe-3"><strong>Difference:</strong></td>
                                    <td class="text-end fw-bold" id="difference">Rp 0</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                </div><!-- /.modal-body -->

                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-sm" style="background-color:#166c82; color:#fff;" id="submitBtn">
                        <i class="bi bi-save" id="submitIcon"></i> <span id="submitLabel">Create Journal Entry</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ============================================================
// Currency (rates injected by PHP)
// ============================================================
const currencyRates  = <?php echo json_encode($currency_rates_map); ?>;
const currencyCodeEl = document.getElementById('currencyCode');
const exchangeRateEl = document.getElementById('exchangeRate');
const exRateGroup    = document.getElementById('exchangeRateGroup');
const fcLabelEl      = document.getElementById('fcLabel');
const linesHeader    = document.getElementById('linesHeader');
const thFC           = document.getElementById('th_fc');

function isForeign() { return currencyCodeEl.value !== 'IDR'; }

function applyFCVisibility() {
    const foreign = isForeign();
    exRateGroup.style.display = foreign ? '' : 'none';
    thFC.style.display        = foreign ? '' : 'none';
    if (foreign) fcLabelEl.textContent = currencyCodeEl.value;
    document.querySelectorAll('.col-fc').forEach(el => el.style.display = foreign ? '' : 'none');
    linesHeader.textContent = foreign ? currencyCodeEl.value + ' → IDR auto-convert' : '';
}

currencyCodeEl.addEventListener('change', function() {
    const sel = this.options[this.selectedIndex];
    exchangeRateEl.value = (sel.dataset.rate && this.value !== 'IDR')
        ? parseFloat(sel.dataset.rate).toFixed(6) : '';
    applyFCVisibility();
    calculateTotals();
});
exchangeRateEl.addEventListener('input', () => {
    document.querySelectorAll('.journal-line').forEach(recalcFCLine);
});

function recalcFCLine(line) {
    if (!isForeign()) return;
    const rate  = parseFloat(exchangeRateEl.value) || 0;
    const fc    = parseFloat(line.querySelector('.fc-amount-input').value) || 0;
    const idr   = rate > 0 ? (fc * rate).toFixed(2) : '';
    const debit = line.querySelector('.debit-input');
    const cred  = line.querySelector('.credit-input');
    if (cred.value && parseFloat(cred.value) > 0) cred.value = idr;
    else debit.value = idr;
    calculateTotals();
}

// ============================================================
// Company → BU → Division → Block cascade
// ============================================================
const jeDivision = document.getElementById('je_division_id');
const jeBU       = document.getElementById('je_bu_id');
const jeCompany  = document.getElementById('je_company_id'); // null when company is locked

// Block options HTML (all blocks, all division ids embedded) — cloned for new lines
const allBlockOptionsHTML = document.querySelector('#journalLines .line-block-sel').innerHTML;

// Filter BU options by Company (show/hide in-place — no AJAX, all options pre-loaded)
function filterBUs(companyId) {
    if (!jeBU) return;
    Array.from(jeBU.options).forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (!companyId || opt.dataset.companyId == companyId) ? '' : 'none';
    });
    // Clear selection if its option is now hidden
    const cur = jeBU.value;
    if (cur && jeBU.querySelector(`option[value="${cur}"]`)?.style.display === 'none') {
        jeBU.value = '';
        filterDivisions(''); // cascade: clear divisions
        filterBlocks('');    // cascade: clear blocks
    }
}

// Filter division options by BU (show/hide in-place — no AJAX, all options pre-loaded)
function filterDivisions(buId) {
    Array.from(jeDivision.options).forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (!buId || opt.dataset.buId == buId) ? '' : 'none';
    });
    // Clear selection if its option is now hidden
    const cur = jeDivision.value;
    if (cur && jeDivision.querySelector(`option[value="${cur}"]`)?.style.display === 'none') {
        jeDivision.value = '';
        filterBlocks(''); // also clear blocks
    }
}

// Filter block options by division (show/hide in-place — no AJAX, all options pre-loaded)
function filterBlocks(divId) {
    document.querySelectorAll('#journalLines .line-block-sel').forEach(sel => {
        const prev = sel.value;
        Array.from(sel.options).forEach(opt => {
            if (!opt.value) return;
            opt.style.display = (!divId || opt.dataset.divisionId == divId) ? '' : 'none';
        });
        // Clear selection if now hidden
        if (prev && sel.querySelector(`option[value="${prev}"]`)?.style.display === 'none') {
            sel.value = '';
        }
    });
}

// Company change → filter BUs, then cascade
if (jeCompany) {
    jeCompany.addEventListener('change', function() {
        filterBUs(this.value);
        filterDivisions('');
        filterBlocks('');
    });
}

// BU change → filter Divisions, then reset Blocks
if (jeBU) {
    jeBU.addEventListener('change', function() {
        filterDivisions(this.value);
        filterBlocks('');
    });
}

// Division change → filter blocks on all lines
jeDivision.addEventListener('change', function() {
    filterBlocks(this.value);
});

// User defaults injected by PHP (used for both initial cascade and modal open)
const defaultCompanyId  = <?php echo json_encode($default_company_id  ? (string)$default_company_id  : ''); ?>;
const defaultBuId       = <?php echo json_encode($default_bu_id       ? (string)$default_bu_id       : ''); ?>;
const defaultDivisionId = <?php echo json_encode($default_division_id ? (string)$default_division_id : ''); ?>;

// Apply cascade + defaults to the header dropdowns.
// When company is locked, jeCompany is null — we still call filterBUs with the
// locked company id so only that company's BUs are shown.
function applyUserDefaults() {
    const companyIdForFilter = (jeCompany ? jeCompany.value : null) || defaultCompanyId;
    if (companyIdForFilter) filterBUs(companyIdForFilter);

    if (jeBU) {
        if (defaultBuId && !jeBU.value) jeBU.value = defaultBuId;
        filterDivisions(jeBU.value);
    }
    if (jeDivision) {
        if (defaultDivisionId && !jeDivision.value) jeDivision.value = defaultDivisionId;
        filterBlocks(jeDivision.value);
    }
}

// On page load: apply initial filters based on pre-selected Company / BU / Division
(function applyInitialCascade() {
    applyUserDefaults();
})();

// On modal open (new entry mode): re-apply cascade + defaults so dropdowns
// are pre-filtered and pre-selected to the user's company → BU → division scope.
document.getElementById('newEntryModal').addEventListener('show.bs.modal', function () {
    // Only apply defaults when opening in create mode (not edit mode)
    if (document.getElementById('formAction').value !== 'create_entry') return;

    // Reset header dimension fields to user defaults
    if (jeCompany && defaultCompanyId) jeCompany.value = defaultCompanyId;
    if (jeBU      && defaultBuId)      jeBU.value      = defaultBuId;
    if (jeDivision && defaultDivisionId) jeDivision.value = defaultDivisionId;

    applyUserDefaults();
});

// ============================================================
// Journal lines
// ============================================================
const glAccountOptions  = document.querySelector('#journalLines .journal-line select[name="line_account[]"]').innerHTML;
const activityOptions   = document.querySelector('#journalLines .journal-line select[name="line_activity_id[]"]').innerHTML;

document.getElementById('addLineBtn').addEventListener('click', function() {
    const tbody = document.getElementById('journalLines');
    const rows  = tbody.querySelectorAll('.journal-line');
    const tr    = document.createElement('tr');
    tr.className = 'journal-line';
    tr.innerHTML = `
        <td class="text-muted line-num">${rows.length + 1}</td>
        <td><select name="line_account[]" class="form-select form-select-sm" required>${glAccountOptions}</select></td>
        <td><select name="line_activity_id[]" class="form-select form-select-sm">${activityOptions}</select></td>
        <td><select name="line_block_id[]" class="form-select form-select-sm line-block-sel">${allBlockOptionsHTML}</select></td>
        <td class="col-fc" style="display:${isForeign() ? '' : 'none'};">
            <input type="number" name="line_fc_amount[]" class="form-control form-control-sm fc-amount-input" placeholder="0.0000" step="0.0001" min="0">
        </td>
        <td><input type="number" name="line_debit[]"  class="form-control form-control-sm debit-input text-end"  placeholder="0.00" step="0.01" min="0"></td>
        <td><input type="number" name="line_credit[]" class="form-control form-control-sm credit-input text-end" placeholder="0.00" step="0.01" min="0"></td>
        <td>
            <select name="line_cost_category[]" class="form-select form-select-sm">
                <option value="">—</option>
                <option value="labor">Labor</option>
                <option value="material">Material</option>
                <option value="vehicle_equipment">Vehicle/Equipment</option>
                <option value="overhead">Overhead</option>
                <option value="other">Other</option>
            </select>
        </td>
        <td><input type="text" name="line_description[]" class="form-control form-control-sm" placeholder="Optional note"></td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger remove-line" title="Remove line">
                <i class="bi bi-trash"></i>
            </button>
        </td>`;
    tbody.appendChild(tr);
    // Apply current division filter to the new line's block select
    filterBlocks(jeDivision.value);
    updateLineNums();
    updateRemoveButtons();
    calculateTotals();
});

document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-line')) {
        e.target.closest('.journal-line').remove();
        updateLineNums();
        updateRemoveButtons();
        calculateTotals();
    }
});

function updateLineNums() {
    document.querySelectorAll('#journalLines .journal-line').forEach((tr, i) => {
        const cell = tr.querySelector('.line-num');
        if (cell) cell.textContent = i + 1;
    });
}

function updateRemoveButtons() {
    const lines = document.querySelectorAll('#journalLines .journal-line');
    lines.forEach(line => {
        line.querySelector('.remove-line').disabled = (lines.length === 1);
    });
}

// ============================================================
// Totals
// ============================================================
document.addEventListener('input', function(e) {
    if (e.target.classList.contains('fc-amount-input'))
        recalcFCLine(e.target.closest('.journal-line'));
    if (e.target.classList.contains('debit-input') || e.target.classList.contains('credit-input'))
        calculateTotals();
});

function calculateTotals() {
    let dr = 0, cr = 0;
    document.querySelectorAll('#journalLines .debit-input').forEach(i  => dr += parseFloat(i.value)  || 0);
    document.querySelectorAll('#journalLines .credit-input').forEach(i => cr += parseFloat(i.value) || 0);
    const diff = Math.abs(dr - cr);
    document.getElementById('totalDebit').textContent  = 'Rp ' + dr.toLocaleString('id-ID', {minimumFractionDigits:2});
    document.getElementById('totalCredit').textContent = 'Rp ' + cr.toLocaleString('id-ID', {minimumFractionDigits:2});
    document.getElementById('difference').textContent  = 'Rp ' + diff.toLocaleString('id-ID', {minimumFractionDigits:2});
    const balanced = diff <= 0.01;
    document.getElementById('balanceWarning').classList.toggle('d-none', balanced);
    document.getElementById('submitBtn').disabled = !balanced;
}

// ============================================================
// Reset form when modal is dismissed
// ============================================================
// Helper: today as YYYY-MM-DD (always consistent, never affected by server locale)
function todayYMD() {
    return new Date().toLocaleDateString('en-CA');
}

// Set today as the default for new entries on page load
document.getElementById('entryDateInput').value = todayYMD();

document.getElementById('newEntryModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('journalEntryForm').reset();
    // form.reset() clears the value (no HTML default anymore) — restore today for next new entry
    document.getElementById('entryDateInput').value = todayYMD();
    // Remove extra lines (keep first), restore block options and re-apply filter
    const tbody = document.getElementById('journalLines');
    const lines = tbody.querySelectorAll('.journal-line');
    lines.forEach((line, i) => { if (i > 0) line.remove(); });
    // Re-apply user defaults + cascade after reset (form.reset() clears select values)
    if (jeCompany && defaultCompanyId) jeCompany.value = defaultCompanyId;
    if (jeBU      && defaultBuId)      jeBU.value      = defaultBuId;
    if (jeDivision && defaultDivisionId) jeDivision.value = defaultDivisionId;
    applyUserDefaults();
    const firstBlockSel = tbody.querySelector('.line-block-sel');
    if (firstBlockSel) { firstBlockSel.innerHTML = allBlockOptionsHTML; filterBlocks(jeDivision.value); }
    updateLineNums();
    updateRemoveButtons();
    applyFCVisibility();
    calculateTotals();
});

// ============================================================
// Edit modal: populate form with existing entry data
// ============================================================
function openEditModal(data) {
    const form   = document.getElementById('journalEntryForm');
    const tbody  = document.getElementById('journalLines');
    const modal  = bootstrap.Modal.getOrCreateInstance(document.getElementById('newEntryModal'));

    // Switch to update mode
    document.getElementById('formAction').value  = 'update_entry';
    document.getElementById('formEntryId').value = data.id;
    document.getElementById('modalTitle').innerHTML =
        '<i class="bi bi-pencil me-2"></i>Edit Journal Entry';
    document.getElementById('submitLabel').textContent = 'Save Changes';

    // Set entry date — done here AND repeated on shown.bs.modal to survive any
    // intermediate form.reset() that fires during Bootstrap's hide animation
    document.getElementById('entryDateInput').value = data.entry_date;
    document.getElementById('newEntryModal').addEventListener('shown.bs.modal', function setDate() {
        document.getElementById('entryDateInput').value = data.entry_date;
        document.getElementById('newEntryModal').removeEventListener('shown.bs.modal', setDate);
    });
    form.querySelector('[name="entry_type"]').value   = data.entry_type;
    form.querySelector('[name="description"]').value  = data.description;

    // Currency
    const ccSel = document.getElementById('currencyCode');
    ccSel.value = data.currency_code || 'IDR';
    if (data.currency_code && data.currency_code !== 'IDR') {
        document.getElementById('exchangeRate').value = parseFloat(data.exchange_rate).toFixed(6);
    }
    applyFCVisibility();

    // Dimensions
    const buSel  = document.getElementById('je_bu_id');
    const divSel = document.getElementById('je_division_id');
    const coSel  = document.getElementById('je_company_id'); // may be null (locked)
    if (coSel)  { coSel.value  = data.company_id        || ''; filterBUs(coSel.value); }
    if (buSel)  { buSel.value  = data.business_unit_id || ''; filterDivisions(buSel.value); }
    if (divSel) { divSel.value = data.division_id      || ''; filterBlocks(divSel.value); }

    // Lines — clear all then rebuild
    Array.from(tbody.querySelectorAll('.journal-line')).forEach((r, i) => { if (i > 0) r.remove(); });

    const lines = data.lines && data.lines.length ? data.lines : [{}];
    lines.forEach((ln, idx) => {
        let tr;
        if (idx === 0) {
            tr = tbody.querySelector('.journal-line');
        } else {
            document.getElementById('addLineBtn').click();
            tr = tbody.querySelectorAll('.journal-line')[idx];
        }

        const acSel   = tr.querySelector('[name="line_account[]"]');
        const actSel  = tr.querySelector('[name="line_activity_id[]"]');
        const blkSel  = tr.querySelector('[name="line_block_id[]"]');
        const fcInp   = tr.querySelector('[name="line_fc_amount[]"]');
        const drInp   = tr.querySelector('[name="line_debit[]"]');
        const crInp   = tr.querySelector('[name="line_credit[]"]');
        const catSel  = tr.querySelector('[name="line_cost_category[]"]');
        const descInp = tr.querySelector('[name="line_description[]"]');

        if (acSel  && ln.gl_account_id)  acSel.value  = ln.gl_account_id;
        if (actSel && ln.activity_id)    actSel.value  = ln.activity_id;
        if (blkSel && ln.block_id)       blkSel.value  = ln.block_id;
        if (catSel && ln.cost_category)  catSel.value  = ln.cost_category;
        if (descInp && ln.description)   descInp.value = ln.description;

        const isFc = ln.currency_code && ln.currency_code !== 'IDR';
        if (drInp)  drInp.value  = isFc ? (ln.base_currency_debit  || 0) : (ln.debit_amount  || 0);
        if (crInp)  crInp.value  = isFc ? (ln.base_currency_credit || 0) : (ln.credit_amount || 0);
        if (fcInp)  fcInp.value  = isFc ? (ln.debit_amount > 0 ? ln.debit_amount : ln.credit_amount) : '';
    });

    calculateTotals();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('newEntryModal')).show();
}

// Reset to "create" mode when modal is closed
document.getElementById('newEntryModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formAction').value  = 'create_entry';
    document.getElementById('formEntryId').value = '';
    document.getElementById('modalTitle').innerHTML =
        '<i class="bi bi-plus-circle me-2"></i>New Journal Entry';
    document.getElementById('submitLabel').textContent = 'Create Journal Entry';
});

// Initial state
applyFCVisibility();
calculateTotals();
</script>

<style>
/* Custom button styles for #166c82 color */
.btn-custom-primary {
    background-color: #166c82;
    border-color: #166c82;
    color: white;
}

.btn-custom-primary:hover {
    background-color: #1a7d9a;
    border-color: #1a7d9a;
    color: white;
}

.btn-custom-primary:focus,
.btn-custom-primary:active {
    background-color: #145a6d;
    border-color: #145a6d;
    color: white;
}

/* Custom filter button styles */
.btn-custom-filter {
    background-color: #166c82;
    border-color: #166c82;
    color: white;
}

.btn-custom-filter:hover {
    background-color: #1a7d9a;
    border-color: #1a7d9a;
    color: white;
}

.btn-custom-filter:focus,
.btn-custom-filter:active {
    background-color: #145a6d;
    border-color: #145a6d;
    color: white;
}

/* Hover effect for action buttons in table */
.table .btn-sm:hover {
    opacity: 0.85;
    transform: scale(1.05);
    transition: all 0.2s ease;
}
</style>

<?php require_once 'includes/footer.php'; ?>

// Powered by IBM Bob
