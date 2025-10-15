<?php
/**
 * Authentication helper functions
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Load config to get session timeout
    $config = require __DIR__ . '/config.php';

    // Set session cookie parameters before starting session
    session_set_cookie_params([
        'lifetime' => $config['session_timeout'],
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Set server-side session lifetime
    ini_set('session.gc_maxlifetime', $config['session_timeout']);

    session_start();
}

/**
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

/**
 * Require authentication - redirect to login if not logged in
 */
function require_auth($config) {
    if (!is_logged_in()) {
        header('Location: ' . $config['base_path'] . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    // Check session timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $config['session_timeout'])) {
        logout();
        header('Location: ' . $config['base_path'] . '/login.php?timeout=1&redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    // Update last activity time
    $_SESSION['last_activity'] = time();
}

/**
 * Perform login
 */
function login($username, $password, $config) {
    if ($username === $config['username'] && $password === $config['password']) {
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        $_SESSION['login_time'] = time();
        return true;
    }
    return false;
}

/**
 * Perform logout
 */
function logout() {
    $_SESSION = array();

    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();
}
