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
    <link rel="stylesheet" href="css/main.css">
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