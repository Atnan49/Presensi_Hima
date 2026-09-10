<?php
// Logout sesi administrator presensi

require_once 'config.php';
startSessionSafe();

// Hancurkan semua data sesi
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}
session_destroy();

header('Location: login?msg=logged_out');
exit;
