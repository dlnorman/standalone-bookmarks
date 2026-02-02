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
require_once __DIR__ . '/includes/tags.php';

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
$allTags = getAllTagsWithCounts($db, $isLoggedIn);

// Get filter parameter
$filterType = $_GET['type'] ?? 'all';

// Process tags with type info
$processedTags = [];
foreach ($allTags as $tagLower => $data) {
    $parsed = parseTagType($data['display']);
    $processedTags[$tagLower] = [
        'full' => $data['display'],
        'name' => $parsed['name'],
        'type' => $parsed['type'],
        'count' => $data['count'],
    ];
}

// Filter by type if specified
if ($filterType !== 'all') {
    $processedTags = array_filter($processedTags, function($t) use ($filterType) {
        return $t['type'] === $filterType;
    });
}

// Sort tags alphabetically by display name
uasort($processedTags, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

// Count by type for filter tabs
$typeCounts = ['all' => count($allTags), 'tag' => 0, 'person' => 0, 'via' => 0];
foreach ($allTags as $tagLower => $data) {
    $parsed = parseTagType($data['display']);
    $typeCounts[$parsed['type']]++;
}
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
        <!-- Filter Tabs -->
        <div class="tags-filter-tabs">
            <a href="?type=all" class="filter-tab <?= $filterType === 'all' ? 'active' : '' ?>">
                All <span class="tab-count"><?= $typeCounts['all'] ?></span>
            </a>
            <a href="?type=tag" class="filter-tab <?= $filterType === 'tag' ? 'active' : '' ?>">
                Tags <span class="tab-count"><?= $typeCounts['tag'] ?></span>
            </a>
            <a href="?type=person" class="filter-tab filter-tab-person <?= $filterType === 'person' ? 'active' : '' ?>">
                <span class="tag-icon">&#128100;</span> People <span class="tab-count"><?= $typeCounts['person'] ?></span>
            </a>
            <a href="?type=via" class="filter-tab filter-tab-via <?= $filterType === 'via' ? 'active' : '' ?>">
                <span class="tag-icon">&#128228;</span> Via <span class="tab-count"><?= $typeCounts['via'] ?></span>
            </a>
        </div>

        <div class="tags-container">
            <?php if (empty($processedTags)): ?>
                <div class="no-tags">
                    <p>No tags found. Add some tags to your bookmarks!</p>
                </div>
            <?php else: ?>
                <div class="tag-cloud">
                    <?php
                    // Calculate font sizes using logarithmic scale for better distribution
                    $counts = array_column($processedTags, 'count');
                    $maxCount = max($counts);
                    $minCount = min($counts);

                    // Font size range in rem
                    $minFontSize = 0.9;
                    $maxFontSize = 2.4;

                    // Pre-calculate log bounds
                    $logMin = log(max(1, $minCount));
                    $logMax = log(max(1, $maxCount));

                    foreach ($processedTags as $tagData):
                        $count = $tagData['count'];
                        // Use logarithmic scale for continuous sizing
                        // This adapts to the actual data range and differentiates all values
                        if ($logMax == $logMin) {
                            // All tags have same count - use middle size
                            $fontSize = ($minFontSize + $maxFontSize) / 2;
                            $ratio = 0.5;
                        } else {
                            $logCount = log(max(1, $count));
                            $ratio = ($logCount - $logMin) / ($logMax - $logMin);
                            $fontSize = $minFontSize + $ratio * ($maxFontSize - $minFontSize);
                        }

                        // Determine size class for styling (opacity, color, weight)
                        if ($ratio < 0.2) {
                            $sizeClass = 1;
                        } elseif ($ratio < 0.4) {
                            $sizeClass = 2;
                        } elseif ($ratio < 0.6) {
                            $sizeClass = 3;
                        } elseif ($ratio < 0.8) {
                            $sizeClass = 4;
                        } else {
                            $sizeClass = 5;
                        }

                        $typeClass = getTagTypeClass($tagData['type']);
                        $icon = getTagTypeIcon($tagData['type']);
                        ?>
                        <div class="tag-item">
                            <a href="<?= $config['base_path'] ?>/?tag=<?= urlencode($tagData['full']) ?>"
                                class="tag-link tag-size-<?= $sizeClass ?> <?= $typeClass ?>"
                                style="font-size: <?= number_format($fontSize, 2) ?>rem">
                                <?= $icon ?><?= htmlspecialchars($tagData['name']) ?>
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