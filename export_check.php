<?php
/**
 * export_check.php — upload to cloud to confirm file routing works
 * Access: https://inodesain.com/agro/export_check.php
 * Delete after confirming.
 */
require_once 'includes/auth.php';
require_login();
header('Content-Type: text/plain; charset=UTF-8');
echo "export_check.php OK\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "ZipArchive: " . (extension_loaded('zip') ? 'YES' : 'NO') . "\n";
echo "__DIR__: " . __DIR__ . "\n";
echo "export_pl.php exists: "   . (file_exists(__DIR__ . '/export_pl.php')      ? 'YES' : 'NO') . "\n";
echo "export_bs.php exists: "   . (file_exists(__DIR__ . '/export_bs.php')      ? 'YES' : 'NO') . "\n";
echo "export_generic.php exists: " . (file_exists(__DIR__ . '/export_generic.php') ? 'YES' : 'NO') . "\n";
echo "export_report.php size: " . filesize(__DIR__ . '/export_report.php') . " bytes\n";
$first = '';
$fh = fopen(__DIR__ . '/export_report.php', 'r');
if ($fh) { $first = fread($fh, 300); fclose($fh); }
echo "export_report.php first 300 chars:\n" . $first . "\n";
