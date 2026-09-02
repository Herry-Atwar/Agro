<?php
/**
 * One-time setup: Rencana Biaya 2026
 * Run once: https://inodesain.com/agro/setup_cost_plan.php — then delete this file.
 */
// ── Must be FIRST — before any require ──────────────────────────────────────
ini_set('display_errors', 1);
error_reporting(E_ALL);

// database.php calls ob_start(); we include it, then drain its buffer immediately
require_once 'config/database.php';
while (ob_get_level() > 0) ob_end_clean();  // drop injected content, send nothing yet

// Now it is safe to output
echo '<pre style="font-family:monospace;font-size:13px;padding:16px;">';
echo "=== Setup Rencana Biaya 2026 ===\n\n";
flush();

$db = getDB();

// ── 1. cost_plan_assumptions ──────────────────────────────────────────────────
// Use INT for year (YEAR type can cause strict-mode issues on some hosts)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS cost_plan_assumptions (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        year                INT           NOT NULL,
        company_id          INT           DEFAULT NULL,
        daily_wage_kebun    DECIMAL(12,2) NOT NULL DEFAULT 180000,
        daily_wage_pks      DECIMAL(12,2) NOT NULL DEFAULT 200000,
        price_urea          DECIMAL(12,2) NOT NULL DEFAULT 3500,
        price_tsp           DECIMAL(12,2) NOT NULL DEFAULT 6500,
        price_kcl           DECIMAL(12,2) NOT NULL DEFAULT 7200,
        price_dolomite      DECIMAL(12,2) NOT NULL DEFAULT 2800,
        price_herbicide     DECIMAL(12,2) NOT NULL DEFAULT 85000,
        price_insecticide   DECIMAL(12,2) NOT NULL DEFAULT 120000,
        price_diesel        DECIMAL(12,2) NOT NULL DEFAULT 7000,
        price_lubricant     DECIMAL(12,2) NOT NULL DEFAULT 45000,
        overhead_pct_kebun  DECIMAL(6,4)  NOT NULL DEFAULT 0.0800,
        overhead_pct_pks    DECIMAL(6,4)  NOT NULL DEFAULT 0.1000,
        depreciation_kebun  DECIMAL(15,2) NOT NULL DEFAULT 0,
        depreciation_pks    DECIMAL(15,2) NOT NULL DEFAULT 0,
        updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by          VARCHAR(100)  DEFAULT NULL,
        UNIQUE KEY uq_year_company (year, company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Table cost_plan_assumptions\n"; flush();
} catch (Exception $e) {
    echo "[FAIL] cost_plan_assumptions: " . $e->getMessage() . "\n</pre>";
    exit;
}

// ── 2. cost_plan_lines ────────────────────────────────────────────────────────
// Use INT for year, VARCHAR for unit_type (ENUM can cause issues on some hosts)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS cost_plan_lines (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        year             INT           NOT NULL,
        company_id       INT           DEFAULT NULL,
        business_unit_id INT           DEFAULT NULL,
        unit_type        VARCHAR(20)   NOT NULL DEFAULT 'kebun',
        cost_category    VARCHAR(50)   NOT NULL,
        cost_item        VARCHAR(150)  NOT NULL,
        unit             VARCHAR(30)   DEFAULT NULL,
        volume           DECIMAL(15,2) NOT NULL DEFAULT 0,
        unit_price       DECIMAL(15,2) NOT NULL DEFAULT 0,
        planned_amount   DECIMAL(18,2) NOT NULL DEFAULT 0,
        actual_amount    DECIMAL(18,2) NOT NULL DEFAULT 0,
        jan DECIMAL(15,2) DEFAULT 0, feb DECIMAL(15,2) DEFAULT 0,
        mar DECIMAL(15,2) DEFAULT 0, apr DECIMAL(15,2) DEFAULT 0,
        mei DECIMAL(15,2) DEFAULT 0, jun DECIMAL(15,2) DEFAULT 0,
        jul DECIMAL(15,2) DEFAULT 0, agu DECIMAL(15,2) DEFAULT 0,
        sep DECIMAL(15,2) DEFAULT 0, okt DECIMAL(15,2) DEFAULT 0,
        nov DECIMAL(15,2) DEFAULT 0, des DECIMAL(15,2) DEFAULT 0,
        notes            TEXT          DEFAULT NULL,
        sort_order       INT           NOT NULL DEFAULT 0,
        updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by       VARCHAR(100)  DEFAULT NULL,
        KEY idx_year_unit (year, unit_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Table cost_plan_lines\n"; flush();
} catch (Exception $e) {
    echo "[FAIL] cost_plan_lines: " . $e->getMessage() . "\n</pre>";
    exit;
}

