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

// Sort tags by count (descending), then alphabetically
arsort($allTags);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tags - <?= htmlspecialchars($config['site_title']) ?></title>
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
        }

        .btn {
            padding: 8px 15px;
            background: #95a5a6;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 13px;
            display: inline-block;
        }

        .btn:hover {
            background: #7f8c8d;
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

        /* Size tags based on frequency */
        .tag-size-1 { font-size: 0.9em; }
        .tag-size-2 { font-size: 1em; }
        .tag-size-3 { font-size: 1.1em; }
        .tag-size-4 { font-size: 1.3em; }
        .tag-size-5 { font-size: 1.5em; }

        .no-tags {
            text-align: center;
            color: #7f8c8d;
            padding: 40px;
        }

        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            header {
                padding: 15px;
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
    <header>
        <h1>Tags</h1>
        <div class="actions">
            <a href="<?= $config['base_path'] ?>/" class="btn">Back to Bookmarks</a>
            <a href="<?= $config['base_path'] ?>/logout.php" class="btn">Logout</a>
        </div>
    </header>

    <div class="tags-container">
        <?php if (empty($allTags)): ?>
            <div class="no-tags">
                <p>No tags found. Add some tags to your bookmarks!</p>
            </div>
        <?php else: ?>
            <div class="tag-cloud">
                <?php
                // Calculate size classes based on frequency
                $maxCount = max($allTags);
                $minCount = min($allTags);
                $range = max($maxCount - $minCount, 1);

                foreach ($allTags as $tag => $count):
                    // Calculate size (1-5)
                    $normalized = ($count - $minCount) / $range;
                    $size = max(1, min(5, ceil($normalized * 5)));
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
</body>
</html>
