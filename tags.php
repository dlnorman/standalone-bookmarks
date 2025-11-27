<?php
/**
 * Tags view - shows all tags with counts
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found. Please copy config-example.php to config.php and adjust settings.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/nav.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Check if logged in (but don't require it)
$isLoggedIn = is_logged_in();

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed. Run init_db.php first.');
}

// HTTP Caching: Get last modification time from most recent bookmark
if ($isLoggedIn) {
    $lastModStmt = $db->query("SELECT MAX(updated_at) as last_mod FROM bookmarks");
} else {
    $lastModStmt = $db->query("SELECT MAX(updated_at) as last_mod FROM bookmarks WHERE private = 0");
}
$lastMod = $lastModStmt->fetch(PDO::FETCH_ASSOC)['last_mod'];

if ($lastMod) {
    $lastModTime = strtotime($lastMod);
    $etag = md5($lastMod . ($isLoggedIn ? 'auth' : 'public'));

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

// Fetch bookmarks to extract tags (exclude private if not logged in)
if ($isLoggedIn) {
    $stmt = $db->query("SELECT tags FROM bookmarks WHERE tags IS NOT NULL AND tags != ''");
} else {
    $stmt = $db->query("SELECT tags FROM bookmarks WHERE tags IS NOT NULL AND tags != '' AND private = 0");
}
$allTags = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $tags = array_map('trim', explode(',', $row['tags']));
    foreach ($tags as $tag) {
        if (!empty($tag)) {
            $tag = strtolower($tag); // Normalize to lowercase for consistency
            if (!isset($allTags[$tag])) {
                $allTags[$tag] = 0;
            }
            $allTags[$tag]++;
        }
    }
}

// Sort tags alphabetically (size will still be based on count)
ksort($allTags);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tags - <?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">
</head>

<body>
    <?php render_nav($config, $isLoggedIn, 'tags', 'Tags'); ?>

    <div class="page-container">

        <div class="tags-container">
            <?php if (empty($allTags)): ?>
                <div class="no-tags">
                    <p>No tags found. Add some tags to your bookmarks!</p>
                </div>
            <?php else: ?>
                <div class="tag-cloud">
                    <?php
                    // Calculate size classes based on frequency using logarithmic scale
                    $maxCount = max($allTags);
                    $minCount = min($allTags);

                    foreach ($allTags as $tag => $count):
                        // Use logarithmic scale for better distribution
                        // This gives more differentiation at lower counts
                        if ($count == 1) {
                            $size = 1;
                        } elseif ($count <= 2) {
                            $size = 2;
                        } elseif ($count <= 4) {
                            $size = 3;
                        } elseif ($count <= 8) {
                            $size = 4;
                        } else {
                            $size = 5;
                        }
                        ?>
                        <div class="tag-item">
                            <a href="<?= $config['base_path'] ?>/?tag=<?= urlencode($tag) ?>"
                                class="tag-link tag-size-<?= $size ?>">
                                <?= htmlspecialchars($tag) ?>
                                <span class="tag-count"><?= $count ?></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php render_nav_scripts(); ?>
</body>

</html>