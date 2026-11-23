<?php
// auth_check.php
session_start();
define('AUTH_SECRET', 'change_this_to_a_long_random_secret_string_!@#'); // same secret as login

// If already in session, ok
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['username'])) {
    // optionally refresh session expiry logic here
    return;
}

// If session not present, check remember cookie
if (!empty($_COOKIE['remember'])) {
    $cookie = base64_decode($_COOKIE['remember']);
    if ($cookie !== false) {
        $parts = explode('|', $cookie);
        // Expecting: username|expiry|hmac
        if (count($parts) === 3) {
            list($username, $expiry, $hmac) = $parts;
            $expected = hash_hmac('sha256', $username . '|' . $expiry, AUTH_SECRET);

            // Validate HMAC and expiry
            if (hash_equals($expected, $hmac) && is_numeric($expiry) && intval($expiry) > time()) {
                // All good — restore session without forcing re-login
                session_regenerate_id(true);
                $_SESSION['logged_in'] = true;
                $_SESSION['username'] = $username;
                return;
            }
        }
    }
}

// If reached here, not authenticated — redirect to login
header('Location: login.php');
exit;
