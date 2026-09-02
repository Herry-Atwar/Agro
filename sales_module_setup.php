<?php
/**
 * Sales Module Setup
 * Run this once to create the Sales Module database tables.
 * Access via browser: http://localhost/agro/sales_module_setup.php
 */
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$results = [];
$has_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'run_setup') {
    $sql_file = __DIR__ . '/database/sales_module_schema.sql';
    if (!file_exists($sql_file)) {
        $results[] = ['status'=>'error','msg'=>'Schema file not found: ' . $sql_file];
        $has_error = true;
    } else {
        $sql = file_get_contents($sql_file);
        // Split on semicolons (skip delimiters — no procedures in this file)
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => strlen($s) > 5 && strpos($s,'--') !== 0
        );
        foreach ($statements as $stmt) {
            if (trim($stmt) === '') continue;
            try {
                $db->exec($stmt);
                // Extract first meaningful line as label
                $first = strtok(trim($stmt), "\n");
                $results[] = ['status'=>'ok','msg'=>htmlspecialchars(substr($first,0,100))];
            } catch (PDOException $e) {
                // Warn on "already exists" but don't fail
                if (strpos($e->getMessage(),'already exists') !== false ||
                    strpos($e->getMessage(),'Duplicate') !== false) {
                    $results[] = ['status'=>'warn','msg'=>htmlspecialchars(substr($stmt,0,80)).' — already exists'];
                } else {
                    $results[] = ['status'=>'error','msg'=>htmlspecialchars($e->getMessage())];
                    $has_error = true;
                }
            }
        }
    }
}

// Check current table status
$tables = ['sales_contracts','delivery_orders','payment_receives'];
$table_status = [];
foreach ($tables as $t) {
    try {
        $cnt = (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $table_status[$t] = ['exists'=>true,'count'=>$cnt];
    } catch (PDOException $e) {
        $table_status[$t] = ['exists'=>false,'count'=>0];
    }
}

$page_title = 'Sales Module Setup';
require_once 'includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-gear"></i> Sales Module Setup</h1>
    <p class="text-muted">Creates <code>sales_contracts</code>, <code>delivery_orders</code>, and <code>payment_receives</code> tables.</p>
</div>

<!-- Table Status -->
<div class="row g-3 mb-4">
    <?php foreach ($table_status as $tbl => $info): ?>
        <div class="col-md-4">
            <div class="card h-100 <?= $info['exists'] ? 'border-success' : 'border-danger' ?>">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center">
                        <i class="bi <?= $info['exists'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?> fs-4 me-2"></i>
                        <div>
                            <div class="fw-bold"><?= $tbl ?></div>
                            <div class="small text-muted">
                                <?= $info['exists'] ? $info['count'].' rows' : 'Not found' ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Run Setup -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-play-circle"></i> Run Setup</div>
    <div class="card-body">
        <p class="mb-3">
            Click the button below to execute <code>database/sales_module_schema.sql</code>.
            This will <strong>CREATE IF NOT EXISTS</strong> all required tables and views.
            It is safe to run multiple times.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="run_setup">
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Run Sales Module setup now?')">
                <i class="bi bi-play-fill"></i> Execute Setup SQL
            </button>
            <a href="sales_contracts.php" class="btn btn-outline-secondary ms-2">
                <i class="bi bi-file-earmark-text"></i> Go to Sales Contracts
            </a>
        </form>
    </div>
</div>

<!-- Results -->
<?php if (!empty($results)): ?>
<div class="card">
    <div class="card-header <?= $has_error ? 'bg-danger' : 'bg-success' ?> text-white">
        <i class="bi <?= $has_error ? 'bi-exclamation-triangle' : 'bi-check-circle' ?>"></i>
        Setup <?= $has_error ? 'completed with errors' : 'completed successfully' ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Status</th><th>Statement</th></tr></thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                        <tr class="<?= $r['status']==='error'?'table-danger':($r['status']==='warn'?'table-warning':'table-success') ?>">
                            <td class="text-nowrap">
                                <?php if ($r['status']==='ok'):   ?><i class="bi bi-check-circle-fill text-success"></i> OK
                                <?php elseif ($r['status']==='warn'): ?><i class="bi bi-exclamation-circle-fill text-warning"></i> Warn
                                <?php else: ?><i class="bi bi-x-circle-fill text-danger"></i> Error<?php endif; ?>
                            </td>
                            <td class="small font-monospace"><?= $r['msg'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!$has_error): ?>
    <div class="alert alert-success mt-3">
        <i class="bi bi-check-circle-fill"></i>
        <strong>Setup complete!</strong> You can now use the Sales Module.
        <a href="sales_contracts.php" class="alert-link">Go to Sales Contracts →</a>
    </div>
<?php endif; ?>
<?php endif; ?>

<!-- Accounting Integration Notes -->
<div class="card mt-4">
    <div class="card-header"><i class="bi bi-journal-text"></i> Accounting Integration Flow</div>
    <div class="card-body">
        <ol class="mb-0">
            <li><strong>Sales Contract</strong> — Agree on product, quantity, price, delivery window. Status: <code>active</code>.</li>
            <li class="mt-1"><strong>Delivery Order</strong> — Create a shipment against the contract. Driver, vehicle, weights.
                Confirm delivery → status: <code>delivered</code>.</li>
            <li class="mt-1"><strong>Invoice (on DO)</strong> — Click <em>Invoice</em> on a delivered DO. System auto-posts:
                <code>Dr Accounts Receivable / Cr Revenue</code>. Status: <code>invoiced</code>.</li>
            <li class="mt-1"><strong>Payment Receive</strong> — Record cash/bank receipt against the invoice. System auto-posts:
                <code>Dr Bank (Cash in Bank – Operations) / Cr Accounts Receivable</code>.</li>
        </ol>
        <hr>
        <p class="mb-0 text-muted small">
            GL accounts used: AR (1121/1122/1123), Revenue (4100/4110/4120/4130), Bank (1112).
            All journal entries link to <code>journal_entries</code> and can be viewed in
            <a href="journal_entries.php">Journal Entries</a>.
        </p>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
