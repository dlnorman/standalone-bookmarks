<?php
/**
 * Installation Page
 * 
 * Handles initial database setup and admin user creation.
 */

if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found. Please copy config-example.php to config.php first.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_setup.php';

$error = '';
$success = false;

// Check if already installed
try {
    if (file_exists($config['db_path'])) {
        $db = new PDO('sqlite:' . $config['db_path']);
        // Check if users table exists and has users
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        if ($result->fetch()) {
            $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($count > 0) {
                // Already installed, redirect to login
                header('Location: login.php');
                exit;
            }
        }
    }
} catch (Exception $e) {
    // DB might not exist yet, which is fine
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $display_name = trim($_POST['display_name'] ?? '');

    if (empty($username) || empty($password) || empty($display_name)) {
        $error = 'All fields are required.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } else {
        try {
            $setup = new DatabaseSetup($config['db_path']);
            $setup->runSetup();
            $setup->createAdminUser($username, $password, $display_name);

            $success = true;
        } catch (Exception $e) {
            $error = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - <?= htmlspecialchars($config['site_title']) ?></title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .install-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 2rem;
            background: var(--bg-card);
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .success-message {
            text-align: center;
            color: var(--text-success);
        }
    </style>
</head>

<body class="login-page">
    <div class="install-container">
        <h1>Installation</h1>

        <?php if ($success): ?>
            <div class="success-message">
                <h2>Success!</h2>
                <p>Administrator account created successfully.</p>
                <p><a href="login.php" class="btn btn-primary">Go to Login</a></p>
            </div>
        <?php else: ?>
            <p>Welcome! Please create your administrator account to get started.</p>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="display_name">Display Name</label>
                    <input type="text" id="display_name" name="display_name" required
                        value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required minlength="8">
                    <small>Minimum 8 characters</small>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <button type="submit" class="btn btn-primary">Install & Create Account</button>
            </form>
        <?php endif; ?>
    </div>
</body>

</html>