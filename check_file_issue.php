<?php
/**
 * Quick diagnostic to check why divisions.php shows "page doesn't exist"
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>File Existence Check</h1>";
echo "<hr>";

// Check if divisions.php exists
$file = 'divisions.php';
echo "<h3>Checking: $file</h3>";

if (file_exists($file)) {
    echo "✓ File EXISTS<br>";
    echo "File size: " . filesize($file) . " bytes (" . round(filesize($file)/1024, 2) . " KB)<br>";
    echo "Permissions: " . substr(sprintf('%o', fileperms($file)), -4) . "<br>";
    echo "Is readable: " . (is_readable($file) ? 'YES' : 'NO') . "<br>";
    echo "Is writable: " . (is_writable($file) ? 'YES' : 'NO') . "<br>";
    
    // Try to read first 100 characters
    echo "<br><strong>First 100 characters of file:</strong><br>";
    $content = file_get_contents($file, false, null, 0, 100);
    echo "<pre>" . htmlspecialchars($content) . "</pre>";
    
    // Check if it's a valid PHP file
    if (substr($content, 0, 5) === '<?php') {
        echo "✓ Valid PHP file (starts with <?php)<br>";
    } else {
        echo "✗ NOT a valid PHP file (doesn't start with <?php)<br>";
        echo "First 5 chars: " . htmlspecialchars(substr($content, 0, 5)) . "<br>";
    }
    
} else {
    echo "✗ File DOES NOT EXIST<br>";
    echo "<strong>This is the problem!</strong><br>";
}

echo "<hr>";

// List all PHP files in current directory
echo "<h3>All PHP files in this directory:</h3>";
$files = glob('*.php');
sort($files);
echo "<ul>";
foreach ($files as $f) {
    $size = filesize($f);
    $highlight = ($f == 'divisions.php') ? ' <strong style="color: red;">&larr; THIS ONE</strong>' : '';
    echo "<li>$f (" . round($size/1024, 2) . " KB)$highlight</li>";
}
echo "</ul>";

echo "<hr>";

// Check .htaccess
echo "<h3>.htaccess Check:</h3>";
if (file_exists('.htaccess')) {
    echo "✓ .htaccess exists<br>";
    echo "File size: " . filesize('.htaccess') . " bytes<br>";
    echo "<br><strong>Content:</strong><br>";
    echo "<pre>" . htmlspecialchars(file_get_contents('.htaccess')) . "</pre>";
} else {
    echo "✗ .htaccess NOT FOUND<br>";
}

echo "<hr>";
echo "<h3>Direct Access Test:</h3>";
echo "Try these links:<br>";
echo "<a href='divisions.php' target='_blank'>Direct: divisions.php</a><br>";
echo "<a href='./divisions.php' target='_blank'>With ./: ./divisions.php</a><br>";
echo "<a href='/agro/divisions.php' target='_blank'>Absolute: /agro/divisions.php</a><br>";

echo "<hr>";
echo "<a href='index.php'>← Back to Dashboard</a>";
?>

// Powered by IBM Bob
