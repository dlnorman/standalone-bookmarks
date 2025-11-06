<?php
/**
 * RSS feed for bookmarks
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/markdown.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed.');
}

// HTTP Caching: Get last modification time from most recent bookmark
$lastModStmt = $db->query("SELECT MAX(created_at) as last_mod FROM bookmarks WHERE private = 0");
$lastMod = $lastModStmt->fetch(PDO::FETCH_ASSOC)['last_mod'];

if ($lastMod) {
    $lastModTime = strtotime($lastMod);
    $etag = md5($lastMod . $config['rss_limit']);

    // Set caching headers (cache for 5 minutes)
    header('Cache-Control: public, max-age=300');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModTime) . ' GMT');
    header('ETag: "' . $etag . '"');

    // Check if client has cached version
    $ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;
    $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') : '';

    // Return 304 Not Modified if content hasn't changed
    if ($ifModifiedSince >= $lastModTime || $ifNoneMatch === $etag) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
}

// Fetch recent bookmarks (exclude private)
$stmt = $db->prepare("
    SELECT * FROM bookmarks
    WHERE private = 0
    ORDER BY created_at DESC
    LIMIT ?
");
$stmt->execute([$config['rss_limit']]);
$bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set content type
header('Content-Type: application/rss+xml; charset=utf-8');

// Generate RSS feed
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
    <channel>
        <title><?= htmlspecialchars($config['rss_title']) ?></title>
        <link><?= htmlspecialchars($config['site_url']) ?></link>
        <description><?= htmlspecialchars($config['rss_description']) ?></description>
        <language>en</language>
        <lastBuildDate><?= date(DATE_RSS) ?></lastBuildDate>
        <atom:link href="<?= htmlspecialchars($config['site_url']) ?>/rss.php" rel="self" type="application/rss+xml" />

        <?php foreach ($bookmarks as $bookmark): ?>
        <item>
            <title><?= htmlspecialchars($bookmark['title']) ?></title>
            <link><?= htmlspecialchars($bookmark['url']) ?></link>
            <guid isPermaLink="false">bookmark-<?= $bookmark['id'] ?></guid>
            <pubDate><?= date(DATE_RSS, strtotime($bookmark['created_at'])) ?></pubDate>
            <description><![CDATA[
                <?php if (!empty($bookmark['screenshot'])): ?>
                <p><img src="<?= htmlspecialchars($config['site_url']) ?>/<?= htmlspecialchars($bookmark['screenshot']) ?>" alt="<?= htmlspecialchars($bookmark['title']) ?>" style="max-width: 100%; height: auto;" /></p>
                <?php endif; ?>
                <?php if (!empty($bookmark['description'])): ?>
                <?= parseMarkdown($bookmark['description']) ?>
                <?php endif; ?>
            ]]></description>
            <?php if (!empty($bookmark['screenshot'])): ?>
            <media:thumbnail url="<?= htmlspecialchars($config['site_url']) ?>/<?= htmlspecialchars($bookmark['screenshot']) ?>" />
            <enclosure url="<?= htmlspecialchars($config['site_url']) ?>/<?= htmlspecialchars($bookmark['screenshot']) ?>" type="image/jpeg" />
            <?php endif; ?>
            <?php if (!empty($bookmark['tags'])): ?>
                <?php
                $tags = array_map('trim', explode(',', $bookmark['tags']));
                foreach ($tags as $tag):
                    if (!empty($tag)):
                ?>
            <category><?= htmlspecialchars($tag) ?></category>
                <?php
                    endif;
                endforeach;
                ?>
            <?php endif; ?>
        </item>
        <?php endforeach; ?>

    </channel>
</rss>
