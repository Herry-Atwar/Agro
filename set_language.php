<?php
/**
 * set_language.php
 * Lets a logged-in user toggle their UI language on-the-fly.
 * Updates the session immediately so the next page load reflects the change,
 * and persists the preference to the database so it survives re-login.
 */
require_once 'config/database.php';
require_once 'includes/auth.php';

// Only process while logged in
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

$lang = $_GET['lang'] ?? 'en';
if (!in_array($lang, ['en', 'id'], true)) {
    $lang = 'en';
}

// Update session immediately
$_SESSION['preferred_language'] = $lang;

// Persist to database
$db = getDB();
try {
    $stmt = $db->prepare("UPDATE users SET preferred_language = :lang WHERE id = :id");
    $stmt->execute([':lang' => $lang, ':id' => $_SESSION['user_id']]);
} catch (PDOException $e) {
    // Column may not exist yet on older deployments — silently ignore
    error_log('set_language: could not persist language preference: ' . $e->getMessage());
}

// Redirect back to the referring page, or dashboard
$back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header('Location: ' . $back);
exit;
