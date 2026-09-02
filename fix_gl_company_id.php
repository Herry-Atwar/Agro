<?php
/**
 * One-time migration: add company_id to general_ledger_accounts (cloud safe)
 * Run once via browser, then delete this file.
 */
require_once 'config/database.php';
$db = getDB();

$results = [];

// 1. Check if column already exists
$col = $db->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'general_ledger_accounts'
      AND COLUMN_NAME  = 'company_id'
")->fetchColumn();

if ($col > 0) {
    $results[] = ['ok', 'Column <code>company_id</code> already exists — nothing to do.'];
} else {
    try {
        $db->exec("
            ALTER TABLE general_ledger_accounts
                ADD COLUMN company_id INT NULL
                    COMMENT 'FK → companies.company_id — NULL = shared/global'
                    AFTER id,
                ADD INDEX idx_gla_company (company_id)
        ");
        $results[] = ['ok', 'Column <code>company_id</code> added successfully.'];
    } catch (PDOException $e) {
        $results[] = ['err', 'Failed to add column: ' . $e->getMessage()];
    }
}

// 2. Check FK (optional — skip if companies table missing)
try {
    $fkExists = $db->query("
        SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA    = DATABASE()
          AND TABLE_NAME      = 'general_ledger_accounts'
          AND CONSTRAINT_NAME = 'fk_gla_company'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ")->fetchColumn();

    if (!$fkExists) {
        $db->exec("
            ALTER TABLE general_ledger_accounts
                ADD CONSTRAINT fk_gla_company
                    FOREIGN KEY (company_id) REFERENCES companies(company_id)
                    ON DELETE SET NULL
        ");
        $results[] = ['ok', 'Foreign key <code>fk_gla_company</code> added.'];
    } else {
        $results[] = ['ok', 'Foreign key already exists.'];
    }
} catch (PDOException $e) {
    $results[] = ['warn', 'FK skipped (non-critical): ' . $e->getMessage()];
}

// 3. Show current column structure
$cols = $db->query("
    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'general_ledger_accounts'
    ORDER BY ORDINAL_POSITION
")->fetchAll();
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<title>GL Accounts Migration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4">
<div style="max-width:800px;margin:0 auto;">
    <h4><i>GL Accounts — company_id Migration</i></h4>

    <?php foreach ($results as [$type, $msg]): ?>
    <div class="alert alert-<?= $type === 'ok' ? 'success' : ($type === 'warn' ? 'warning' : 'danger') ?>">
        <?= $msg ?>
    </div>
    <?php endforeach; ?>

    <h6 class="mt-3">Current columns on <code>general_ledger_accounts</code>:</h6>
    <table class="table table-sm table-bordered">
        <thead class="table-light">
            <tr><th>Column</th><th>Type</th><th>Nullable</th><th>Default</th><th>Comment</th></tr>
        </thead>
        <tbody>
        <?php foreach ($cols as $c): ?>
            <tr <?= $c['COLUMN_NAME'] === 'company_id' ? 'class="table-success fw-bold"' : '' ?>>
                <td><code><?= htmlspecialchars($c['COLUMN_NAME']) ?></code></td>
                <td><?= htmlspecialchars($c['COLUMN_TYPE']) ?></td>
                <td><?= $c['IS_NULLABLE'] ?></td>
                <td><?= $c['COLUMN_DEFAULT'] ?? '—' ?></td>
                <td><?= htmlspecialchars($c['COLUMN_COMMENT']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="alert alert-info mt-3">
        <strong>Done.</strong> You can now delete <code>fix_gl_company_id.php</code>.
        <a href="gl_accounts.php" class="btn btn-sm btn-primary ms-3">→ Go to GL Accounts</a>
    </div>
</div>
</body></html>
