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
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/security.php';

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
    // Validate URL for SSRF protection
    if (!is_safe_url($url)) {
        $error = 'URL not allowed: Private/internal addresses are blocked for security';
        $url = '';
    } else {
        // Fetch metadata directly from the URL
        $result = safe_fetch_url($url, 10);

        if (!$result['success']) {
            // Failed to fetch - that's okay, user can still manually enter data
            $html = false;
        } else {
            $html = $result['content'];
        }
    }

    if (isset($html) && $html) {
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

    // Wrap description in blockquote format
    if (!empty($description)) {
        $description = '> ' . str_replace("\n", "\n> ", $description);
    }

    // Append selected text to description if provided
    if (!empty($selectedText)) {
        // Wrap selected text in blockquote format
        $wrappedSelectedText = '> ' . str_replace("\n", "\n> ", $selectedText);

        if (!empty($description)) {
            $description .= "\n\n---\n### Selected text\n\n" . $wrappedSelectedText;
        } else {
            $description = $wrappedSelectedText;
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    // Validate CSRF token
    csrf_require_valid_token();

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
    <link rel="stylesheet" href="css/main.css">
</head>

<body class="bookmarklet-popup">
    <div class="container">
        <h1><?= $isEdit ? 'Edit Bookmark' : 'Add Bookmark' ?></h1>

        <?php if (isset($success)): ?>
            <div class="success">
                Bookmark <?= $isEdit ? 'updated' : 'added' ?> successfully!
            </div>
            <script>
                setTimeout(function () {
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
            <?php csrf_field(); ?>
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

            <div class="form-group tag-autocomplete">
                <label for="tags">Tags</label>
                <input type="text" id="tags" name="tags" value="<?= htmlspecialchars($tags) ?>" autocomplete="off">
                <div class="tag-suggestions" id="tags-suggestions"></div>
                <div class="help-text">Comma-separated tags</div>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" id="private" name="private" style="margin-right: 8px; width: auto;"
                        <?= ($isEdit && !empty($existingBookmark['private'])) ? 'checked' : '' ?>>
                    <span>Private (hidden from RSS feed and recent bookmarks)</span>
                </label>
            </div>

            <div class="buttons">
                <button type="submit" name="submit" class="btn-primary">Save Bookmark</button>
                <button type="button" onclick="window.close()" class="btn-secondary">Cancel</button>
            </div>
        </form>
    </div>

    <script>
        const BASE_PATH = <?= json_encode($config['base_path']) ?>;

        // Tag autocomplete functionality
        let allTagsCache = null;

        async function fetchAllTags() {
            if (allTagsCache) {
                return allTagsCache;
            }

            try {
                const response = await fetch(BASE_PATH + '/api.php?action=get_tags');
                const data = await response.json();
                allTagsCache = data.tags || [];
                return allTagsCache;
            } catch (err) {
                console.error('Error fetching tags:', err);
                return [];
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function initTagAutocomplete(inputId, suggestionsId) {
            const input = document.getElementById(inputId);
            const suggestionsDiv = document.getElementById(suggestionsId);

            if (!input || !suggestionsDiv) return;

            let selectedIndex = -1;

            input.addEventListener('input', async function () {
                const value = this.value;
                const cursorPos = this.selectionStart;

                // Find the current tag being typed (between commas)
                const beforeCursor = value.substring(0, cursorPos);
                const afterCursor = value.substring(cursorPos);
                const lastComma = beforeCursor.lastIndexOf(',');
                const nextComma = afterCursor.indexOf(',');

                const currentTag = beforeCursor.substring(lastComma + 1).trim();

                if (currentTag.length === 0) {
                    suggestionsDiv.classList.remove('active');
                    return;
                }

                // Fetch all tags
                const allTags = await fetchAllTags();

                // Filter tags that start with the current input (case-insensitive)
                const matches = allTags.filter(tag =>
                    tag.toLowerCase().startsWith(currentTag.toLowerCase()) &&
                    tag.toLowerCase() !== currentTag.toLowerCase()
                );

                if (matches.length === 0) {
                    suggestionsDiv.classList.remove('active');
                    return;
                }

                // Display suggestions
                selectedIndex = -1;
                suggestionsDiv.innerHTML = matches.map((tag, index) => {
                    const matchLen = currentTag.length;
                    const highlighted = `<span class="tag-suggestion-match">${escapeHtml(tag.substring(0, matchLen))}</span>${escapeHtml(tag.substring(matchLen))}`;
                    return `<div class="tag-suggestion" data-index="${index}" data-tag="${escapeHtml(tag)}">${highlighted}</div>`;
                }).join('');

                suggestionsDiv.classList.add('active');

                // Add click handlers to suggestions
                suggestionsDiv.querySelectorAll('.tag-suggestion').forEach(suggestionEl => {
                    suggestionEl.addEventListener('click', function () {
                        const tag = this.getAttribute('data-tag');
                        insertTag(input, tag, lastComma, cursorPos, nextComma === -1 ? value.length : cursorPos + nextComma);
                        suggestionsDiv.classList.remove('active');
                    });
                });
            });

            input.addEventListener('keydown', function (e) {
                const suggestions = suggestionsDiv.querySelectorAll('.tag-suggestion');

                if (!suggestionsDiv.classList.contains('active') || suggestions.length === 0) {
                    return;
                }

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, suggestions.length - 1);
                    updateSelectedSuggestion(suggestions);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, -1);
                    updateSelectedSuggestion(suggestions);
                } else if (e.key === 'Enter' || e.key === 'Tab') {
                    if (selectedIndex >= 0) {
                        e.preventDefault();
                        suggestions[selectedIndex].click();
                    }
                } else if (e.key === 'Escape') {
                    suggestionsDiv.classList.remove('active');
                }
            });

            // Close suggestions when clicking outside
            document.addEventListener('click', function (e) {
                if (!input.contains(e.target) && !suggestionsDiv.contains(e.target)) {
                    suggestionsDiv.classList.remove('active');
                }
            });

            function updateSelectedSuggestion(suggestions) {
                suggestions.forEach((el, index) => {
                    if (index === selectedIndex) {
                        el.classList.add('selected');
                        el.scrollIntoView({ block: 'nearest' });
                    } else {
                        el.classList.remove('selected');
                    }
                });
            }

            function insertTag(input, tag, lastCommaPos, cursorPos, nextCommaPos) {
                const value = input.value;
                const beforeTag = value.substring(0, lastCommaPos + 1) + (lastCommaPos >= 0 ? ' ' : '');
                const afterTag = value.substring(nextCommaPos);

                input.value = beforeTag + tag + (afterTag.trim().length > 0 ? ', ' : '') + afterTag.trim();
                const newCursorPos = beforeTag.length + tag.length + (afterTag.trim().length > 0 ? 2 : 0);
                input.setSelectionRange(newCursorPos, newCursorPos);
                input.focus();
            }
        }

        // Initialize autocomplete when the page loads
        document.addEventListener('DOMContentLoaded', function () {
            initTagAutocomplete('tags', 'tags-suggestions');
        });
    </script>
</body>

</html>