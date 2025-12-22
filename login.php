<?php
/**
 * Login page
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found. Please copy config-example.php to config.php and adjust settings.');
}

$config = require __DIR__ . '/config.php';
define('FORCE_SESSION_START', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/security.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// If already logged in, redirect to index
if (is_logged_in()) {
    header('Location: ' . $config['base_path'] . '/');
    exit;
}

// Check if installation is needed (no users in DB)
try {
    if (!file_exists($config['db_path'])) {
        header('Location: install.php');
        exit;
    }
    $db = new PDO('sqlite:' . $config['db_path']);
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
    if (!$result->fetch()) {
        header('Location: install.php');
        exit;
    }
    $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count == 0) {
        header('Location: install.php');
        exit;
    }
} catch (Exception $e) {
    // If DB error, assume install needed
    header('Location: install.php');
    exit;
}

$error = '';
$redirectParam = $_GET['redirect'] ?? $config['base_path'] . '/';
$redirect = validate_redirect_url($redirectParam, $config['base_path'], $config['base_path'] . '/');

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    csrf_require_valid_token();

    // Get client IP for rate limiting
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Check rate limiting
    if (is_rate_limited($clientIp, 5, 300)) {
        $lockoutTime = get_lockout_time($clientIp);
        $minutes = ceil($lockoutTime / 60);
        $error = "Too many failed login attempts. Please try again in {$minutes} minute(s).";
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (login($username, $password, $config)) {
            // Successful login - reset rate limit
            reset_rate_limit($clientIp);
            header('Location: ' . $redirect);
            exit;
        } else {
            // Failed login - record attempt
            record_failed_login($clientIp);
            $error = 'Invalid username or password';
        }
    }
}

$timeout_msg = isset($_GET['timeout']) ? 'Your session has expired. Please login again.' : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($config['site_title']) ?></title>
    <link rel="stylesheet" href="css/main.css">
</head>

<body class="login-page">
    <div class="login-container">
        <h1><?= htmlspecialchars($config['site_title']) ?></h1>

        <?php if ($timeout_msg): ?>
            <div class="info"><?= htmlspecialchars($timeout_msg) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <?php csrf_field(); ?>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Login</button>
        </form>
    </div>
</body>

</html>