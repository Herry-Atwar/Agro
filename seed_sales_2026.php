<?php
/**
 * Seed Sales 2026 — Agro Project
 * Creates 12 months of realistic CPO / Kernel / FFB sales for year 2026.
 * Safe to re-run: deletes previous seeder rows first.
 * Usage: visit http://localhost/agro/seed_sales_2026.php
 */

require_once 'config/database.php';

$db = getDB();

echo "<!DOCTYPE html><html><head><title>Seed Sales 2026 — Agro</title>
<style>
body{font-family:Arial,sans-serif;margin:30px;max-width:900px}
pre{background:#f5f5f5;padding:15px;border-radius:6px;border-left:4px solid #28a745;font-size:13px}
.ok{color:#28a745;font-weight:bold}.skip{color:#6c757d}.err{color:#dc3545;font-weight:bold}
a.btn{display:inline-block;margin:4px;padding:9px 20px;background:#166c82;color:#fff;text-decoration:none;border-radius:5px}
h2{color:#166c82}
</style></head><body>
<h2>🌱 Seed Sales Data — Year 2026</h2><pre>";

// ─── Helpers ────────────────────────────────────────────────────────────────
function rand_between(int $min, int $max): int { return rand($min, $max); }
function rand_float(float $min, float $max, int $dec = 2): float {
    return round($min + mt_rand() / mt_getrandmax() * ($max - $min), $dec);
}

// ─── Step 1: Ensure customers exist ─────────────────────────────────────────
echo "Step 1: Ensuring customers exist...\n";

$customers_data = [
    ['CUST-001', 'PT Wilmar Indonesia',        'Mill',             'John Manager',   '+62-21-5551234', 'john@wilmar.co.id',      'Jakarta', 'DKI Jakarta',    'Credit 30 days'],
    ['CUST-002', 'PT Musim Mas',               'Refinery',         'Sarah Director', '+62-61-4567890', 'sarah@musimmas.co.id',   'Medan',   'Sumatra Utara',  'Credit 14 days'],
    ['CUST-003', 'PT Sinar Mas Agro',          'Trader',           'David Trader',   '+62-21-5559876', 'david@sinarmas.co.id',   'Jakarta', 'DKI Jakarta',    'Credit 7 days' ],
    ['CUST-004', 'PT Golden Agri Resources',   'Mill',             'Lisa Manager',   '+62-21-5552345', 'lisa@gar.co.id',         'Jakarta', 'DKI Jakarta',    'Cash'          ],
    ['CUST-005', 'PT Astra Agro Lestari',      'Exporter',         'Michael Export', '+62-21-4616555', 'michael@astra-agro.co.id','Jakarta','DKI Jakarta',    'Credit 30 days'],
    ['CUST-006', 'PT Permata Hijau Group',     'Refinery',         'Rina Susanti',   '+62-21-7701234', 'rina@permatahijau.co.id','Surabaya','Jawa Timur',     'Credit 21 days'],
    ['CUST-007', 'PT Triputra Agro Persada',   'Trader',           'Hendra Wijaya',  '+62-21-5678901', 'hendra@triputra.co.id',  'Jakarta', 'DKI Jakarta',    'Credit 14 days'],
];

// Ensure customers table exists
try {
    $db->query("SELECT 1 FROM customers LIMIT 1");
} catch (PDOException $e) {
    echo "<span class='err'>ERROR: customers table missing. Run sales_schema.sql first.</span>\n";
    echo "</pre></body></html>"; exit;
}

$existing_codes = $db->query("SELECT customer_code FROM customers")->fetchAll(PDO::FETCH_COLUMN);
$cust_inserted = 0;
foreach ($customers_data as $c) {
    if (!in_array($c[0], $existing_codes)) {
        $db->prepare("INSERT INTO customers (customer_code,customer_name,customer_type,contact_person,phone,email,city,province,payment_terms,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,'Active','seeder')")
           ->execute($c);
        echo "<span class='ok'>  ADDED customer: {$c[1]}</span>\n";
        $cust_inserted++;
    } else {
        echo "<span class='skip'>  SKIP  customer: {$c[1]} (exists)</span>\n";
    }
}
echo "  → $cust_inserted new customers added.\n\n";

// Load customer IDs
$customers = $db->query("SELECT customer_id, customer_name, customer_type, payment_terms FROM customers WHERE status='Active' ORDER BY customer_id")->fetchAll(PDO::FETCH_ASSOC);
$company_ids = $db->query("SELECT company_id FROM companies ORDER BY company_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);

if (empty($company_ids)) {
    echo "<span class='err'>ERROR: No companies found. Please add companies first.</span>\n";
    echo "</pre></body></html>"; exit;
}

// ─── Step 2: Delete previous seeder rows ────────────────────────────────────
echo "Step 2: Removing previous 2026 seeder rows...\n";
try {
    $deleted = $db->exec("DELETE FROM sales WHERE YEAR(sale_date) = 2026 AND created_by = 'seeder'");
    echo "  → $deleted rows removed.\n\n";
} catch (PDOException $e) {
    echo "<span class='err'>ERROR: " . $e->getMessage() . "</span>\n";
    echo "</pre></body></html>"; exit;
}

// ─── Price ranges (realistic 2026 Indonesian palm oil market) ───────────────
//  FFB : Rp 2,200 – 2,600 /kg (increases mid-year)
//  CPO : Rp 13,500 – 15,500 /kg
//  Kernel: Rp 8,500 – 11,000 /kg
$price_base = [
    'FFB'    => ['min' => 2200, 'max' => 2600, 'trend' => 8],   // +Rp 8/month
    'CPO'    => ['min' => 13500,'max' => 15500,'trend' => 60],
    'Kernel' => ['min' => 8500, 'max' => 11000,'trend' => 30],
];

// Qty ranges per transaction (kg)
$qty_range = [
    'FFB'    => ['min' => 20000,  'max' => 120000],
    'CPO'    => ['min' => 15000,  'max' =>  80000],
    'Kernel' => ['min' =>  5000,  'max' =>  30000],
];

// Delivery locations
$locations = [
    'Pelabuhan Belawan, Medan',
    'Pelabuhan Tanjung Priok, Jakarta',
    'Pelabuhan Dumai, Riau',
    'PKS Tebing Tinggi, Sumatra Utara',
    'Pabrik Refinery Medan',
    'Gudang Pelabuhan Sei Pakning',
    'Terminal CPO Dumai',
    'Dermaga Mill Jambi',
];

$payment_statuses = ['paid', 'paid', 'paid', 'pending', 'partial'];   // weighted toward paid

// ─── Step 3: Generate 2026 sales ────────────────────────────────────────────
echo "Step 3: Inserting 2026 sales transactions...\n";

$stmt = $db->prepare("
    INSERT INTO sales (
        sale_date, company_id, customer_id, product_type,
        quantity_kg, unit_price, total_amount, currency,
        payment_terms, payment_status, payment_date,
        delivery_location, delivery_date,
        invoice_number, reference_number,
        notes, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'IDR', ?, ?, ?, ?, ?, ?, ?, ?, 'seeder')
");

$inv_counter = 1000;
$total_inserted = 0;
$monthly_totals = [];

// Product distribution per month (varies seasonally)
$monthly_product_plan = [
    // [FFB_txns, CPO_txns, Kernel_txns]
     1 => [5, 4, 3],
     2 => [5, 4, 3],
     3 => [6, 5, 3],
     4 => [6, 5, 4],
     5 => [7, 5, 4],
     6 => [7, 6, 4],
     7 => [7, 6, 4],
     8 => [8, 6, 5],
     9 => [7, 6, 4],
    10 => [7, 5, 4],
    11 => [6, 5, 3],
    12 => [5, 4, 3],
];

for ($month = 1; $month <= 12; $month++) {
    $plan      = $monthly_product_plan[$month];
    $products  = array_merge(
        array_fill(0, $plan[0], 'FFB'),
        array_fill(0, $plan[1], 'CPO'),
        array_fill(0, $plan[2], 'Kernel')
    );
    shuffle($products);

    $month_revenue = 0;
    $month_qty     = 0;
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, 2026);

    foreach ($products as $product) {
        // Random day in the month
        $day      = rand_between(1, $days_in_month);
        $sale_date = sprintf('2026-%02d-%02d', $month, $day);

        // Price with monthly trend
        $p        = $price_base[$product];
        $base_min = $p['min'] + ($month - 1) * $p['trend'];
        $base_max = $p['max'] + ($month - 1) * $p['trend'];
        $unit_price = rand_between($base_min, $base_max);

        // Quantity
        $q         = $qty_range[$product];
        // Round to nearest 100kg
        $qty_kg    = round(rand_between($q['min'], $q['max']) / 100) * 100;

        $total     = $qty_kg * $unit_price;

        // Customer — FFB preferably direct Mill/Trader, CPO → Refinery/Exporter
        $preferred = match($product) {
            'CPO'    => array_filter($customers, fn($c) => in_array($c['customer_type'], ['Refinery','Exporter'])),
            'Kernel' => array_filter($customers, fn($c) => in_array($c['customer_type'], ['Refinery','Trader'])),
            default  => $customers,
        };
        $pool     = array_values(!empty($preferred) ? $preferred : $customers);
        $cust     = $pool[array_rand($pool)];

        // Company — rotate
        $company_id = $company_ids[($inv_counter % count($company_ids))];

        // Payment
        $pay_status = $payment_statuses[array_rand($payment_statuses)];
        // Future months (after today) are always pending
        if (strtotime($sale_date) > time()) $pay_status = 'pending';
        $pay_date = null;
        if ($pay_status === 'paid') {
            $pay_date = date('Y-m-d', strtotime($sale_date . ' +' . rand_between(7, 30) . ' days'));
        }

        // Delivery date = sale + 3-10 days
        $del_date   = date('Y-m-d', strtotime($sale_date . ' +' . rand_between(3, 10) . ' days'));
        $location   = $locations[array_rand($locations)];

        $inv_no     = sprintf('INV-2026%02d-%04d', $month, $inv_counter);
        $ref_no     = sprintf('SO-2026-%04d', $inv_counter);
        $notes      = "$product sale — {$cust['customer_name']}";

        try {
            $stmt->execute([
                $sale_date,
                $company_id,
                $cust['customer_id'],
                $product,
                $qty_kg,
                $unit_price,
                $total,
                $cust['payment_terms'],
                $pay_status,
                $pay_date,
                $location,
                $del_date,
                $inv_no,
                $ref_no,
                $notes,
            ]);
            $inv_counter++;
            $total_inserted++;
            $month_revenue += $total;
            $month_qty     += $qty_kg;
        } catch (PDOException $e) {
            echo "<span class='err'>  ERR month $month $product: " . $e->getMessage() . "</span>\n";
        }
    }

    $monthly_totals[$month] = ['revenue' => $month_revenue, 'qty' => $month_qty, 'txns' => count($products)];
    $month_name = date('F', mktime(0,0,0,$month,1,2026));
    echo "  <span class='ok'>$month_name</span>: " . count($products) . " transactions | "
       . number_format($month_qty / 1000, 1) . " MT | Rp " . number_format($month_revenue, 0) . "\n";
}

echo "\n<span class='ok'>✓ Total inserted: $total_inserted transactions</span>\n\n";

// ─── Step 4: Summary ─────────────────────────────────────────────────────────
echo "Step 4: Summary by product type...\n";
try {
    $summary = $db->query("
        SELECT product_type,
               COUNT(*) as txns,
               SUM(quantity_kg)/1000 as total_mt,
               AVG(unit_price) as avg_price,
               SUM(total_amount) as total_revenue
        FROM sales
        WHERE YEAR(sale_date)=2026 AND created_by='seeder'
        GROUP BY product_type
        ORDER BY total_revenue DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    printf("  %-10s %6s %12s %12s %20s\n", 'Product', 'Txns', 'Total MT', 'Avg Price', 'Revenue (Rp)');
    echo "  " . str_repeat('-', 65) . "\n";
    $grand_rev = 0;
    foreach ($summary as $r) {
        printf("  %-10s %6d %12s %12s %20s\n",
            $r['product_type'],
            $r['txns'],
            number_format($r['total_mt'], 1),
            number_format($r['avg_price'], 0),
            number_format($r['total_revenue'], 0)
        );
        $grand_rev += $r['total_revenue'];
    }
    echo "  " . str_repeat('-', 65) . "\n";
    printf("  %-10s %6d %12s %12s %20s\n", 'TOTAL', $total_inserted, '', '', number_format($grand_rev, 0));
} catch (PDOException $e) {
    echo "<span class='err'>Summary error: " . $e->getMessage() . "</span>\n";
}

echo "\n<span class='ok'>✓ Done! Sales data for 2026 is ready.</span>\n";
echo "</pre>";
echo '<a class="btn" href="sales.php?year=2026">→ View Sales 2026</a>';
echo '</body></html>';
