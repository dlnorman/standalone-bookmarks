<?php
/**
 * Authentication helper functions
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Load config to get session timeout
    $config = require __DIR__ . '/config.php';

    // Set up dedicated session save path to prevent system cleanup
    $session_path = __DIR__ . '/sessions';
    if (!is_dir($session_path)) {
        mkdir($session_path, 0700, true);
    }

    // Ensure the sessions directory is writable
    if (is_writable($session_path)) {
        ini_set('session.save_path', $session_path);
    }

    // Set session garbage collection settings
    ini_set('session.gc_maxlifetime', $config['session_timeout']);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 100);

    // Set session cookie parameters before starting session
    session_set_cookie_params([
        'lifetime' => $config['session_timeout'],
        'path' => $config['base_path'] ?: '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();

    // Validate session integrity
    if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
        // Check if session has expired based on last activity
        if (isset($_SESSION['last_activity']) &&
            (time() - $_SESSION['last_activity'] > $config['session_timeout'])) {
            // Session expired - destroy it
            session_unset();
            $_SESSION['session_expired'] = true;
        } else {
            // Session still valid - update last activity
            $_SESSION['last_activity'] = time();
        }
    }
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
    // Check if session was expired
    if (isset($_SESSION['session_expired']) && $_SESSION['session_expired'] === true) {
        session_destroy();
        header('Location: ' . $config['base_path'] . '/login.php?timeout=1&redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    if (!is_logged_in()) {
        header('Location: ' . $config['base_path'] . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

/**
 * Perform login
 */
function login($username, $password, $config) {
    if ($username === $config['username'] && $password === $config['password']) {
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

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
