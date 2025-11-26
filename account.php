<?php
/**
 * Account Management Page
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/nav.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Require authentication
require_auth($config);

$user = get_current_user_info($config);
if (!$user) {
    // Should not happen if require_auth passed, but safety check
    header('Location: ' . $config['base_path'] . '/logout.php');
    exit;
}

$success_msg = '';
$error_msg = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_token();

    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update_profile') {
            $displayName = trim($_POST['display_name'] ?? '');

            if (empty($displayName)) {
                $error_msg = 'Display name cannot be empty.';
            } else {
                if (update_user_display_name($user['id'], $displayName, $config)) {
                    $success_msg = 'Profile updated successfully.';
                    $user['display_name'] = $displayName; // Update local variable
                } else {
                    $error_msg = 'Failed to update profile.';
                }
            }
        } elseif ($_POST['action'] === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $error_msg = 'All password fields are required.';
            } elseif ($newPassword !== $confirmPassword) {
                $error_msg = 'New passwords do not match.';
            } elseif (strlen($newPassword) < 8) {
                $error_msg = 'New password must be at least 8 characters long.';
            } else {
                if (update_user_password($user['id'], $currentPassword, $newPassword, $config)) {
                    $success_msg = 'Password changed successfully.';
                } else {
                    $error_msg = 'Incorrect current password.';
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account - <?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 0;
            line-height: 1.6;
            background: #f5f5f5;
            color: #333;
        }

        .page-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .card h2 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }

        .btn {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }

        .btn:hover {
            background: #2980b9;
        }

        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .user-info {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }

        .user-info-label {
            font-weight: 600;
            color: #7f8c8d;
        }
    </style>
</head>

<body>
    <?php render_nav($config, true, 'account'); ?>

    <div class="page-container">
        <h1>Account Management</h1>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Profile Information</h2>

            <div class="user-info">
                <div class="user-info-label">Username:</div>
                <div><?= htmlspecialchars($user['username']) ?></div>
            </div>

            <div class="user-info">
                <div class="user-info-label">Role:</div>
                <div>
                    <span
                        style="padding: 4px 8px; background: <?= ($user['role'] ?? 'user') === 'admin' ? '#e74c3c' : '#3498db' ?>; color: white; border-radius: 4px; font-size: 12px; font-weight: bold;">
                        <?= ucfirst(htmlspecialchars($user['role'] ?? 'user')) ?>
                    </span>
                </div>
            </div>

            <form method="post" action="">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="update_profile">

                <div class="form-group">
                    <label for="display_name">Display Name</label>
                    <input type="text" id="display_name" name="display_name"
                        value="<?= htmlspecialchars($user['display_name'] ?? '') ?>" required>
                </div>

                <button type="submit" class="btn">Update Profile</button>
            </form>
        </div>

        <div class="card">
            <h2>Change Password</h2>
            <form method="post" action="">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password (min 8 chars)</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                </div>

                <button type="submit" class="btn">Change Password</button>
            </form>
        </div>
    </div>
</body>

</html>