// ── 3. Seed assumptions for company_id = 1001 (PT Borneo Sawit Mandiri) ───────
$COMPANY_ID = 1001;
try {
    foreach ([2025, 2026, 2027] as $y) {
        $db->prepare("INSERT IGNORE INTO cost_plan_assumptions
            (year,company_id,daily_wage_kebun,daily_wage_pks,price_urea,price_tsp,price_kcl,
             price_dolomite,price_herbicide,price_insecticide,price_diesel,price_lubricant,
             overhead_pct_kebun,overhead_pct_pks,depreciation_kebun,depreciation_pks,updated_by)
            VALUES (?,?,180000,200000,3500,6500,7200,2800,85000,120000,7000,45000,
                    0.08,0.10,1200000000,800000000,'system')"
        )->execute([$y, $COMPANY_ID]);
    }
    echo "[OK] Assumptions seeded (2025-2027)\n"; flush();
} catch (Exception $e) {
    echo "[FAIL] Seed assumptions: " . $e->getMessage() . "\n</pre>";
    exit;
}

// ── 4. Seed cost plan lines 2026 for company_id = 1001 ────────────────────────
// planned_amount = volume * unit_price
// actual_amount  = planned * realization_ratio  (varies: good=close, bad=over/under)
// Monthly distribution: seasonality-aware (panen puncak Mar-Jun & Sep-Nov)
// Peak months weight: 1=low, 2=normal, 3=peak
function spread($total, $weights): array {
    $sum = array_sum($weights);
    $out = [];
    foreach ($weights as $w) $out[] = round($total * $w / $sum, 0);
    $diff = (int)$total - array_sum($out);
    $out[11] += $diff; // absorb rounding remainder in December
    return $out;
}

