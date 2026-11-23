<?php
session_start();

// destroy session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// clear remember cookie (so auto-login won't happen again)
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie('remember', '', time() - 3600, '/', '', $secure, true);

// optional toast message
session_start();
$_SESSION['toast'] = ['type' => 'info', 'message' => 'You have been logged out.'];

// redirect to login
header('Location: login.php');
exit;
