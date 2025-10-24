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

// Require authentication
require_auth($config);

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed. Run init_db.php first.');
}

// Fetch all bookmarks to extract tags
$stmt = $db->query("SELECT tags FROM bookmarks WHERE tags IS NOT NULL AND tags != ''");
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
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 0;
            line-height: 1.6;
            background: #f5f5f5;
            color: #333;
        }

        .page-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }

        .tags-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .tag-cloud {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
        }

        .tag-item {
            display: inline-block;
        }

        .tag-link {
            display: inline-block;
            padding: 8px 16px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 20px;
            transition: all 0.2s;
        }

        .tag-link:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .tag-count {
            font-size: 0.85em;
            opacity: 0.9;
            margin-left: 5px;
            background: rgba(255,255,255,0.3);
            padding: 2px 6px;
            border-radius: 10px;
        }

        /* Size tags based on frequency - wider spread for more visual impact */
        .tag-size-1 { font-size: 0.75em; }
        .tag-size-2 { font-size: 0.95em; }
        .tag-size-3 { font-size: 1.2em; }
        .tag-size-4 { font-size: 1.6em; font-weight: 600; }
        .tag-size-5 { font-size: 2.2em; font-weight: 700; }

        .no-tags {
            text-align: center;
            color: #7f8c8d;
            padding: 40px;
        }

        @media (max-width: 600px) {
            .page-container {
                padding: 10px;
            }

            .tags-container {
                padding: 20px;
            }

            .tag-cloud {
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <?php render_nav($config, true, 'tags', 'Tags'); ?>

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
