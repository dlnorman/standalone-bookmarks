<?php
/**
 * Diagnostic page for URL checker
 * Shows database stats to help debug issues
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Require authentication
if (!is_logged_in()) {
    die('Please log in first.');
}

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Get diagnostics
$diagnostics = [];

// Check bookmarks table
$diagnostics['total_bookmarks'] = $db->query("SELECT COUNT(*) as count FROM bookmarks")->fetch(PDO::FETCH_ASSOC)['count'];

// Check for broken_url column
$columns = $db->query("PRAGMA table_info(bookmarks)")->fetchAll(PDO::FETCH_ASSOC);
$diagnostics['has_broken_url_column'] = false;
$diagnostics['has_last_checked_column'] = false;
foreach ($columns as $column) {
    if ($column['name'] === 'broken_url') $diagnostics['has_broken_url_column'] = true;
    if ($column['name'] === 'last_checked') $diagnostics['has_last_checked_column'] = true;
}

// Check jobs table
$diagnostics['total_jobs'] = $db->query("SELECT COUNT(*) as count FROM jobs")->fetch(PDO::FETCH_ASSOC)['count'];
$diagnostics['check_url_jobs'] = $db->query("SELECT COUNT(*) as count FROM jobs WHERE job_type = 'check_url'")->fetch(PDO::FETCH_ASSOC)['count'];
$diagnostics['pending_check_url_jobs'] = $db->query("SELECT COUNT(*) as count FROM jobs WHERE job_type = 'check_url' AND status = 'pending'")->fetch(PDO::FETCH_ASSOC)['count'];
$diagnostics['processing_check_url_jobs'] = $db->query("SELECT COUNT(*) as count FROM jobs WHERE job_type = 'check_url' AND status = 'processing'")->fetch(PDO::FETCH_ASSOC)['count'];
$diagnostics['completed_check_url_jobs'] = $db->query("SELECT COUNT(*) as count FROM jobs WHERE job_type = 'check_url' AND status = 'completed'")->fetch(PDO::FETCH_ASSOC)['count'];
$diagnostics['failed_check_url_jobs'] = $db->query("SELECT COUNT(*) as count FROM jobs WHERE job_type = 'check_url' AND status = 'failed'")->fetch(PDO::FETCH_ASSOC)['count'];

// Sample bookmarks
$diagnostics['sample_bookmarks'] = $db->query("SELECT id, url, title FROM bookmarks LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Sample jobs
$diagnostics['sample_jobs'] = $db->query("SELECT id, bookmark_id, job_type, status, created_at FROM jobs WHERE job_type = 'check_url' ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <title>URL Checker Diagnostics</title>
    <style>
        body {
            font-family: monospace;
            padding: 20px;
            max-width: 1000px;
            margin: 0 auto;
            background: #f5f5f5;
        }
        h1 { color: #2c3e50; }
        h2 { color: #3498db; margin-top: 30px; }
        .stat {
            background: white;
            padding: 10px;
            margin: 5px 0;
            border-left: 3px solid #3498db;
        }
        .stat strong { color: #2c3e50; }
        .good { border-left-color: #27ae60; }
        .warn { border-left-color: #f39c12; }
        .bad { border-left-color: #e74c3c; }
        pre {
            background: white;
            padding: 15px;
            border: 1px solid #ddd;
            overflow: auto;
        }
        table {
            width: 100%;
            background: white;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background: #3498db;
            color: white;
        }
    </style>
</head>
<body>
    <h1>URL Checker Diagnostics</h1>
    <p><a href="check-bookmarks.php">← Back to Check Bookmarks</a></p>

    <h2>Database Status</h2>
    <div class="stat <?= $diagnostics['total_bookmarks'] > 0 ? 'good' : 'bad' ?>">
        <strong>Total Bookmarks:</strong> <?= $diagnostics['total_bookmarks'] ?>
    </div>
    <div class="stat <?= $diagnostics['has_broken_url_column'] ? 'good' : 'warn' ?>">
        <strong>Has broken_url column:</strong> <?= $diagnostics['has_broken_url_column'] ? 'Yes' : 'No (will be created on first check)' ?>
    </div>
    <div class="stat <?= $diagnostics['has_last_checked_column'] ? 'good' : 'warn' ?>">
        <strong>Has last_checked column:</strong> <?= $diagnostics['has_last_checked_column'] ? 'Yes' : 'No (will be created on first check)' ?>
    </div>

    <h2>Jobs Status</h2>
    <div class="stat">
        <strong>Total Jobs:</strong> <?= $diagnostics['total_jobs'] ?>
    </div>
    <div class="stat">
        <strong>Check URL Jobs:</strong> <?= $diagnostics['check_url_jobs'] ?>
    </div>
    <div class="stat">
        <strong>Pending:</strong> <?= $diagnostics['pending_check_url_jobs'] ?>
    </div>
    <div class="stat">
        <strong>Processing:</strong> <?= $diagnostics['processing_check_url_jobs'] ?>
    </div>
    <div class="stat">
        <strong>Completed:</strong> <?= $diagnostics['completed_check_url_jobs'] ?>
    </div>
    <div class="stat">
        <strong>Failed:</strong> <?= $diagnostics['failed_check_url_jobs'] ?>
    </div>

    <?php if (!empty($diagnostics['sample_bookmarks'])): ?>
    <h2>Sample Bookmarks (First 5)</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>Title</th>
            <th>URL</th>
        </tr>
        <?php foreach ($diagnostics['sample_bookmarks'] as $bm): ?>
        <tr>
            <td><?= $bm['id'] ?></td>
            <td><?= htmlspecialchars($bm['title']) ?></td>
            <td><?= htmlspecialchars(substr($bm['url'], 0, 60)) ?><?= strlen($bm['url']) > 60 ? '...' : '' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if (!empty($diagnostics['sample_jobs'])): ?>
    <h2>Sample Check URL Jobs (Last 5)</h2>
    <table>
        <tr>
            <th>Job ID</th>
            <th>Bookmark ID</th>
            <th>Status</th>
            <th>Created</th>
        </tr>
        <?php foreach ($diagnostics['sample_jobs'] as $job): ?>
        <tr>
            <td><?= $job['id'] ?></td>
            <td><?= $job['bookmark_id'] ?></td>
            <td><?= $job['status'] ?></td>
            <td><?= $job['created_at'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <h2>Recommendations</h2>
    <?php if ($diagnostics['total_bookmarks'] === 0): ?>
        <div class="stat bad">
            <strong>Issue:</strong> No bookmarks found. Please add some bookmarks first.
        </div>
    <?php elseif ($diagnostics['pending_check_url_jobs'] > 0 || $diagnostics['processing_check_url_jobs'] > 0): ?>
        <div class="stat warn">
            <strong>Info:</strong> You have <?= $diagnostics['pending_check_url_jobs'] + $diagnostics['processing_check_url_jobs'] ?> jobs waiting to be processed.
            Make sure your cron job is running: <code>php <?= __DIR__ ?>/process_jobs.php</code>
        </div>
    <?php else: ?>
        <div class="stat good">
            <strong>Ready:</strong> You can queue URL checks. Click "Start URL Check" on the main page.
        </div>
    <?php endif; ?>

    <h2>Manual Job Processing</h2>
    <p>To manually process jobs right now (useful for testing), run this command:</p>
    <pre>php <?= __DIR__ ?>/process_jobs.php</pre>
    <p>Or visit: <a href="<?= $config['base_path'] ?>/run-jobs-manual.php" target="_blank">Manual Job Runner</a> (if created)</p>

    <h2>Full Diagnostics Data (JSON)</h2>
    <pre><?= json_encode($diagnostics, JSON_PRETTY_PRINT) ?></pre>
</body>
</html>
