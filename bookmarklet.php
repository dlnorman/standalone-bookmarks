<?php
/**
 * Bookmarklet interface - opens in popup to add bookmarks
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Require authentication
require_auth($config);

// Get URL and title from query string
$url = $_GET['url'] ?? '';
$title = $_GET['title'] ?? '';
$selectedText = $_GET['selected'] ?? '';

// Check if this URL already exists in the database
$existingBookmark = null;
$isEdit = false;
if (!empty($url) && empty($_POST['submit'])) {
    try {
        $db = new PDO('sqlite:' . $config['db_path']);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("SELECT * FROM bookmarks WHERE url = ? LIMIT 1");
        $stmt->execute([$url]);
        $existingBookmark = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingBookmark) {
            $isEdit = true;
            // Load existing data - don't fetch metadata from the page
            $title = $existingBookmark['title'];
            $description = $existingBookmark['description'] ?? '';
            $tags = $existingBookmark['tags'] ?? '';
        }
    } catch (PDOException $e) {
        // Continue with new bookmark if query fails
        error_log("Bookmark lookup failed: " . $e->getMessage());
    }
}

// If we have a URL but no metadata yet, fetch it
if (empty($existingBookmark)) {
    $description = '';
    $tags = '';
}

if (!empty($url) && empty($_POST['submit']) && !$isEdit) {
    // Fetch metadata directly from the URL
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'Mozilla/5.0 (compatible; BookmarksApp/1.0)',
        ]
    ]);

    $html = @file_get_contents($url, false, $context);

    if ($html) {
        // Extract title
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $extractedTitle = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            // Prefer extracted title over document.title (which may have extra content)
            if (!empty($extractedTitle)) {
                $title = $extractedTitle;
            }
        }

        // Extract meta description (try multiple patterns)
        // Pattern 1: name before content
        if (preg_match('/<meta\s+[^>]*name\s*=\s*["\']description["\']\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*>/is', $html, $matches)) {
            $description = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }
        // Pattern 2: content before name
        elseif (preg_match('/<meta\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*name\s*=\s*["\']description["\']/is', $html, $matches)) {
            $description = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }
        // Pattern 3: Open Graph description
        elseif (preg_match('/<meta\s+[^>]*property\s*=\s*["\']og:description["\']\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*>/is', $html, $matches)) {
            $description = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }
        // Pattern 4: OG content before property
        elseif (preg_match('/<meta\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*property\s*=\s*["\']og:description["\']/is', $html, $matches)) {
            $description = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }

        // Extract keywords/tags (try multiple patterns)
        if (preg_match('/<meta\s+[^>]*name\s*=\s*["\']keywords["\']\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*>/is', $html, $matches)) {
            $tags = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/<meta\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*name\s*=\s*["\']keywords["\']/is', $html, $matches)) {
            $tags = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }
    }

    // Append selected text to description if provided
    if (!empty($selectedText)) {
        if (!empty($description)) {
            $description .= "\n\n---\nSelected text:\n" . $selectedText;
        } else {
            $description = $selectedText;
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $submitUrl = $_POST['url'] ?? '';
    $submitTitle = $_POST['title'] ?? '';
    $submitDescription = $_POST['description'] ?? '';
    $submitTags = $_POST['tags'] ?? '';
    $submitPrivate = isset($_POST['private']) ? 1 : 0;
    $submitBookmarkId = $_POST['bookmark_id'] ?? null;

    if (!empty($submitUrl) && !empty($submitTitle)) {
        // Connect to database
        try {
            $db = new PDO('sqlite:' . $config['db_path']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Use PHP's current time (respects timezone setting) instead of SQLite's CURRENT_TIMESTAMP (always UTC)
            $now = date('Y-m-d H:i:s');

            if (!empty($submitBookmarkId)) {
                // Update existing bookmark
                $stmt = $db->prepare("
                    UPDATE bookmarks
                    SET title = ?, description = ?, tags = ?, private = ?, updated_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([$submitTitle, $submitDescription, $submitTags, $submitPrivate, $now, $submitBookmarkId]);
                $bookmarkId = $submitBookmarkId;
            } else {
                // Insert new bookmark
                $stmt = $db->prepare("
                    INSERT INTO bookmarks (url, title, description, tags, private, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$submitUrl, $submitTitle, $submitDescription, $submitTags, $submitPrivate, $now, $now]);

                $bookmarkId = $db->lastInsertId();

                // Queue background jobs for thumbnail and archive (only for new bookmarks)
                // Check if jobs table exists
                $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'")->fetchAll();
                if (!empty($tables)) {
                    // Queue archive job
                    $stmt = $db->prepare("
                        INSERT INTO jobs (bookmark_id, job_type, payload, created_at, updated_at)
                        VALUES (?, 'archive', ?, ?, ?)
                    ");
                    $stmt->execute([$bookmarkId, $submitUrl, $now, $now]);

                    // Queue thumbnail job
                    $stmt = $db->prepare("
                        INSERT INTO jobs (bookmark_id, job_type, payload, created_at, updated_at)
                        VALUES (?, 'thumbnail', ?, ?, ?)
                    ");
                    $stmt->execute([$bookmarkId, $submitUrl, $now, $now]);
                }
            }

            $success = true;
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    } else {
        $error = 'URL and title are required';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Bookmark</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            padding: 20px;
            margin: 0;
            background: #f5f5f5;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        h1 {
            margin: 0 0 20px 0;
            font-size: 20px;
            color: #2c3e50;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }

        input[type="text"],
        input[type="url"],
        textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-primary {
            background: #27ae60;
            color: white;
        }

        .btn-primary:hover {
            background: #229954;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        .help-text {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?= $isEdit ? 'Edit Bookmark' : 'Add Bookmark' ?></h1>

        <?php if (isset($success)): ?>
            <div class="success">
                Bookmark <?= $isEdit ? 'updated' : 'added' ?> successfully!
            </div>
            <script>
                setTimeout(function() {
                    window.close();
                }, 1500);
            </script>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php if ($isEdit): ?>
            <input type="hidden" name="bookmark_id" value="<?= $existingBookmark['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="url">URL *</label>
                <input type="url" id="url" name="url" required value="<?= htmlspecialchars($url) ?>" <?= $isEdit ? 'readonly' : '' ?>>
            </div>

            <div class="form-group">
                <label for="title">Title *</label>
                <input type="text" id="title" name="title" required value="<?= htmlspecialchars($title) ?>">
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description"><?= htmlspecialchars($description) ?></textarea>
            </div>

            <div class="form-group">
                <label for="tags">Tags</label>
                <input type="text" id="tags" name="tags" value="<?= htmlspecialchars($tags) ?>">
                <div class="help-text">Comma-separated tags</div>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" id="private" name="private" style="margin-right: 8px; width: auto;" <?= ($isEdit && !empty($existingBookmark['private'])) ? 'checked' : '' ?>>
                    <span>Private (hidden from RSS feed and recent bookmarks)</span>
                </label>
            </div>

            <div class="buttons">
                <button type="submit" name="submit" class="btn-primary">Save Bookmark</button>
                <button type="button" onclick="window.close()" class="btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</body>
</html>
