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

/**
 * Simple markdown parser without external dependencies
 * Supports: headers, bold, italic, links, code blocks, inline code, lists, blockquotes
 */
function parseMarkdown($text) {
    if (empty($text)) return '';

    // Process code blocks first (before escaping, to preserve content)
    $codeBlocks = [];
    $text = preg_replace_callback('/```([a-z]*)\n(.*?)```/s', function($matches) use (&$codeBlocks) {
        $lang = $matches[1] ? ' class="language-' . $matches[1] . '"' : '';
        $placeholder = '___CODE_BLOCK_' . count($codeBlocks) . '___';
        $codeBlocks[$placeholder] = '<pre><code' . $lang . '>' . htmlspecialchars(trim($matches[2]), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        return $placeholder;
    }, $text);

    // Split into lines for processing
    $lines = explode("\n", $text);
    $output = [];
    $inList = false;
    $inBlockquote = false;

    foreach ($lines as $lineNum => $line) {
        $trimmed = trim($line);

        // Skip if line is a code block placeholder
        if (strpos($line, '___CODE_BLOCK_') !== false) {
            $output[] = $line;
            continue;
        }

        // Headers (# through ######)
        if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches)) {
            $level = strlen($matches[1]);
            $output[] = '<h' . $level . '>' . htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8') . '</h' . $level . '>';
            continue;
        }

        // Unordered lists (- or *)
        if (preg_match('/^[\*\-]\s+(.+)$/', $trimmed, $matches)) {
            if (!$inList) {
                $output[] = '<ul>';
                $inList = true;
            }
            $output[] = '<li>' . htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '</li>';
            continue;
        } elseif ($inList) {
            $output[] = '</ul>';
            $inList = false;
        }

        // Ordered lists (1. 2. etc.)
        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $matches)) {
            if (!$inList) {
                $output[] = '<ol>';
                $inList = 'ol';
            }
            $output[] = '<li>' . htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '</li>';
            continue;
        } elseif ($inList === 'ol') {
            $output[] = '</ol>';
            $inList = false;
        }

        // Blockquotes (including empty blockquote lines with just >)
        if (preg_match('/^>\s*(.*)$/', $trimmed, $matches)) {
            if (!$inBlockquote) {
                $output[] = '<blockquote>';
                $inBlockquote = true;
            }
            // Add content (or empty line if no content)
            if (!empty($matches[1])) {
                $output[] = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '<br>';
            } else {
                $output[] = '<br>'; // Empty blockquote line creates a line break
            }
            continue;
        } elseif ($inBlockquote) {
            $output[] = '</blockquote>';
            $inBlockquote = false;
        }

        // Horizontal rules
        if (preg_match('/^(\*\*\*|---|___)$/', $trimmed)) {
            $output[] = '<hr>';
            continue;
        }

        // Regular line - escape it
        $output[] = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
    }

    // Close any open lists or blockquotes
    if ($inList === 'ol') {
        $output[] = '</ol>';
    } elseif ($inList) {
        $output[] = '</ul>';
    }
    if ($inBlockquote) {
        $output[] = '</blockquote>';
    }

    $text = implode("\n", $output);

    // Inline elements (process after block elements)
    // Note: Content is already HTML-escaped at this point, so we work with escaped HTML

    // Inline code (backticks) - process before bold/italic to avoid conflicts
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

    // Bold (**text** or __text__)
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);

    // Italic (*text* or _text_)
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/_(.+?)_/', '<em>$1</em>', $text);

    // Links [text](url) - URL needs to be unescaped for href attribute
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^\)]+)\)/', function($matches) {
        $text = $matches[1];
        $url = htmlspecialchars_decode($matches[2], ENT_QUOTES);
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
    }, $text);

    // Restore code blocks
    foreach ($codeBlocks as $placeholder => $codeBlock) {
        $text = str_replace($placeholder, $codeBlock, $text);
    }

    // Convert newlines to <br> (but not inside block elements)
    $text = preg_replace('/\n(?!<\/(ul|ol|blockquote|pre|h[1-6])>)/', '<br>', $text);

    return $text;
}

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
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
            background: #f5f5f5;
            color: #333;
        }

        header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        h1 {
            margin: 0 0 15px 0;
            font-size: 24px;
            color: #2c3e50;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .btn {
            padding: 8px 15px;
            background: #95a5a6;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 13px;
            display: inline-block;
            border: none;
            cursor: pointer;
        }

        .btn:hover {
            background: #7f8c8d;
        }

        .btn-primary {
            background: #27ae60;
        }

        .btn-primary:hover {
            background: #229954;
        }

        .btn-secondary {
            background: #3498db;
        }

        .btn-secondary:hover {
            background: #2980b9;
        }

        .archive-controls {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .control-section {
            margin-bottom: 20px;
        }

        .control-section:last-child {
            margin-bottom: 0;
        }

        .control-section h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #2c3e50;
        }

        .view-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .view-tabs button {
            padding: 8px 16px;
            background: #ecf0f1;
            color: #2c3e50;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }

        .view-tabs button.active {
            background: #3498db;
            color: white;
        }

        .view-tabs button:hover:not(.active) {
            background: #d5dbdb;
        }

        .date-navigation {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .date-navigation input[type="date"] {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .custom-range {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .stats-summary {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            padding: 15px;
            background: #e8f4f8;
            border-radius: 4px;
            margin-top: 15px;
        }

        .stat-item {
            font-size: 14px;
            color: #2c3e50;
        }

        .stat-item strong {
            color: #3498db;
            font-size: 18px;
            display: block;
        }

        .group-section {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .group-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
            cursor: pointer;
        }

        .group-header h2 {
            margin: 0;
            font-size: 20px;
            color: #2c3e50;
        }

        .group-count {
            background: #3498db;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: bold;
        }

        .group-bookmarks {
            display: block;
        }

        .group-bookmarks.collapsed {
            display: none;
        }

        .bookmark {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 6px;
            border-left: 3px solid #3498db;
        }

        .bookmark:last-child {
            margin-bottom: 0;
        }

        .bookmark h3 {
            margin: 0 0 8px 0;
            font-size: 16px;
        }

        .bookmark h3 a {
            color: #2c3e50;
            text-decoration: none;
        }

        .bookmark h3 a:hover {
            color: #3498db;
        }

        .bookmark .url {
            color: #7f8c8d;
            font-size: 12px;
            word-break: break-all;
            margin-bottom: 8px;
        }

        .bookmark .description {
            color: #555;
            font-size: 14px;
            margin-bottom: 8px;
            line-height: 1.6;
        }

        /* Markdown styling within descriptions */
        .bookmark .description h1,
        .bookmark .description h2,
        .bookmark .description h3,
        .bookmark .description h4,
        .bookmark .description h5,
        .bookmark .description h6 {
            margin: 15px 0 10px 0;
            color: #2c3e50;
            font-weight: 600;
        }

        .bookmark .description h1 { font-size: 1.5em; }
        .bookmark .description h2 { font-size: 1.3em; }
        .bookmark .description h3 { font-size: 1.1em; }
        .bookmark .description h4 { font-size: 1em; }
        .bookmark .description h5 { font-size: 0.9em; }
        .bookmark .description h6 { font-size: 0.85em; }

        .bookmark .description strong {
            font-weight: 600;
            color: #2c3e50;
        }

        .bookmark .description em {
            font-style: italic;
        }

        .bookmark .description code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Monaco', 'Menlo', 'Courier New', monospace;
            font-size: 0.9em;
            color: #c7254e;
        }

        .bookmark .description pre {
            background: #f4f4f4;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 0 12px;
            overflow-x: auto;
            margin: 0;
        }

        .bookmark .description pre code {
            background: none;
            padding: 0;
            color: #333;
            font-size: 0.85em;
        }

        .bookmark .description ul,
        .bookmark .description ol {
            margin: 10px 0;
            padding-left: 30px;
        }

        .bookmark .description li {
            margin: 5px 0;
        }

        .bookmark .description blockquote {
            border-left: 4px solid #3498db;
            padding-left: 15px;
            margin: 0;
            color: #666;
            font-style: italic;
        }

        .bookmark .description hr {
            border: none;
            border-top: 2px solid #ddd;
            margin: 15px 0;
        }

        .bookmark .description a {
            color: #3498db;
            text-decoration: none;
        }

        .bookmark .description a:hover {
            text-decoration: underline;
        }

        .bookmark .screenshot {
            margin: 10px 0;
        }

        .bookmark .screenshot img {
            max-width: 100%;
            height: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
        }

        .bookmark .screenshot img.thumbnail {
            max-width: 300px;
        }

        .bookmark .meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 12px;
            color: #7f8c8d;
        }

        .bookmark.private {
            border-left-color: #e67e22;
        }

        .bookmark .private-badge {
            display: inline-block;
            padding: 2px 8px;
            background: #e67e22;
            color: white;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            margin-left: 10px;
        }

        .bookmark .tags {
            color: #3498db;
        }

        .no-results {
            background: white;
            padding: 40px;
            text-align: center;
            border-radius: 8px;
            color: #7f8c8d;
        }

        .export-section {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .menu-dropdown {
            position: relative;
            display: inline-block;
        }

        .menu-trigger {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .menu-trigger:hover {
            background: #7f8c8d;
        }

        .menu-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 5px;
            background: white;
            min-width: 160px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-radius: 4px;
            z-index: 1000;
            overflow: hidden;
        }

        .menu-content.active {
            display: block;
        }

        .menu-content a {
            display: block;
            padding: 10px 15px;
            color: #2c3e50;
            text-decoration: none;
            font-size: 13px;
            transition: background 0.15s;
        }

        .menu-content a:hover {
            background: #f8f9fa;
        }

        .menu-divider {
            height: 1px;
            background: #ecf0f1;
            margin: 5px 0;
        }

        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            header {
                padding: 15px;
            }

            .archive-controls {
                padding: 15px;
            }

            .date-navigation {
                flex-direction: column;
                align-items: stretch;
            }

            .date-navigation button,
            .date-navigation input {
                width: 100%;
            }

            .group-section {
                padding: 15px;
            }

            .bookmark {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Archive - <?= htmlspecialchars($config['site_title']) ?></h1>

        <div class="actions">
            <a href="<?= $config['base_path'] ?>" class="btn">← Back to Bookmarks</a>
            <?php if ($isLoggedIn): ?>
                <a href="#" onclick="showAddBookmark(); return false;" class="btn btn-primary">Add Bookmark</a>
                <a href="<?= $config['base_path'] ?>/tags.php" class="btn">Tags</a>
                <div class="menu-dropdown">
                    <button class="menu-trigger" onclick="toggleMenu(event)">
                        <span>⚙</span> Menu
                    </button>
                    <div class="menu-content" id="mainMenu">
                        <a href="<?= $config['base_path'] ?>/bookmarklet-setup.php">Bookmarklet</a>
                        <a href="<?= $config['base_path'] ?>/rss.php">RSS Feed</a>
                        <div class="menu-divider"></div>
                        <a href="<?= $config['base_path'] ?>/import.php">Import</a>
                        <a href="<?= $config['base_path'] ?>/export.php">Export</a>
                        <div class="menu-divider"></div>
                        <a href="<?= $config['base_path'] ?>/logout.php">Logout</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= $config['base_path'] ?>/login.php" class="btn btn-primary">Login</a>
                <a href="<?= $config['base_path'] ?>/rss.php" class="btn">RSS Feed</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="archive-controls">
        <div class="control-section">
            <h3>View Period</h3>
            <div class="view-tabs">
                <button class="<?= $view === 'day' ? 'active' : '' ?>" onclick="changeView('day')">Day</button>
                <button class="<?= $view === 'week' ? 'active' : '' ?>" onclick="changeView('week')">Week</button>
                <button class="<?= $view === 'month' ? 'active' : '' ?>" onclick="changeView('month')">Month</button>
            </div>

            <div class="date-navigation">
                <button class="btn" onclick="navigateDate(-1)">← Previous</button>
                <input type="date" id="datePicker" value="<?= htmlspecialchars($date) ?>" onchange="goToDate(this.value)">
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
                <button class="<?= $groupBy === 'day' ? 'active' : '' ?>" onclick="changeGroupBy('day')">Day</button>
                <button class="<?= $groupBy === 'week' ? 'active' : '' ?>" onclick="changeGroupBy('week')">Week</button>
                <button class="<?= $groupBy === 'month' ? 'active' : '' ?>" onclick="changeGroupBy('month')">Month</button>
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
                            <h3>
                                <a href="<?= htmlspecialchars($bookmark['url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($bookmark['title']) ?></a>
                                <?php if (!empty($bookmark['private'])): ?>
                                    <span class="private-badge">PRIVATE</span>
                                <?php endif; ?>
                            </h3>
                            <div class="url"><?= htmlspecialchars($bookmark['url']) ?></div>
                            <?php if (!empty($bookmark['description'])): ?>
                                <div class="description"><?= parseMarkdown($bookmark['description']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($bookmark['screenshot'])): ?>
                                <div class="screenshot">
                                    <img src="<?= $config['base_path'] . '/' . htmlspecialchars($bookmark['screenshot']) ?>"
                                         class="thumbnail"
                                         alt="Screenshot"
                                         onclick="this.classList.toggle('thumbnail')"
                                         title="Click to toggle size">
                                </div>
                            <?php endif; ?>
                            <div class="meta">
                                <div>
                                    <?php if (!empty($bookmark['tags'])): ?>
                                        <span class="tags">Tags:
                                        <?php
                                            $tagList = array_map('trim', explode(',', $bookmark['tags']));
                                            $tagLinks = [];
                                            foreach ($tagList as $tagItem) {
                                                $tagLinks[] = '<a href="' . $config['base_path'] . '/?tag=' . urlencode($tagItem) . '" style="color: #3498db; text-decoration: none;">' . htmlspecialchars($tagItem) . '</a>';
                                            }
                                            echo implode(', ', $tagLinks);
                                        ?>
                                        </span>
                                    <?php endif; ?>
                                    <span> &middot; <?= date($config['date_format'], strtotime($bookmark['created_at'])) ?></span>
                                    <?php if (!empty($bookmark['archive_url'])): ?>
                                        <span> &middot; <a href="<?= htmlspecialchars($bookmark['archive_url']) ?>" target="_blank" rel="noopener noreferrer" style="color: #27ae60;">Archive</a></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

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

        function showAddBookmark() {
            if (!IS_LOGGED_IN) return;
            const url = prompt('Enter URL:');
            if (!url) return;

            const title = prompt('Enter title:');
            if (!title) return;

            const description = prompt('Enter description (optional):') || '';
            const tags = prompt('Enter tags (comma-separated, optional):') || '';
            const isPrivate = confirm('Make this bookmark private? (Hidden from RSS feed and recent bookmarks)');

            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('url', url);
            formData.append('title', title);
            formData.append('description', description);
            formData.append('tags', tags);
            if (isPrivate) {
                formData.append('private', '1');
            }

            fetch(BASE_PATH + '/api.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Bookmark added successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(err => alert('Error: ' + err));
        }

        // Menu dropdown functionality
        function toggleMenu(event) {
            event.stopPropagation();
            const menu = document.getElementById('mainMenu');
            menu.classList.toggle('active');
        }

        // Close menu when clicking outside
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('mainMenu');
            if (menu && !event.target.closest('.menu-dropdown')) {
                menu.classList.remove('active');
            }
        });
    </script>
</body>
</html>
