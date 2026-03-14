<?php
/**
 * Utilities - Admin hub for tools and maintenance
 */

if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/nav.php';

if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

require_admin($config);

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Gather database stats
$stats = [];
$stats['total_bookmarks']  = $db->query("SELECT COUNT(*) FROM bookmarks")->fetchColumn();
$stats['public_bookmarks'] = $db->query("SELECT COUNT(*) FROM bookmarks WHERE private = 0")->fetchColumn();
$stats['private_bookmarks']= $db->query("SELECT COUNT(*) FROM bookmarks WHERE private = 1")->fetchColumn();
$stats['broken_links']     = $db->query("SELECT COUNT(*) FROM bookmarks WHERE broken_url = 1")->fetchColumn();

// Count distinct tags
$tagRows = $db->query("SELECT tags FROM bookmarks WHERE tags IS NOT NULL AND tags != ''")->fetchAll(PDO::FETCH_COLUMN);
$allTags = [];
foreach ($tagRows as $tagString) {
    foreach (array_map('trim', explode(',', $tagString)) as $t) {
        if ($t !== '') $allTags[strtolower($t)] = true;
    }
}
$stats['distinct_tags'] = count($allTags);

$stats['total_users'] = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Job stats
$jobStats = $db->query("SELECT status, COUNT(*) as cnt FROM jobs GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
$stats['jobs'] = [];
foreach ($jobStats as $row) {
    $stats['jobs'][$row['status']] = $row['cnt'];
}
$stats['total_jobs'] = array_sum($stats['jobs']);

// DB file size
$stats['db_size'] = file_exists($config['db_path']) ? filesize($config['db_path']) : 0;

function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

$isLoggedIn = is_logged_in();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utilities - <?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .utilities-container {
            max-width: 960px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }

        .utilities-header {
            margin-bottom: 36px;
        }

        .utilities-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 6px;
        }

        .utilities-header p {
            color: var(--text-secondary);
            margin: 0;
            font-size: 0.95rem;
        }

        /* ── Section layout ── */
        .util-section {
            margin-bottom: 40px;
        }

        .util-section-title {
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-tertiary);
            margin: 0 0 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
        }

        /* ── Tool cards ── */
        .util-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 14px;
        }

        .util-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px 22px;
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            transition: box-shadow 0.15s, border-color 0.15s, transform 0.1s;
            box-shadow: var(--shadow-sm);
        }

        .util-card:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }

        .util-card-icon {
            font-size: 1.5rem;
            line-height: 1;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .util-card-body {
            min-width: 0;
        }

        .util-card-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0 0 4px;
        }

        .util-card-desc {
            font-size: 0.82rem;
            color: var(--text-secondary);
            margin: 0;
            line-height: 1.4;
        }

        /* ── DB stats grid ── */
        .db-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .db-stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 14px 16px;
            box-shadow: var(--shadow-sm);
        }

        .db-stat-card .stat-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1;
            margin-bottom: 4px;
        }

        .db-stat-card .stat-label {
            font-size: 0.75rem;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 500;
        }

        .db-stat-card.accent-red .stat-value  { color: var(--accent-red); }
        .db-stat-card.accent-blue .stat-value { color: var(--accent-blue); }
        .db-stat-card.accent-green .stat-value { color: var(--accent-green); }

        .db-meta {
            font-size: 0.82rem;
            color: var(--text-secondary);
        }

        .db-meta span + span::before {
            content: ' · ';
            color: var(--text-tertiary);
        }

        /* ── Jobs sub-table ── */
        .jobs-breakdown {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
        }

        .job-pill {
            font-size: 0.78rem;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 500;
        }

        .job-pill.pending    { background: rgba(255, 149, 0, 0.12); color: var(--accent-amber); }
        .job-pill.processing { background: rgba(0, 122, 255, 0.12); color: var(--accent-blue); }
        .job-pill.completed  { background: rgba(52, 199, 89, 0.12);  color: var(--accent-green); }
        .job-pill.failed     { background: rgba(255, 59, 48, 0.12);  color: var(--accent-red); }

        /* ── CLI section ── */
        .cli-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .cli-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 14px 18px;
            box-shadow: var(--shadow-sm);
        }

        .cli-item-header {
            display: flex;
            align-items: baseline;
            gap: 10px;
            margin-bottom: 6px;
        }

        .cli-item-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .cli-item-desc {
            font-size: 0.82rem;
            color: var(--text-secondary);
        }

        .cli-code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.78rem;
            background: var(--code-bg);
            color: var(--text-primary);
            padding: 6px 10px;
            border-radius: 6px;
            display: inline-block;
            margin-top: 2px;
        }
    </style>
</head>

