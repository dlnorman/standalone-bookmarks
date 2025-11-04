<?php
/**
 * Check Bookmarks - URL validation and broken link detection
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found. Please copy config-example.php to config.php and adjust settings.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/csrf.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Require authentication
if (!is_logged_in()) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$csrfToken = csrf_get_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Bookmarks - <?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f5f5;
            color: #333;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .header p {
            color: #7f8c8d;
            font-size: 14px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .stat-card .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #7f8c8d;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1;
        }

        .stat-card.broken .value {
            color: #e74c3c;
        }

        .stat-card.clickable {
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card.clickable:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        .stat-card .hint {
            font-size: 10px;
            color: #95a5a6;
            margin-top: 5px;
        }

        .stat-card.completed .value {
            color: #27ae60;
        }

        .actions {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn:hover {
            background: #5568d3;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .btn:disabled {
            background: #95a5a6;
            cursor: not-allowed;
            transform: none;
        }

        .progress {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: none;
        }

        .progress.active {
            display: block;
        }

        .progress-bar {
            background: #ecf0f1;
            height: 30px;
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 10px;
        }

        .progress-fill {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            height: 100%;
            width: 0%;
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
        }

        .progress-text {
            text-align: center;
            color: #7f8c8d;
            font-size: 14px;
        }

        .results {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .results h2 {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 15px;
        }

        .result-item {
            padding: 15px;
            border-bottom: 1px solid #ecf0f1;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .result-item:last-child {
            border-bottom: none;
        }

        .result-status {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .result-status.ok {
            background: #27ae60;
        }

        .result-status.broken {
            background: #e74c3c;
        }

        .result-status.pending {
            background: #f39c12;
        }

        .result-info {
            flex: 1;
            min-width: 0;
        }

        .result-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .result-url {
            font-size: 12px;
            color: #7f8c8d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .result-message {
            font-size: 12px;
            color: #95a5a6;
        }

        .result-time {
            font-size: 11px;
            color: #bdc3c7;
            flex-shrink: 0;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #7f8c8d;
        }

        @media (max-width: 768px) {
            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .result-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <?php
    $isLoggedIn = is_logged_in();
    render_nav($config, $isLoggedIn, 'check-bookmarks');
    ?>

    <div class="container">
        <div class="header">
            <h1>Check Bookmarks</h1>
            <p>Validate URLs and detect broken links across your bookmark collection</p>
            <p style="margin-top: 10px;"><a href="check-diagnostics.php" style="color: #7f8c8d; font-size: 13px;">🔍 View Diagnostics</a> (helpful if you encounter issues)</p>
        </div>

        <div class="stats">
            <div class="stat-card">
                <div class="label">Total Jobs</div>
                <div class="value" id="stat-total">-</div>
            </div>
            <div class="stat-card">
                <div class="label">Pending</div>
                <div class="value" id="stat-pending">-</div>
            </div>
            <div class="stat-card completed">
                <div class="label">Completed</div>
                <div class="value" id="stat-completed">-</div>
            </div>
            <div class="stat-card broken clickable" id="broken-card" onclick="viewBrokenLinks()">
                <div class="label">Broken Links</div>
                <div class="value" id="stat-broken">-</div>
                <div class="hint">Click to view</div>
            </div>
        </div>

        <div class="actions">
            <button id="start-check" class="btn">Start URL Check</button>
            <button id="refresh-status" class="btn" style="margin-left: 10px; background: #95a5a6;">Refresh Status</button>
        </div>

        <div class="progress" id="progress">
            <div class="progress-bar">
                <div class="progress-fill" id="progress-fill"></div>
            </div>
            <div class="progress-text" id="progress-text">Checking URLs...</div>
        </div>

        <div class="results">
            <h2>Recent Check Results</h2>
            <div id="results-list">
                <div class="empty-state">Click "Start URL Check" to begin validating your bookmarks</div>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = <?= json_encode($csrfToken) ?>;
        const basePath = <?= json_encode($config['base_path']) ?>;
        let pollInterval = null;

        // Load initial status
        loadStatus();

        document.getElementById('start-check').addEventListener('click', startCheck);
        document.getElementById('refresh-status').addEventListener('click', loadStatus);

        function viewBrokenLinks() {
            window.location.href = basePath + '/?broken=1';
        }

        function startCheck() {
            const btn = document.getElementById('start-check');
            btn.disabled = true;
            btn.textContent = 'Queueing checks...';

            fetch('api.php?action=queue_url_checks', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(res => res.json())
            .then(data => {
                console.log('Queue response:', data);
                if (data.success) {
                    if (data.queued > 0) {
                        alert(data.message + '\n\nThe background job processor (process_jobs.php via cron) will check these URLs. This page will auto-refresh to show progress.');
                        document.getElementById('progress').classList.add('active');
                        startPolling();
                        loadStatus();
                    } else if (data.total === 0) {
                        alert('No bookmarks found in the database. Please add some bookmarks first.');
                        btn.disabled = false;
                        btn.textContent = 'Start URL Check';
                    } else {
                        alert(data.message + '\n\nIf you just ran a check, wait for it to complete before running again.');
                        btn.disabled = false;
                        btn.textContent = 'Start URL Check';
                        loadStatus(); // Still load status to show existing jobs
                    }
                } else {
                    alert('Error: ' + (data.error || 'Failed to queue URL checks'));
                    btn.disabled = false;
                    btn.textContent = 'Start URL Check';
                }
            })
            .catch(err => {
                console.error('Queue error:', err);
                alert('Error: ' + err.message);
                btn.disabled = false;
                btn.textContent = 'Start URL Check';
            });
        }

        function loadStatus() {
            fetch('api.php?action=check_status')
            .then(res => res.json())
            .then(data => {
                updateStats(data.stats, data.broken_count);
                updateResults(data.recent_checks);

                // Auto-enable button if no pending jobs
                if (data.stats.pending === 0 && data.stats.processing === 0) {
                    const btn = document.getElementById('start-check');
                    btn.disabled = false;
                    btn.textContent = 'Start URL Check';
                    stopPolling();
                }
            })
            .catch(err => {
                console.error('Failed to load status:', err);
            });
        }

        function updateStats(stats, brokenCount) {
            document.getElementById('stat-total').textContent = stats.total || 0;
            document.getElementById('stat-pending').textContent = stats.pending || 0;
            document.getElementById('stat-completed').textContent = stats.completed || 0;
            document.getElementById('stat-broken').textContent = brokenCount || 0;

            // Update progress bar
            if (stats.total > 0) {
                const progress = ((stats.completed + stats.failed) / stats.total) * 100;
                document.getElementById('progress-fill').style.width = progress + '%';
                document.getElementById('progress-fill').textContent = Math.round(progress) + '%';

                if (stats.pending > 0 || stats.processing > 0) {
                    document.getElementById('progress').classList.add('active');
                    document.getElementById('progress-text').textContent =
                        `Checking URLs... ${stats.completed + stats.failed} of ${stats.total} completed`;
                } else if (stats.total > 0) {
                    document.getElementById('progress-text').textContent = 'All checks completed!';
                }
            }
        }

        function updateResults(checks) {
            const list = document.getElementById('results-list');

            if (checks.length === 0) {
                list.innerHTML = '<div class="empty-state">No check results yet</div>';
                return;
            }

            list.innerHTML = checks.map(check => {
                const statusClass = check.broken_url == 1 ? 'broken' :
                                   check.status === 'completed' ? 'ok' : 'pending';
                const timeAgo = formatTimeAgo(check.updated_at);

                return `
                    <div class="result-item">
                        <div class="result-status ${statusClass}"></div>
                        <div class="result-info">
                            <div class="result-title">${escapeHtml(check.title)}</div>
                            <div class="result-url">${escapeHtml(check.url)}</div>
                            <div class="result-message">${escapeHtml(check.result || check.status)}</div>
                        </div>
                        <div class="result-time">${timeAgo}</div>
                    </div>
                `;
            }).join('');
        }

        function startPolling() {
            if (pollInterval) return;
            pollInterval = setInterval(loadStatus, 5000); // Poll every 5 seconds
        }

        function stopPolling() {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }

        function formatTimeAgo(dateStr) {
            const date = new Date(dateStr);
            const now = new Date();
            const seconds = Math.floor((now - date) / 1000);

            if (seconds < 60) return 'just now';
            if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
            if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
            return Math.floor(seconds / 86400) + 'd ago';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-poll if there are pending jobs
        if (document.getElementById('stat-pending').textContent !== '-' &&
            parseInt(document.getElementById('stat-pending').textContent) > 0) {
            startPolling();
        }
    </script>
</body>
</html>
