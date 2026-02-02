<?php
/**
 * Archive view - Browse bookmarks by date
 * Features: Date range picker, timeline view, export functionality
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found. Please copy config-example.php to config.php and adjust settings.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/markdown.php';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/tags.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Check if user is authenticated (but don't force login for public view)
$isLoggedIn = is_logged_in();

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed. Run init_db.php first.');
}

// Get parameters
$view = $_GET['view'] ?? 'month'; // month, week, day, custom
$date = $_GET['date'] ?? date('Y-m-d');
$startDate = $_GET['start'] ?? '';
$endDate = $_GET['end'] ?? '';
$groupBy = $_GET['group'] ?? 'day'; // day, week, month
$export = $_GET['export'] ?? ''; // html, markdown

// Calculate date range based on view
if (!empty($startDate) && !empty($endDate)) {
    // Custom range
    $rangeStart = $startDate . ' 00:00:00';
    $rangeEnd = $endDate . ' 23:59:59';
    $viewTitle = date('M j, Y', strtotime($startDate)) . ' - ' . date('M j, Y', strtotime($endDate));
} else {
    switch ($view) {
        case 'day':
            $rangeStart = $date . ' 00:00:00';
            $rangeEnd = $date . ' 23:59:59';
            $viewTitle = date('l, F j, Y', strtotime($date));
            break;
        case 'week':
            $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($date)));
            $weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
            $rangeStart = $weekStart . ' 00:00:00';
            $rangeEnd = $weekEnd . ' 23:59:59';
            $viewTitle = 'Week of ' . date('M j', strtotime($weekStart)) . ' - ' . date('M j, Y', strtotime($weekEnd));
            break;
        case 'month':
        default:
            $monthStart = date('Y-m-01', strtotime($date));
            $monthEnd = date('Y-m-t', strtotime($date));
            $rangeStart = $monthStart . ' 00:00:00';
            $rangeEnd = $monthEnd . ' 23:59:59';
            $viewTitle = date('F Y', strtotime($date));
            break;
    }
}

// Fetch bookmarks in date range
if ($isLoggedIn) {
    $stmt = $db->prepare("
        SELECT * FROM bookmarks
        WHERE created_at >= ? AND created_at <= ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$rangeStart, $rangeEnd]);
} else {
    $stmt = $db->prepare("
        SELECT * FROM bookmarks
        WHERE created_at >= ? AND created_at <= ? AND private = 0
        ORDER BY created_at DESC
    ");
    $stmt->execute([$rangeStart, $rangeEnd]);
}

$bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group bookmarks by date
$groupedBookmarks = [];
foreach ($bookmarks as $bookmark) {
    $createdAt = strtotime($bookmark['created_at']);

    switch ($groupBy) {
        case 'week':
            $groupKey = date('Y-W', $createdAt); // Year-Week
            $groupLabel = 'Week of ' . date('M j', strtotime('monday this week', $createdAt)) . ' - ' . date('M j, Y', strtotime('sunday this week', $createdAt));
            break;
        case 'month':
            $groupKey = date('Y-m', $createdAt);
            $groupLabel = date('F Y', $createdAt);
            break;
        case 'day':
        default:
            $groupKey = date('Y-m-d', $createdAt);
            $groupLabel = date('l, F j, Y', $createdAt);
            break;
    }

    if (!isset($groupedBookmarks[$groupKey])) {
        $groupedBookmarks[$groupKey] = [
            'label' => $groupLabel,
            'date' => $groupKey,
            'bookmarks' => []
        ];
    }

    $groupedBookmarks[$groupKey]['bookmarks'][] = $bookmark;
}

// Get activity stats
if ($isLoggedIn) {
    $statsStmt = $db->query("
        SELECT
            DATE(created_at) as date,
            COUNT(*) as count
        FROM bookmarks
        GROUP BY DATE(created_at)
        ORDER BY date DESC
        LIMIT 90
    ");
} else {
    $statsStmt = $db->query("
        SELECT
            DATE(created_at) as date,
            COUNT(*) as count
        FROM bookmarks
        WHERE private = 0
        GROUP BY DATE(created_at)
        ORDER BY date DESC
        LIMIT 90
    ");
}
$activityStats = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle export
if (!empty($export) && !empty($bookmarks)) {
    if ($export === 'markdown') {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="bookmarks-' . date('Y-m-d') . '.md"');

        echo "# Bookmarks: $viewTitle\n\n";
        echo "Generated on " . date('F j, Y') . "\n\n";
        echo "---\n\n";

        foreach ($groupedBookmarks as $group) {
            echo "## " . $group['label'] . "\n\n";

            foreach ($group['bookmarks'] as $bookmark) {
                echo "### [" . $bookmark['title'] . "](" . $bookmark['url'] . ")\n\n";

                if (!empty($bookmark['description'])) {
                    echo $bookmark['description'] . "\n\n";
                }

                if (!empty($bookmark['screenshot'])) {
                    $screenshotPath = ltrim($bookmark['screenshot'], '/');
                    $screenshotUrl = rtrim($config['site_url'], '/') . '/' . $screenshotPath;
                    echo "![Screenshot](" . $screenshotUrl . ")\n\n";
                }

                if (!empty($bookmark['tags'])) {
                    echo "**Tags:** " . $bookmark['tags'] . "\n\n";
                }

                echo "*Added: " . date($config['date_format'], strtotime($bookmark['created_at'])) . "*\n\n";
                echo "---\n\n";
            }
        }

        exit;
    } elseif ($export === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="bookmarks-' . date('Y-m-d') . '.html"');

        echo "<!DOCTYPE html>\n";
        echo "<html lang=\"en\">\n<head>\n";
        echo "<meta charset=\"UTF-8\">\n";
        echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
        echo "<title>Bookmarks: $viewTitle</title>\n";
        echo "<style>\n";
        echo "body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; line-height: 1.6; color: #333; }\n";
        echo "h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }\n";
        echo "h2 { color: #34495e; margin-top: 40px; border-bottom: 2px solid #95a5a6; padding-bottom: 5px; }\n";
        echo "h3 { margin-top: 30px; }\n";
        echo "h3 a { color: #3498db; text-decoration: none; }\n";
        echo "h3 a:hover { text-decoration: underline; }\n";
        echo ".url { color: #7f8c8d; font-size: 14px; word-break: break-all; }\n";
        echo ".description { margin: 10px 0; color: #555; }\n";
        echo ".screenshot { margin: 15px 0; }\n";
        echo ".screenshot img { max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px; }\n";
        echo ".meta { font-size: 13px; color: #95a5a6; margin-top: 10px; }\n";
        echo ".tags { color: #27ae60; font-weight: 500; }\n";
        echo "hr { border: none; border-top: 1px solid #ecf0f1; margin: 30px 0; }\n";
        echo "</style>\n</head>\n<body>\n";

        echo "<h1>Bookmarks: $viewTitle</h1>\n";
        echo "<p><em>Generated on " . date('F j, Y') . "</em></p>\n";
        echo "<hr>\n";

        foreach ($groupedBookmarks as $group) {
            echo "<h2>" . htmlspecialchars($group['label']) . "</h2>\n";

            foreach ($group['bookmarks'] as $bookmark) {
                echo "<h3><a href=\"" . htmlspecialchars($bookmark['url']) . "\" target=\"_blank\">" . htmlspecialchars($bookmark['title']) . "</a></h3>\n";
                echo "<div class=\"url\">" . htmlspecialchars($bookmark['url']) . "</div>\n";

                if (!empty($bookmark['description'])) {
                    echo "<div class=\"description\">" . nl2br(htmlspecialchars($bookmark['description'])) . "</div>\n";
                }

                if (!empty($bookmark['screenshot'])) {
                    $screenshotPath = ltrim($bookmark['screenshot'], '/');
                    $screenshotUrl = rtrim($config['site_url'], '/') . '/' . $screenshotPath;
                    echo "<div class=\"screenshot\"><img src=\"" . htmlspecialchars($screenshotUrl) . "\" alt=\"Screenshot\"></div>\n";
                }

                echo "<div class=\"meta\">\n";
                if (!empty($bookmark['tags'])) {
                    echo "<span class=\"tags\">Tags: " . htmlspecialchars($bookmark['tags']) . "</span> &middot; ";
                }
                echo "Added: " . date($config['date_format'], strtotime($bookmark['created_at']));
                echo "</div>\n";
                echo "<hr>\n";
            }
        }

        echo "</body>\n</html>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive - <?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">
</head>

<body>
    <?php render_nav($config, $isLoggedIn, 'archive', 'Archive'); ?>

    <div class="page-container">

        <div class="archive-controls">
            <div class="control-section">
                <h3>View Period</h3>
                <div class="view-tabs">
                    <button class="<?= $view === 'day' ? 'active' : '' ?>" onclick="changeView('day')">Day</button>
                    <button class="<?= $view === 'week' ? 'active' : '' ?>" onclick="changeView('week')">Week</button>
                    <button class="<?= $view === 'month' ? 'active' : '' ?>"
                        onclick="changeView('month')">Month</button>
                </div>

                <div class="date-navigation">
                    <button class="btn" onclick="navigateDate(-1)">← Previous</button>
                    <input type="date" id="datePicker" value="<?= htmlspecialchars($date) ?>"
                        onchange="goToDate(this.value)">
                    <button class="btn" onclick="navigateDate(1)">Next →</button>
                    <button class="btn btn-secondary" onclick="goToToday()">Today</button>
                </div>

                <div class="custom-range" style="margin-top: 15px;">
                    <span style="font-size: 14px; color: #7f8c8d;">Custom Range:</span>
                    <input type="date" id="startDate" value="<?= htmlspecialchars($startDate) ?>">
                    <span style="color: #7f8c8d;">to</span>
                    <input type="date" id="endDate" value="<?= htmlspecialchars($endDate) ?>">
                    <button class="btn btn-secondary" onclick="applyCustomRange()">Apply</button>
                </div>
            </div>

            <div class="control-section">
                <h3>Group By</h3>
                <div class="view-tabs">
                    <button class="<?= $groupBy === 'day' ? 'active' : '' ?>"
                        onclick="changeGroupBy('day')">Day</button>
                    <button class="<?= $groupBy === 'week' ? 'active' : '' ?>"
                        onclick="changeGroupBy('week')">Week</button>
                    <button class="<?= $groupBy === 'month' ? 'active' : '' ?>"
                        onclick="changeGroupBy('month')">Month</button>
                </div>
            </div>

            <?php if (!empty($bookmarks)): ?>
                <div class="control-section">
                    <h3>Export</h3>
                    <div class="export-section">
                        <button class="btn btn-secondary" onclick="exportAs('markdown')">Download as Markdown</button>
                        <button class="btn btn-secondary" onclick="exportAs('html')">Download as HTML</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="stats-summary">
                <div class="stat-item">
                    <strong><?= count($bookmarks) ?></strong>
                    Bookmarks in <?= htmlspecialchars($viewTitle) ?>
                </div>
                <div class="stat-item">
                    <strong><?= count($groupedBookmarks) ?></strong>
                    <?= ucfirst($groupBy) ?><?= count($groupedBookmarks) !== 1 ? 's' : '' ?>
                </div>
            </div>
        </div>

        <?php if (empty($bookmarks)): ?>
            <div class="no-results">
                <p>No bookmarks found for <?= htmlspecialchars($viewTitle) ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($groupedBookmarks as $group): ?>
                <div class="group-section">
                    <div class="group-header" onclick="toggleGroup(this)">
                        <h2><?= htmlspecialchars($group['label']) ?></h2>
                        <span class="group-count"><?= count($group['bookmarks']) ?></span>
                    </div>

                    <div class="group-bookmarks">
                        <?php foreach ($group['bookmarks'] as $bookmark): ?>
                            <div class="bookmark<?= !empty($bookmark['private']) ? ' private' : '' ?>">

                                <!-- Screenshot Container (floats right) -->
                                <?php if (!empty($bookmark['screenshot'])): ?>
                                    <div class="bookmark-screenshot-container">
                                        <img src="<?= $config['base_path'] . '/' . htmlspecialchars($bookmark['screenshot']) ?>"
                                            alt="Screenshot" onclick="this.classList.toggle('thumbnail')" title="Click to toggle size">
                                    </div>
                                <?php else: ?>
                                    <div class="bookmark-screenshot-container">
                                        <div class="bookmark-screenshot-placeholder">
                                            <?= strtoupper(substr($bookmark['title'], 0, 1)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Content (wraps around screenshot) -->
                                <div class="bookmark-content">
                                    <div class="bookmark-header">
                                        <h2>
                                            <a href="<?= htmlspecialchars($bookmark['url']) ?>" target="_blank"
                                                rel="noopener noreferrer"><?= htmlspecialchars($bookmark['title']) ?></a>
                                            <?php if (!empty($bookmark['private'])): ?>
                                                <span class="private-badge">PRIVATE</span>
                                            <?php endif; ?>
                                        </h2>
                                        <div class="url"><?= htmlspecialchars($bookmark['url']) ?></div>
                                    </div>

                                    <?php if (!empty($bookmark['description'])): ?>
                                        <div class="description"><?= parseMarkdown($bookmark['description']) ?></div>
                                    <?php endif; ?>

                                    <?php if (!empty($bookmark['tags'])): ?>
                                        <div class="bookmark-tags">
                                            <?php
                                            $tagList = array_map('trim', explode(',', $bookmark['tags']));
                                            foreach ($tagList as $tagItem) {
                                                echo renderTag($tagItem, $config['base_path']);
                                            }
                                            ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="meta">
                                        <div class="bookmark-meta-left">
                                            <span class="bookmark-meta-item">
                                                <?= date($config['date_format'], strtotime($bookmark['created_at'])) ?>
                                            </span>
                                            <?php if (!empty($bookmark['archive_url'])): ?>
                                                <span class="bookmark-meta-item">
                                                    <a href="<?= htmlspecialchars($bookmark['archive_url']) ?>" target="_blank"
                                                        rel="noopener noreferrer" class="bookmark-archive-link">📦 Archive</a>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php render_nav_scripts(); ?>
    <script>
        const BASE_PATH = <?= json_encode($config['base_path']) ?>;
        const IS_LOGGED_IN = <?= json_encode($isLoggedIn) ?>;
        const CURRENT_VIEW = <?= json_encode($view) ?>;
        const CURRENT_DATE = <?= json_encode($date) ?>;
        const CURRENT_GROUP = <?= json_encode($groupBy) ?>;

        function changeView(newView) {
            const params = new URLSearchParams(window.location.search);
            params.set('view', newView);
            params.set('date', CURRENT_DATE);
            params.delete('start');
            params.delete('end');
            window.location.href = '?' + params.toString();
        }

        function changeGroupBy(newGroup) {
            const params = new URLSearchParams(window.location.search);
            params.set('group', newGroup);
            window.location.href = '?' + params.toString();
        }

        function navigateDate(direction) {
            const currentDate = new Date(CURRENT_DATE);

            if (CURRENT_VIEW === 'day') {
                currentDate.setDate(currentDate.getDate() + direction);
            } else if (CURRENT_VIEW === 'week') {
                currentDate.setDate(currentDate.getDate() + (7 * direction));
            } else if (CURRENT_VIEW === 'month') {
                currentDate.setMonth(currentDate.getMonth() + direction);
            }

            const newDate = currentDate.toISOString().split('T')[0];
            goToDate(newDate);
        }

        function goToDate(newDate) {
            const params = new URLSearchParams(window.location.search);
            params.set('date', newDate);
            params.delete('start');
            params.delete('end');
            window.location.href = '?' + params.toString();
        }

        function goToToday() {
            const today = new Date().toISOString().split('T')[0];
            goToDate(today);
        }

        function applyCustomRange() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;

            if (!startDate || !endDate) {
                alert('Please select both start and end dates');
                return;
            }

            if (startDate > endDate) {
                alert('Start date must be before end date');
                return;
            }

            const params = new URLSearchParams(window.location.search);
            params.set('start', startDate);
            params.set('end', endDate);
            params.delete('view');
            params.delete('date');
            window.location.href = '?' + params.toString();
        }

        function exportAs(format) {
            const params = new URLSearchParams(window.location.search);
            params.set('export', format);
            window.location.href = '?' + params.toString();
        }

        function toggleGroup(header) {
            const bookmarksDiv = header.nextElementSibling;
            bookmarksDiv.classList.toggle('collapsed');
        }
    </script>
</body>

</html>