<body>
    <?php render_nav($config, $isLoggedIn, 'utilities'); ?>

    <div class="utilities-container">
        <div class="utilities-header">
            <h1>Utilities</h1>
            <p>Admin tools for managing, importing, exporting, and maintaining your bookmarks.</p>
        </div>

        <!-- ── Import & Export ── -->
        <section class="util-section">
            <h2 class="util-section-title">Import &amp; Export</h2>
            <div class="util-grid">
                <a href="import.php" class="util-card">
                    <span class="util-card-icon">📥</span>
                    <div class="util-card-body">
                        <div class="util-card-title">Import Bookmarks</div>
                        <p class="util-card-desc">Import from Pinboard, Delicious, or any browser using the Netscape Bookmark File format.</p>
                    </div>
                </a>
                <a href="export.php" class="util-card">
                    <span class="util-card-icon">📤</span>
                    <div class="util-card-body">
                        <div class="util-card-title">Export Bookmarks</div>
                        <p class="util-card-desc">Download all bookmarks as a standard HTML file compatible with most bookmark managers.</p>
                    </div>
                </a>
            </div>
        </section>

        <!-- ── Browser Integration ── -->
        <section class="util-section">
            <h2 class="util-section-title">Browser Integration</h2>
            <div class="util-grid">
                <a href="bookmarklet-setup.php" class="util-card">
                    <span class="util-card-icon">🔖</span>
                    <div class="util-card-body">
                        <div class="util-card-title">Bookmarklet</div>
                        <p class="util-card-desc">Install the bookmarklet to quickly save any page from your browser with one click.</p>
                    </div>
                </a>
                <a href="rss.php" class="util-card" target="_blank">
                    <span class="util-card-icon">📡</span>
                    <div class="util-card-body">
                        <div class="util-card-title">RSS Feed</div>
                        <p class="util-card-desc">Subscribe to your public bookmarks as an RSS feed in any feed reader.</p>
                    </div>
                </a>
            </div>
        </section>

        <!-- ── URL Health ── -->
        <section class="util-section">
            <h2 class="util-section-title">URL Health</h2>
            <div class="util-grid">
                <a href="check-bookmarks.php" class="util-card">
                    <span class="util-card-icon">🔍</span>
                    <div class="util-card-body">
                        <div class="util-card-title">Check Bookmarks</div>
                        <p class="util-card-desc">Queue URL checks to find and flag broken links across your entire collection.</p>
                    </div>
                </a>
                <a href="check-diagnostics.php" class="util-card">
                    <span class="util-card-icon">🩺</span>
                    <div class="util-card-body">
                        <div class="util-card-title">Diagnostics</div>
                        <p class="util-card-desc">Inspect job queue status, database health, and troubleshoot URL checking issues.</p>
                    </div>
                </a>
            </div>
        </section>

        <!-- ── Database Stats ── -->
        <section class="util-section">
            <h2 class="util-section-title">Database</h2>

            <div class="db-stats-grid">
                <div class="db-stat-card accent-blue">
                    <div class="stat-value"><?= number_format($stats['total_bookmarks']) ?></div>
                    <div class="stat-label">Bookmarks</div>
                </div>
                <div class="db-stat-card">
                    <div class="stat-value"><?= number_format($stats['public_bookmarks']) ?></div>
                    <div class="stat-label">Public</div>
                </div>
                <div class="db-stat-card">
                    <div class="stat-value"><?= number_format($stats['private_bookmarks']) ?></div>
                    <div class="stat-label">Private</div>
                </div>
                <div class="db-stat-card <?= $stats['broken_links'] > 0 ? 'accent-red' : '' ?>">
                    <div class="stat-value"><?= number_format($stats['broken_links']) ?></div>
                    <div class="stat-label">Broken Links</div>
                </div>
                <div class="db-stat-card accent-green">
                    <div class="stat-value"><?= number_format($stats['distinct_tags']) ?></div>
                    <div class="stat-label">Distinct Tags</div>
                </div>
                <div class="db-stat-card">
                    <div class="stat-value"><?= number_format($stats['total_users']) ?></div>
                    <div class="stat-label">Users</div>
                </div>
            </div>

            <div class="db-meta">
                <span>Database file: <code><?= htmlspecialchars(basename($config['db_path'])) ?></code></span>
                <span><?= format_bytes($stats['db_size']) ?></span>
            </div>

            <?php if ($stats['total_jobs'] > 0): ?>
                <div class="jobs-breakdown">
                    <span style="font-size:0.82rem; color:var(--text-secondary); align-self:center;">Jobs:</span>
                    <?php foreach ($stats['jobs'] as $status => $count): ?>
                        <span class="job-pill <?= htmlspecialchars($status) ?>">
                            <?= htmlspecialchars($status) ?>: <?= number_format($count) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ── CLI Tools ── -->
        <section class="util-section">
            <h2 class="util-section-title">Command-Line Tools</h2>
            <div class="cli-list">
                <div class="cli-item">
                    <div class="cli-item-header">
                        <span class="cli-item-title">process_jobs.php</span>
                        <span class="cli-item-desc">Background job processor (screenshots, URL checks). Run via cron every 5 minutes.</span>
                    </div>
                    <code class="cli-code">php <?= htmlspecialchars(__DIR__) ?>/process_jobs.php</code>
                </div>
                <div class="cli-item">
                    <div class="cli-item-header">
                        <span class="cli-item-title">backup.php</span>
                        <span class="cli-item-desc">Database backup utility. Supports <code>--auto</code>, <code>--database-only</code>, and <code>--full</code> modes.</span>
                    </div>
                    <code class="cli-code">php <?= htmlspecialchars(__DIR__) ?>/backup.php --database-only --keep=7</code>
                </div>
                <div class="cli-item">
                    <div class="cli-item-header">
                        <span class="cli-item-title">init_db.php</span>
                        <span class="cli-item-desc">Initialize or update the database schema. Safe to re-run on existing databases.</span>
                    </div>
                    <code class="cli-code">php <?= htmlspecialchars(__DIR__) ?>/init_db.php</code>
                </div>
                <div class="cli-item">
                    <div class="cli-item-header">
                        <span class="cli-item-title">add-indexes.php</span>
                        <span class="cli-item-desc">Add performance indexes to an existing database installation.</span>
                    </div>
                    <code class="cli-code">php <?= htmlspecialchars(__DIR__) ?>/add-indexes.php</code>
                </div>
            </div>
        </section>
    </div>

    <?php render_nav_scripts(); ?>
</body>

</html>