// Lines: [unit_type, category, item, unit, volume, unit_price, actual_ratio, monthly_dist, sort, notes]
// actual_ratio: 1.0=on-budget, <1=under-spend(good), >1=over-spend(bad)
$lines = [
    // ════ KEBUN ════
    // LABOR — good: panen on-budget, pemupukan under (efisiensi), gulma over (hama meningkat)
    ['kebun','labor','Panen TBS','HK',   36000, 180000, 1.02, 'peak',   1, 'Sesuai rencana, sedikit lebih tinggi karena puncak panen diperpanjang'],
    ['kebun','labor','Pemupukan','HK',   12000, 180000, 0.94, 'even',   2, 'Efisiensi penggunaan HK dengan mekanisasi pemupukan'],
    ['kebun','labor','Pengendalian Gulma (kimia)','HK', 6800, 180000, 1.18, 'even', 3, 'Populasi gulma lebih tinggi dari prediksi akibat curah hujan tinggi'],
    ['kebun','labor','Pengendalian Hama & Penyakit','HK', 2400, 180000, 1.35, 'even', 4, '⚠️ Serangan ulat api di Div 2 & 3 menyebabkan biaya melonjak 35%'],
    ['kebun','labor','Pemeliharaan Jalan & Parit','HK', 3600, 180000, 0.97, 'even', 5, ''],
    ['kebun','labor','Supervisi & Administrasi Kebun','HK', 1200, 180000, 1.00, 'even', 6, ''],

    // FERTILIZER — good: urea on-budget, tapi kekurangan KCl (supply issue)
    ['kebun','fertilizer','Pupuk Urea','kg',  1200000, 3500, 0.98, 'even',  10, 'Realisasi sesuai dosis rekomendasi'],
    ['kebun','fertilizer','Pupuk TSP/SP36','kg', 800000, 6500, 1.05, 'even', 11, 'Harga naik 5% di Q3 dari supplier'],
    ['kebun','fertilizer','Pupuk KCl/MOP','kg',  900000, 7200, 0.72, 'even', 12, '⚠️ Realisasi hanya 72% — kelangkaan KCl global, diganti sebagian dengan MOP lokal'],
    ['kebun','fertilizer','Dolomit/Kiserit','kg', 600000, 2800, 1.00, 'even', 13, ''],
    ['kebun','fertilizer','Pupuk Organik/Kompos','kg', 300000, 1500, 1.10, 'even', 14, 'Tambahan aplikasi untuk blok TBM'],

    // CHEMICAL — over budget (hama tinggi)
    ['kebun','chemical','Herbisida Sistemik','liter', 18000, 85000, 1.22, 'even', 20, '⚠️ Volume aktual 22% di atas rencana — gulma resisten terdeteksi di 3 divisi'],
    ['kebun','chemical','Herbisida Kontak','liter',    6000, 42000, 1.08, 'even', 21, ''],
    ['kebun','chemical','Insektisida','liter',          3600,120000, 1.45, 'even', 22, '⚠️ Serangan ulat api membutuhkan 2 putaran penyemprotan ekstra'],
    ['kebun','chemical','Fungisida','liter',            1200, 95000, 0.85, 'even', 23, 'Tidak ada serangan jamur signifikan — efisiensi biaya'],
    ['kebun','chemical','Akarisida','liter',             600,145000, 0.60, 'even', 24, 'Serangan tungau lebih rendah dari prediksi'],

    // FUEL — over budget (infrastruktur rusak, jarak angkut jauh)
    ['kebun','fuel','Solar Alat Berat (excavator, grader)','liter', 360000, 7000, 1.15, 'peak', 30, '⚠️ Kerusakan jalan pasca banjir membutuhkan mobilisasi alat berat lebih banyak'],
    ['kebun','fuel','Solar Kendaraan Operasional','liter',          120000, 7000, 1.08, 'peak', 31, 'Volume tbs tinggi → frekuensi angkut meningkat'],
    ['kebun','fuel','Pelumas & Oli Mesin','liter',                   12000,45000, 1.00, 'flat', 32, ''],
    ['kebun','fuel','Bahan Bakar Pompa Air','liter',                  24000, 6500, 0.78, 'flat', 33, 'Curah hujan memadai, pompa irigasi jarang digunakan'],

    // MAINTENANCE — mixed
    ['kebun','maintenance','Perawatan Alat Panen (dodos, egrek)','unit', 240, 850000, 0.92, 'flat', 40, 'Efisiensi — bengkel in-house ditingkatkan'],
    ['kebun','maintenance','Perawatan Kendaraan Operasional','unit',      48,2500000, 1.30, 'flat', 41, '⚠️ 2 unit truk angkut TBS mengalami kerusakan mayor tidak terduga'],
    ['kebun','maintenance','Perawatan Infrastruktur (jalan, jembatan)','ls', 1, 450000000, 1.55, 'flat', 42, '⚠️ Banjir Q2 merusak 3 jembatan dan 4 km jalan utama — biaya darurat sangat tinggi'],
    ['kebun','maintenance','Perawatan Alat Semprot','unit',              120,  250000, 0.88, 'flat', 43, ''],
    ['kebun','maintenance','Perawatan Genset & Instalasi Listrik','unit',  12, 1500000, 1.00, 'flat', 44, ''],

    // DEPRECIATION
    ['kebun','depreciation','Penyusutan Alat Berat','ls', 1, 480000000, 1.00, 'flat', 50, 'Sesuai jadwal penyusutan'],
    ['kebun','depreciation','Penyusutan Kendaraan','ls',  1, 360000000, 1.00, 'flat', 51, ''],
    ['kebun','depreciation','Penyusutan TBM (Tanaman Belum Menghasilkan)','ls', 1, 360000000, 1.00, 'flat', 52, ''],

    // OVERHEAD
    ['kebun','overhead','Gaji Staf & Manajer Kebun','ls',       1, 2400000000, 1.02, 'flat', 60, 'Kenaikan gaji 2% sesuai PKB'],
    ['kebun','overhead','Asuransi Aset Kebun','ls',              1,  180000000, 1.00, 'flat', 61, ''],
    ['kebun','overhead','Biaya Kantor & Komunikasi','ls',        1,   60000000, 1.12, 'flat', 62, 'Internet upgrade untuk monitoring digital'],
    ['kebun','overhead','Perjalanan Dinas & Training','ls',      1,   75000000, 0.65, 'flat', 63, 'Training dikurangi — kondisi anggaran ketat Q3'],
    ['kebun','overhead','Pajak Bumi & Bangunan (PBB)','ls',      1,  320000000, 1.00, 'flat', 64, ''],

    // ════ PKS ════
    // LABOR PKS — good performance
    ['pks','labor','Operator Stasiun Pengolahan','HK',  7200, 200000, 1.00, 'flat',  1, 'Sesuai rencana'],
    ['pks','labor','Teknisi & Mekanik Mesin','HK',      3600, 200000, 0.98, 'flat',  2, 'Efisiensi jadwal pemeliharaan terjadwal'],
    ['pks','labor','Operator Laboratorium','HK',        1800, 200000, 1.00, 'flat',  3, ''],
    ['pks','labor','Security & Driver PKS','HK',        2880, 200000, 1.05, 'flat',  4, 'Penambahan 1 shift keamanan notifikasi pagi'],
    ['pks','labor','Administrasi & Finance PKS','HK',   1440, 200000, 1.00, 'flat',  5, ''],

    // CHEMICAL PKS — over budget (kualitas TBS turun, butuh lebih banyak bahan)
    ['pks','chemical','Phosphoric Acid','kg',           12000, 85000, 1.00, 'peak', 10, ''],
    ['pks','chemical','Bahan Kimia Water Treatment','kg',18000, 22000, 1.08, 'flat', 11, 'Kualitas air baku menurun di musim kemarau'],
    ['pks','chemical','Bahan Kimia Boiler Treatment','kg', 6000, 35000, 1.00, 'flat', 12, ''],
    ['pks','chemical','Bahan Bakar Proses (Cangkang)','ton',  3600, 450000, 0.90, 'peak', 13, 'Efisiensi: cangkang berlebih dari produksi digunakan ulang'],
    ['pks','chemical','Grease & Seals','kg',             2400, 55000, 1.15, 'flat', 14, '⚠️ Pemakaian lebih tinggi — bearing pompa CPO sering aus'],

    // FUEL PKS — over budget
    ['pks','fuel','Solar Genset (backup)','liter',     120000, 7000, 1.35, 'flat', 20, '⚠️ Pemadaman PLN 18 kali sepanjang tahun — genset berjalan 680 jam ekstra'],
    ['pks','fuel','Solar Forklift & Kendaraan PKS','liter', 48000, 7000, 1.05, 'peak', 21, ''],
    ['pks','fuel','Pelumas Mesin PKS','liter',          24000,45000, 1.08, 'flat', 22, '⚠️ Penggantian interval lebih cepat akibat kualitas TBS kotor'],

    // MAINTENANCE PKS — major over budget
    ['pks','maintenance','Perawatan Rutin Mesin (PM)','ls',     1, 600000000, 0.95, 'flat', 30, 'Jadwal PM berjalan baik — tidak ada breakdown besar bulan Jan-Jun'],
    ['pks','maintenance','Perbaikan Tidak Terjadwal (CM)','ls', 1, 200000000, 2.10, 'flat', 31, '⚠️ KRITIS: Breakdown turbin uap Juli membutuhkan biaya perbaikan Rp 420 juta (2.1x budget)'],
    ['pks','maintenance','Suku Cadang Impor','ls',               1, 800000000, 1.40, 'flat', 32, '⚠️ Kurs USD melemah + lead time panjang — suku cadang lebih mahal 40%'],
    ['pks','maintenance','Perawatan Instalasi Listrik HV','ls',  1, 150000000, 1.00, 'flat', 33, ''],
    ['pks','maintenance','Perawatan Sistem EFB/Composting','ls', 1,  80000000, 0.75, 'flat', 34, 'Program kompos ditunda ke 2027'],

    // DEPRECIATION PKS
    ['pks','depreciation','Penyusutan Mesin Pengolahan','ls',    1, 480000000, 1.00, 'flat', 40, ''],
    ['pks','depreciation','Penyusutan Bangunan PKS','ls',        1, 120000000, 1.00, 'flat', 41, ''],
    ['pks','depreciation','Penyusutan Peralatan Lab & QC','ls',  1,  24000000, 1.00, 'flat', 42, ''],

    // OVERHEAD PKS
    ['pks','overhead','Gaji Staf & Manajer PKS','ls',            1, 1800000000, 1.03, 'flat', 50, 'Termasuk tunjangan produksi'],
    ['pks','overhead','Asuransi Pabrik & Mesin','ls',            1,  240000000, 1.00, 'flat', 51, ''],
    ['pks','overhead','Listrik PLN PKS','ls',                    1,  360000000, 1.20, 'flat', 52, '⚠️ Tarif dasar listrik naik 20% per Juli 2026'],
    ['pks','overhead','Biaya Lingkungan (IPAL, Limbah)','ls',    1,   90000000, 0.88, 'flat', 53, 'Efisiensi operasional IPAL'],
    ['pks','overhead','Sertifikasi ISPO/RSPO','ls',              1,   45000000, 1.00, 'flat', 54, ''],
    ['pks','overhead','PBB & Pajak Daerah','ls',                 1,   85000000, 1.00, 'flat', 55, ''],
];

