<?php
/**
 * Import bookmarks from Pinboard/Delicious format (Netscape Bookmark File Format)
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found. Please copy config-example.php to config.php and adjust settings.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/nav.php';

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

$message = '';
$error = '';
$stats = null;

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    // Validate CSRF token
    csrf_require_valid_token();

    if ($_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $tmpFile = $_FILES['import_file']['tmp_name'];
        $content = file_get_contents($tmpFile);

        // Parse the Netscape Bookmark file format
        $result = importBookmarks($db, $content);

        if ($result['success']) {
            $stats = $result;
            $message = "Import completed successfully! Added {$result['added']} bookmarks, skipped {$result['skipped']} duplicates.";
        } else {
            $error = $result['error'];
        }
    } else {
        $error = 'File upload failed. Please try again.';
    }
}

function importBookmarks($db, $content)
{
    $added = 0;
    $skipped = 0;
    $errors = [];

    // Parse using DOMDocument for more robust parsing
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();

    // Preprocess content to handle common issues
    $content = preg_replace('/<\?xml[^>]*>/', '', $content); // Remove XML declaration if present

    if (!$dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
        return ['success' => false, 'error' => 'Failed to parse HTML file'];
    }

    libxml_clear_errors();

    // Find all anchor tags
    $anchors = $dom->getElementsByTagName('a');

    $now = date('Y-m-d H:i:s');

    foreach ($anchors as $anchor) {
        if (!($anchor instanceof DOMElement)) {
            continue;
        }

        $url = trim($anchor->getAttribute('HREF'));
        if (empty($url)) {
            $url = trim($anchor->getAttribute('href'));
        }

        if (empty($url)) {
            continue;
        }

        // Extract title
        $title = trim($anchor->textContent);
        if (empty($title)) {
            $title = $url;
        }

        // Extract attributes
        $addDate = $anchor->getAttribute('ADD_DATE');
        $private = $anchor->getAttribute('PRIVATE');
        $tags = $anchor->getAttribute('TAGS');

        // Extract description (next DD element)
        $description = '';
        $nextSibling = $anchor->parentNode->nextSibling;
        while ($nextSibling) {
            if ($nextSibling->nodeType === XML_ELEMENT_NODE && strtoupper($nextSibling->nodeName) === 'DD') {
                $description = trim($nextSibling->textContent);
                break;
            }
            $nextSibling = $nextSibling->nextSibling;
        }

        // Convert add date from Unix timestamp to datetime
        $createdAt = $now;
        if (!empty($addDate) && is_numeric($addDate)) {
            $createdAt = date('Y-m-d H:i:s', intval($addDate));
        }

        // Convert private flag
        $isPrivate = ($private === '1') ? 1 : 0;

        // Check if bookmark already exists
        $stmt = $db->prepare("SELECT id FROM bookmarks WHERE url = ?");
        $stmt->execute([$url]);
        if ($stmt->fetch()) {
            $skipped++;
            continue;
        }

        // Insert bookmark
        try {
            $stmt = $db->prepare("
                INSERT INTO bookmarks (url, title, description, tags, private, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $url,
                $title,
                $description,
                $tags,
                $isPrivate,
                $createdAt,
                $now
            ]);

            $bookmarkId = $db->lastInsertId();

            // Queue background jobs for thumbnail and archive
            // Check if jobs table exists
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'")->fetchAll();
            if (!empty($tables)) {
                // Queue archive job
                $stmt = $db->prepare("
                    INSERT INTO jobs (bookmark_id, job_type, payload, created_at, updated_at)
                    VALUES (?, 'archive', ?, ?, ?)
                ");
                $stmt->execute([$bookmarkId, $url, $now, $now]);

                // Queue thumbnail job
                $stmt = $db->prepare("
                    INSERT INTO jobs (bookmark_id, job_type, payload, created_at, updated_at)
                    VALUES (?, 'thumbnail', ?, ?, ?)
                ");
                $stmt->execute([$bookmarkId, $url, $now, $now]);
            }

            $added++;
        } catch (PDOException $e) {
            $errors[] = "Failed to import: {$url} - " . $e->getMessage();
        }
    }

    return [
        'success' => true,
        'added' => $added,
        'skipped' => $skipped,
        'errors' => $errors
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Bookmarks - <?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">

</head>

<body>
    <?php render_nav($config, is_logged_in(), 'import', 'Import Bookmarks'); ?>

    <div class="page-container">
        <div class="content">
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php if ($stats && !empty($stats['errors'])): ?>
                    <div class="import-stats">
                        <h3>Errors during import:</h3>
                        <ul>
                            <?php foreach ($stats['errors'] as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="info-box">
                <h3>About Import</h3>
                <p>This tool imports bookmarks from the Netscape Bookmark File Format, which is used by:</p>
                <ul>
                    <li><strong>Pinboard</strong> - Export from <a href="https://pinboard.in/export/"
                            target="_blank">pinboard.in/export/</a></li>
                    <li><strong>Delicious</strong> - Export in HTML format</li>
                    <li><strong>Most browsers</strong> - Firefox, Chrome, Safari, Edge bookmark exports</li>
                    <li><strong>Other bookmarking services</strong> - Most services support this standard format</li>
                </ul>
                <p><strong>Note:</strong> Duplicate URLs will be skipped automatically.</p>
            </div>

            <form method="post" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label for="import_file">Select Bookmark File:</label>
                    <input type="file" id="import_file" name="import_file" accept=".html,.htm" required>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Import Bookmarks</button>
                    <a href="<?= $config['base_path'] ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <?php render_nav_scripts(); ?>
</body>

</html>