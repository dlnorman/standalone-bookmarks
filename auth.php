<?php
/**
 * Authentication helper functions
 */

// Function to start secure session
function start_secure_session()
{
    if (session_status() !== PHP_SESSION_NONE)
        return;

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

    // Security Headers
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    // Prevent MIME sniffing
    header('X-Content-Type-Options: nosniff');
    // strict referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // HSTS (if HTTPS)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    session_start();

    // Validate session integrity
    if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
        // Check if session has expired based on last activity
        if (
            isset($_SESSION['last_activity']) &&
            (time() - $_SESSION['last_activity'] > $config['session_timeout'])
        ) {
            // Session expired - destroy it
            session_unset();
            $_SESSION['session_expired'] = true;
        } else {
            // Session still valid - update last activity
            $_SESSION['last_activity'] = time();
        }
    }
}

// Determine if we should start the session
// 1. If the user sends a session cookie, they might be logged in, so resume session.
// 2. If the calling script explicitly requires a session (e.g. login page), force start.
$should_start_session = isset($_COOKIE[session_name()]) || (defined('FORCE_SESSION_START') && FORCE_SESSION_START === true);

if ($should_start_session) {
    start_secure_session();
}

/**
 * Check if user is logged in
 */
function is_logged_in()
{
    if (session_status() === PHP_SESSION_NONE)
        return false;
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

/**
 * Require authentication - redirect to login if not logged in
 */
function require_auth($config)
{
    // Load security functions if not already loaded
    if (!function_exists('validate_redirect_url')) {
        require_once __DIR__ . '/includes/security.php';
    }

    // Get current URI and validate it's safe for redirect
    $currentUri = $_SERVER['REQUEST_URI'] ?? $config['base_path'] . '/';
    $safeRedirect = validate_redirect_url($currentUri, $config['base_path'], $config['base_path'] . '/');

    // Check if session was expired
    if (isset($_SESSION['session_expired']) && $_SESSION['session_expired'] === true) {
        session_destroy();
        header('Location: ' . $config['base_path'] . '/login.php?timeout=1&redirect=' . urlencode($safeRedirect));
        exit;
    }

    if (!is_logged_in()) {
        header('Location: ' . $config['base_path'] . '/login.php?redirect=' . urlencode($safeRedirect));
        exit;
    }
}

/**
 * Perform login
 */
function login($username, $password, $config)
{
    try {
        $db = new PDO('sqlite:' . $config['db_path']);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("SELECT id, username, password_hash, display_name, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['authenticated'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['display_name'] = $user['display_name'];
            $_SESSION['role'] = $user['role'] ?? 'user';
            $_SESSION['last_activity'] = time();
            $_SESSION['login_time'] = time();
            return true;
        }
    } catch (PDOException $e) {
        // Log error but don't expose DB details
        error_log("Login error: " . $e->getMessage());
    }

    // Fallback to config auth if DB fails or user not found (optional, but good for transition)
    // For now, we strictly use DB auth as migration should have happened.

    return false;
}

/**
 * Check if current user is admin
 */
function is_admin()
{
    return is_logged_in() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Require admin authentication
 */
function require_admin($config)
{
    require_auth($config);

    if (!is_admin()) {
        header('HTTP/1.1 403 Forbidden');
        die('Access Denied: Admin privileges required.');
    }
}

/**
 * Get current user info
 */
function get_current_user_info($config)
{
    if (!is_logged_in() || !isset($_SESSION['user_id'])) {
        return null;
    }

    try {
        $db = new PDO('sqlite:' . $config['db_path']);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("SELECT id, username, display_name, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Update user display name
 */
function update_user_display_name($userId, $displayName, $config)
{
    try {
        $db = new PDO('sqlite:' . $config['db_path']);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("UPDATE users SET display_name = ?, updated_at = datetime('now') WHERE id = ?");
        $result = $stmt->execute([$displayName, $userId]);

        if ($result) {
            $_SESSION['display_name'] = $displayName;
        }

        return $result;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Update user password
 */
function update_user_password($userId, $currentPassword, $newPassword, $config)
{
    try {
        $db = new PDO('sqlite:' . $config['db_path']);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Verify current password first
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($currentPassword, $hash)) {
            return false;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = datetime('now') WHERE id = ?");
        return $updateStmt->execute([$newHash, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Perform logout
 */
function logout()
{
    $_SESSION = array();

    // Delete the session cookie
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
}
