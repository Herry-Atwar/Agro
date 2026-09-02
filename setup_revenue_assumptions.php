<?php
/**
 * One-time setup: create revenue_assumptions table and seed defaults.
 * Run once then delete or protect this file.
 */
require_once 'config/database.php';
$db = getDB();

$db->exec("
CREATE TABLE IF NOT EXISTS revenue_assumptions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    year             YEAR          NOT NULL,
    price_cpo_idr    DECIMAL(12,2) NOT NULL DEFAULT 12500.00  COMMENT 'Harga CPO Rp/kg (lokal)',
    price_pk_idr     DECIMAL(12,2) NOT NULL DEFAULT 6200.00   COMMENT 'Harga PK Rp/kg (lokal)',
    oer_mhk          DECIMAL(6,4)  NOT NULL DEFAULT 0.2200    COMMENT 'OER PKS Mahakam',
    ker_mhk          DECIMAL(6,4)  NOT NULL DEFAULT 0.0520    COMMENT 'KER PKS Mahakam',
    oer_blg          DECIMAL(6,4)  NOT NULL DEFAULT 0.2100    COMMENT 'OER PKS Bulungan',
    ker_blg          DECIMAL(6,4)  NOT NULL DEFAULT 0.0500    COMMENT 'KER PKS Bulungan',
    usd_idr_rate     DECIMAL(10,2) NOT NULL DEFAULT 16000.00  COMMENT 'Kurs USD/IDR',
    price_cpo_usd    DECIMAL(10,2) NOT NULL DEFAULT 820.00    COMMENT 'Harga CPO USD/MT (referensi)',
    price_pk_usd     DECIMAL(10,2) NOT NULL DEFAULT 390.00    COMMENT 'Harga PK USD/MT (referensi)',
    export_ratio_cpo DECIMAL(5,4)  NOT NULL DEFAULT 0.0000    COMMENT 'Rasio ekspor CPO (0=semua lokal)',
    export_ratio_pk  DECIMAL(5,4)  NOT NULL DEFAULT 0.0000    COMMENT 'Rasio ekspor PK (0=semua lokal)',
    updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by       VARCHAR(100)  DEFAULT NULL,
    UNIQUE KEY uq_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Asumsi perhitungan rencana pendapatan per tahun';
");

$ins = $db->prepare("
    INSERT IGNORE INTO revenue_assumptions
        (year, price_cpo_idr, price_pk_idr, oer_mhk, ker_mhk, oer_blg, ker_blg,
         usd_idr_rate, price_cpo_usd, price_pk_usd, export_ratio_cpo, export_ratio_pk, updated_by)
    VALUES (?, 12500, 6200, 0.2200, 0.0520, 0.2100, 0.0500, 16000, 820, 390, 0, 0, 'system')
");
foreach ([2025, 2026, 2027] as $y) {
    $ins->execute([$y]);
}

echo "<pre>✅ Tabel revenue_assumptions berhasil dibuat dan di-seed.\n";
$rows = $db->query("SELECT * FROM revenue_assumptions ORDER BY year")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "Year {$r['year']}: CPO Rp{$r['price_cpo_idr']}/kg | PK Rp{$r['price_pk_idr']}/kg | USD/IDR {$r['usd_idr_rate']} | ExportCPO {$r['export_ratio_cpo']} | ExportPK {$r['export_ratio_pk']}\n";
}
echo "\n⚠️  Hapus file ini setelah setup selesai.</pre>";