// ── 4. Seed cost_plan_lines 2026 ─────────────────────────────────────────────
try {
    $db->prepare("DELETE FROM cost_plan_lines WHERE year = 2026 AND company_id = ?")->execute([$COMPANY_ID]);
    echo "[OK] Cleared existing 2026 lines\n"; flush();
} catch (Exception $e) {
    echo "[FAIL] Delete: " . $e->getMessage() . "\n</pre>";
    exit;
}

// 25 bound params: year,company_id,unit_type,cost_category,cost_item,unit,
//                  volume,unit_price,planned_amount,actual_amount,
//                  jan…des (12), notes, sort_order, updated_by
$ins = $db->prepare("INSERT INTO cost_plan_lines
    (year, company_id, unit_type, cost_category, cost_item, unit, volume, unit_price,
     planned_amount, actual_amount,
     jan, feb, mar, apr, mei, jun, jul, agu, sep, okt, nov, des,
     notes, sort_order, updated_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

$peak_w = [1,1,3,3,3,3,2,1,3,3,3,2];
$flat_w = [1,1,1,1,1,1,1,1,1,1,1,1];
$inserted = 0;

foreach ($lines as $idx => $l) {
    [$utype,$cat,$item,$unit,$vol,$price,$ratio,$dist,$sort,$notes] = $l;
    $planned = (int) round($vol * $price, 0);
    $actual  = (int) round($planned * $ratio, 0);
    $weights = $dist === 'peak' ? $peak_w : $flat_w;
    $monthly = spread($planned, $weights); // 12 ints
    $params  = array_merge(
        [2026, $COMPANY_ID, $utype, $cat, $item, $unit, (float)$vol, (float)$price, $planned, $actual],
        $monthly,
        [$notes, (int)$sort, 'system']
    );
    try {
        $ins->execute($params);
        $inserted++;
    } catch (Exception $e) {
        echo "[FAIL] Line #{$idx} ({$item}): " . $e->getMessage() . "\n</pre>";
        exit;
    }
}
echo "[OK] Inserted {$inserted} lines\n"; flush();

echo "\n=== SELESAI ===\n";
echo "cost_plan_assumptions : "
    . $db->query("SELECT COUNT(*) FROM cost_plan_assumptions WHERE company_id={$COMPANY_ID}")->fetchColumn()
    . " rows\n";
echo "cost_plan_lines 2026  : "
    . $db->query("SELECT COUNT(*) FROM cost_plan_lines WHERE year=2026 AND company_id={$COMPANY_ID}")->fetchColumn()
    . " rows\n\n";

// Quick summary
$rows = $db->query("
    SELECT unit_type, cost_category,
           SUM(planned_amount) AS plan,
           SUM(actual_amount)  AS actual
    FROM cost_plan_lines
    WHERE year=2026 AND company_id={$COMPANY_ID}
    GROUP BY unit_type, cost_category
    ORDER BY unit_type, cost_category
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $var  = $r['actual'] - $r['plan'];
    $pct  = $r['plan'] > 0 ? round($var / $r['plan'] * 100, 1) : 0;
    $flag = abs($pct) > 15 ? ($pct > 0 ? ' ⚠️  OVER' : ' ✅ UNDER') : '';
    printf("%-8s  %-15s  Plan: %15s  Actual: %15s  Var: %+.1f%%%s\n",
        $r['unit_type'], $r['cost_category'],
        number_format($r['plan']), number_format($r['actual']), $pct, $flag);
}

echo "\n--------------------------------------------------------------\n";
$tot_plan = array_sum(array_column($rows, 'plan'));
$tot_act  = array_sum(array_column($rows, 'actual'));
$tot_var  = $tot_plan > 0 ? round(($tot_act - $tot_plan) / $tot_plan * 100, 1) : 0;
printf("TOTAL                              Plan: %15s  Actual: %15s  Var: %+.1f%%\n",
    number_format($tot_plan), number_format($tot_act), $tot_var);

echo "\n";
echo "Selesai! Akses https://inodesain.com/agro/cost_plan.php\n";
echo "HAPUS file ini setelah selesai.\n";
echo "</pre>";
