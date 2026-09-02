<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=agro;charset=utf8mb4', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Show grn_headers columns
    echo "=== grn_headers columns ===" . PHP_EOL;
    $cols = $pdo->query('DESCRIBE grn_headers')->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) echo '  ' . $c['Field'] . ' (' . $c['Type'] . ')' . PHP_EOL;

    // Show grn_lines if exists
    echo PHP_EOL . "=== grn_lines columns ===" . PHP_EOL;
    try {
        $cols2 = $pdo->query('DESCRIBE grn_lines')->fetchAll(PDO::FETCH_ASSOC);
        foreach($cols2 as $c) echo '  ' . $c['Field'] . ' (' . $c['Type'] . ')' . PHP_EOL;
    } catch(Exception $e) { echo '  Table not found' . PHP_EOL; }

    // Count from grn_headers
    echo PHP_EOL . "=== Counts ===" . PHP_EOL;
    echo 'grn_headers rows: ' . $pdo->query('SELECT COUNT(*) FROM grn_headers')->fetchColumn() . PHP_EOL;

    // Try cloud DB
    echo PHP_EOL . "=== Cloud DB ===" . PHP_EOL;
    $cloud = new PDO('mysql:host=srv1982.hstgr.io;dbname=u208932211_inodesain;charset=utf8mb4', 'u208932211_admin', '12345Abcde@@@');
    $cloud->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $total = $cloud->query('SELECT COUNT(*) FROM grn_headers')->fetchColumn();
    echo 'Cloud - Total GRNs: ' . $total . PHP_EOL;

    $rows = $cloud->query('SELECT status, COUNT(*) as cnt FROM grn_headers GROUP BY status ORDER BY cnt DESC')->fetchAll(PDO::FETCH_ASSOC);
    echo 'By Status:' . PHP_EOL;
    foreach($rows as $r) echo '  ' . $r['status'] . ': ' . $r['cnt'] . PHP_EOL;

    $rows2 = $cloud->query('SELECT YEAR(grn_date) as yr, COUNT(*) as cnt FROM grn_headers GROUP BY yr ORDER BY yr DESC')->fetchAll(PDO::FETCH_ASSOC);
    echo 'By Year:' . PHP_EOL;
    foreach($rows2 as $r) echo '  ' . $r['yr'] . ': ' . $r['cnt'] . PHP_EOL;

    // Try to get total value
    try {
        $cols3 = $cloud->query('DESCRIBE grn_headers')->fetchAll(PDO::FETCH_ASSOC);
        $fieldNames = array_column($cols3, 'Field');
        if(in_array('total_value', $fieldNames)) {
            $val = $cloud->query('SELECT SUM(total_value) FROM grn_headers')->fetchColumn();
            echo 'Total Value: ' . number_format($val, 2) . PHP_EOL;
        } else {
            echo 'Columns: ' . implode(', ', $fieldNames) . PHP_EOL;
        }
    } catch(Exception $e) { echo 'Describe error: ' . $e->getMessage() . PHP_EOL; }

} catch(Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
