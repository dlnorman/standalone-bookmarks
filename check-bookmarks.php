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
    <link rel="stylesheet" href="css/main.css">
</head>

<body>
    <?php
    $isLoggedIn = is_logged_in();
    render_nav($config, $isLoggedIn, 'check-bookmarks');
    ?>

    <div class="check-bookmarks-container">
        <div class="check-bookmarks-header">
            <h1>Check Bookmarks</h1>
            <p>Validate URLs and detect broken links across your bookmark collection</p>
            <p style="margin-top: 10px;"><a href="check-diagnostics.php" style="color: #7f8c8d; font-size: 13px;">🔍
                    View Diagnostics</a> (helpful if you encounter issues)</p>
        </div>

        <div class="check-stats">
            <div class="check-stat-card">
                <div class="label">Total Jobs</div>
                <div class="value" id="stat-total">-</div>
            </div>
            <div class="check-stat-card">
                <div class="label">Pending</div>
                <div class="value" id="stat-pending">-</div>
            </div>
            <div class="check-stat-card completed">
                <div class="label">Completed</div>
                <div class="value" id="stat-completed">-</div>
            </div>
            <div class="check-stat-card broken clickable" id="broken-card" onclick="viewBrokenLinks()">
                <div class="label">Broken Links</div>
                <div class="value" id="stat-broken">-</div>
                <div class="hint">Click to view</div>
            </div>
        </div>

        <div class="check-actions">
            <button id="start-check" class="btn">Start URL Check</button>
            <button id="refresh-status" class="btn" style="margin-left: 10px; background: #95a5a6;">Refresh
                Status</button>
        </div>

        <div class="check-progress" id="progress">
            <div class="progress-bar">
                <div class="progress-fill" id="progress-fill"></div>
            </div>
            <div class="progress-text" id="progress-text">Checking URLs...</div>
        </div>

        <div class="check-results">
            <h2>Recent Check Results</h2>
            <div id="results-list">
                <div class="empty-state">Click "Start URL Check" to begin validating your bookmarks</div>
            </div>
        </div>
    </div>

    <?php render_nav_scripts(); ?>

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