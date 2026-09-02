<?php
require_once 'config/database.php';
$p = getDB();
$rows = $p->query("SELECT account_code, account_name, account_type FROM general_ledger_accounts WHERE account_code LIKE '513%'")->fetchAll();
foreach ($rows as $r) echo $r['account_code'].' | '.$r['account_name'].' | type=['.$r['account_type'].']'.PHP_EOL;
