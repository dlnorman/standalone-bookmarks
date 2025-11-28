<?php
/**
 * Admin User Management Page
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

// Require admin authentication
require_admin($config);

$success_msg = '';
$error_msg = '';

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_token();

    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_user') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'user';

            if (empty($username) || empty($password)) {
                $error_msg = 'Username and password are required.';
            } else {
                try {
                    // Check if username exists
                    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetchColumn() > 0) {
                        $error_msg = 'Username already exists.';
                    } else {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $now = date('Y-m-d H:i:s');

                        $stmt = $db->prepare("
                            INSERT INTO users (username, password_hash, display_name, role, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$username, $passwordHash, $username, $role, $now, $now]);
                        $success_msg = 'User added successfully.';
                    }
                } catch (PDOException $e) {
                    $error_msg = 'Error adding user: ' . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'delete_user') {
            $userId = $_POST['user_id'] ?? 0;

            // Prevent deleting self
            if ($userId == $_SESSION['user_id']) {
                $error_msg = 'You cannot delete your own account.';
            } else {
                try {
                    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $success_msg = 'User deleted successfully.';
                } catch (PDOException $e) {
                    $error_msg = 'Error deleting user: ' . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'reset_password') {
            $userId = $_POST['user_id'] ?? 0;
            $newPassword = $_POST['new_password'] ?? '';

            if (empty($newPassword)) {
                $error_msg = 'New password is required.';
            } else {
                try {
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = datetime('now') WHERE id = ?");
                    $stmt->execute([$passwordHash, $userId]);
                    $success_msg = 'Password reset successfully.';
                } catch (PDOException $e) {
                    $error_msg = 'Error resetting password: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch all users
$users = $db->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - <?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">
</head>

<body>
    <?php render_nav($config, true, 'admin'); ?>

    <div class="page-container">
        <h1>User Management</h1>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Add New User</h2>
            <form method="post" action="">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add_user">

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="user">Standard User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <button type="submit" class="btn">Add User</button>
            </form>
        </div>

        <div class="card">
            <h2>Existing Users</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Display Name</th>
                        <th>Role</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['display_name']) ?></td>
                            <td>
                                <span class="badge badge-<?= htmlspecialchars($u['role']) ?>">
                                    <?= ucfirst(htmlspecialchars($u['role'])) ?>
                                </span>
                            </td>
                            <td><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
                            <td class="actions">
                                <button class="btn btn-warning"
                                    onclick="openResetModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">Reset
                                    Pwd</button>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                    <form method="post" action="" style="display:inline;"
                                        onsubmit="return confirm('Are you sure you want to delete this user?');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php render_nav_scripts(); ?>

    <!-- Reset Password Modal -->
    <div id="resetModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeResetModal()">&times;</span>
            <h3>Reset Password for <span id="resetUsername"></span></h3>
            <form method="post" action="">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password_reset" name="new_password" required>
                </div>

                <button type="submit" class="btn btn-warning">Reset Password</button>
            </form>
        </div>
    </div>

    <script>
        function openResetModal(userId, username) {
            document.getElementById('resetUserId').value = userId;
            document.getElementById('resetUsername').textContent = username;
            document.getElementById('resetModal').style.display = 'block';
        }

        function closeResetModal() {
            document.getElementById('resetModal').style.display = 'none';
        }

        window.onclick = function (event) {
            if (event.target == document.getElementById('resetModal')) {
                closeResetModal();
            }
        }
    </script>
</body>

</html>