<?php
/**
 * Export bookmarks in Pinboard/Delicious format (Netscape Bookmark File Format)
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

// Require authentication
// Note: session_start() is already called in auth.php
if (!is_logged_in()) {
    header('Location: ' . $config['base_path'] . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed. Run init_db.php first.');
}

// Handle export
if (isset($_GET['download']) && $_GET['download'] === '1') {
    exportBookmarks($db, $config);
    exit;
}

function exportBookmarks($db, $config)
{
    // Fetch all bookmarks
    $stmt = $db->query("SELECT * FROM bookmarks ORDER BY created_at DESC");
    $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set headers for file download
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bookmarks-' . date('Y-m-d') . '.html"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Generate Netscape Bookmark file format
    echo '<!DOCTYPE NETSCAPE-Bookmark-file-1>' . "\n";
    echo '<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">' . "\n";
    echo '<!-- This is an automatically generated file.' . "\n";
    echo '     It will be read and overwritten.' . "\n";
    echo '     DO NOT EDIT! -->' . "\n";
    echo '<TITLE>Bookmarks</TITLE>' . "\n";
    echo '<H1>Bookmarks</H1>' . "\n";
    echo '<DL><p>' . "\n";

    foreach ($bookmarks as $bookmark) {
        // Convert datetime to Unix timestamp
        $timestamp = strtotime($bookmark['created_at']);

        // Build attributes
        $attrs = [
            'HREF="' . htmlspecialchars($bookmark['url'], ENT_QUOTES, 'UTF-8') . '"',
            'ADD_DATE="' . $timestamp . '"',
            'PRIVATE="' . (empty($bookmark['private']) ? '0' : '1') . '"'
        ];

        // Add TAGS attribute if tags exist
        if (!empty($bookmark['tags'])) {
            $attrs[] = 'TAGS="' . htmlspecialchars($bookmark['tags'], ENT_QUOTES, 'UTF-8') . '"';
        }

        // Output bookmark
        echo '    <DT><A ' . implode(' ', $attrs) . '>' . htmlspecialchars($bookmark['title'], ENT_QUOTES, 'UTF-8') . '</A>' . "\n";

        // Add description if it exists
        if (!empty($bookmark['description'])) {
            echo '    <DD>' . htmlspecialchars($bookmark['description'], ENT_QUOTES, 'UTF-8') . "\n";
        }
    }

    echo '</DL><p>' . "\n";
}

// Get bookmark count
$stmt = $db->query("SELECT COUNT(*) as total FROM bookmarks");
$total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Bookmarks - <?= htmlspecialchars($config['site_title']) ?></title>
    <link rel="stylesheet" href="css/main.css">
</head>

<body class="export-page">
    <header>
        <div class="breadcrumb">
            <a href="<?= $config['base_path'] ?>">← Back to Bookmarks</a>
        </div>
        <h1>Export Bookmarks</h1>
    </header>

    <div class="content">
        <div class="info-box">
            <h3>About Export</h3>
            <p>This tool exports all your bookmarks in the Netscape Bookmark File Format, compatible with:</p>
            <ul>
                <li><strong>Pinboard</strong> - Import at <a href="https://pinboard.in/import/"
                        target="_blank">pinboard.in/import/</a></li>
                <li><strong>Delicious</strong> - Import bookmarks in HTML format</li>
                <li><strong>Most browsers</strong> - Firefox, Chrome, Safari, Edge can import this format</li>
                <li><strong>Other bookmarking services</strong> - Most services support this standard format</li>
            </ul>
            <p>The export includes all bookmark data: URLs, titles, descriptions, tags, privacy settings, and creation
                dates.</p>
        </div>

        <div class="export-stats">
            <div class="count"><?= number_format($total) ?></div>
            <div class="label">Bookmarks to Export</div>
        </div>

        <div class="export-actions">
            <a href="?download=1" class="btn btn-large">Download Bookmarks</a>
            <a href="<?= $config['base_path'] ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </div>
</body>

</html>