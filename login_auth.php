<?php
session_start();

// Secret key — change to a long random string in production
define('AUTH_SECRET', 'change_this_to_a_long_random_secret_string_!@#');

// Multiple valid users (username => password)
$valid_users = [
    'ziauddin' => 'ziauddin123_',
    'khurram' => 'noc123_',
    'noman' => 'ziauddinnoc123_',
    'admin' => 'spotcomm123_',
    'Knoativ' => 'knoativ123_',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Check if username exists and password matches
    if (isset($valid_users[$username]) && $valid_users[$username] === $password) {
        // Regenerate session id for security
        session_regenerate_id(true);

        $_SESSION['logged_in'] = true;
        $_SESSION['username'] = $username; // Store which user logged in
        // Include the config to get userIpMap
        require_once 'config.php';
        $_SESSION['allowed_ips'] = $userIpMap[$username] ?? []; // Store allowed IPs for this user
        $_SESSION['toast'] = ['type' => 'success', 'message' => '✅ Login successful! Welcome ' . ucfirst($username)];

        // Create a signed "remember me" cookie valid for 30 days
        $expiry = time() + 30 * 24 * 60 * 60; // 30 days in seconds
        $data = $username . '|' . $expiry;
        $hmac = hash_hmac('sha256', $data, AUTH_SECRET);
        $cookie_value = base64_encode($data . '|' . $hmac);

        // Secure cookie flags
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(
            'remember',
            $cookie_value,
            $expiry,
            '/',
            '', // domain (leave empty to default)
            $secure, // secure (only send on HTTPS if available)
            true // httponly
        );

        header('Location: index.php');
        exit;
    } else {
        $_SESSION['toast'] = ['type' => 'error', 'message' => '❌ Invalid username or password'];
        header('Location: login.php');
        exit;
    }
}
?>