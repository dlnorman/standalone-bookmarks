<?php
/**
 * Main interface for bookmarks application
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found. Please copy config-example.php to config.php and adjust settings.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/markdown.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/nav.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Check if user is authenticated (but don't force login)
// Note: session_start() is already called in auth.php
$isLoggedIn = is_logged_in();

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed. Run init_db.php first.');
}

// Get parameters
$search = $_GET['q'] ?? '';
$tag = $_GET['tag'] ?? '';
$showBroken = isset($_GET['broken']) && $_GET['broken'] === '1';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = $config['items_per_page'];
$offset = ($page - 1) * $limit;

// Check if broken_url column exists
$columns = $db->query("PRAGMA table_info(bookmarks)")->fetchAll(PDO::FETCH_ASSOC);
$hasBrokenUrl = false;
foreach ($columns as $column) {
    if ($column['name'] === 'broken_url') {
        $hasBrokenUrl = true;
        break;
    }
}

// Fetch bookmarks
if ($showBroken && $hasBrokenUrl) {
    // Filter by broken URLs
    if ($isLoggedIn) {
        $stmt = $db->prepare("
            SELECT * FROM bookmarks
            WHERE broken_url = 1
            ORDER BY last_checked DESC, created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);

        $countStmt = $db->query("SELECT COUNT(*) as total FROM bookmarks WHERE broken_url = 1");
    } else {
        $stmt = $db->prepare("
            SELECT * FROM bookmarks
            WHERE broken_url = 1 AND private = 0
            ORDER BY last_checked DESC, created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);

        $countStmt = $db->query("SELECT COUNT(*) as total FROM bookmarks WHERE broken_url = 1 AND private = 0");
    }
} elseif (!empty($tag)) {
    // Filter by tag (case-insensitive)
    $tagPattern = '%' . strtolower($tag) . '%';

    if ($isLoggedIn) {
        $stmt = $db->prepare("
            SELECT * FROM bookmarks
            WHERE LOWER(tags) LIKE ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$tagPattern, $limit, $offset]);

        $countStmt = $db->prepare("
            SELECT COUNT(*) as total FROM bookmarks
            WHERE LOWER(tags) LIKE ?
        ");
        $countStmt->execute([$tagPattern]);
    } else {
        $stmt = $db->prepare("
            SELECT * FROM bookmarks
            WHERE LOWER(tags) LIKE ? AND private = 0
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$tagPattern, $limit, $offset]);

        $countStmt = $db->prepare("
            SELECT COUNT(*) as total FROM bookmarks
            WHERE LOWER(tags) LIKE ? AND private = 0
        ");
        $countStmt->execute([$tagPattern]);
    }
} elseif (!empty($search)) {
    $searchTerm = '%' . $search . '%';

    if ($isLoggedIn) {
        $stmt = $db->prepare("
            SELECT * FROM bookmarks
            WHERE title LIKE ? OR description LIKE ? OR tags LIKE ? OR url LIKE ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit, $offset]);

        $countStmt = $db->prepare("
            SELECT COUNT(*) as total FROM bookmarks
            WHERE title LIKE ? OR description LIKE ? OR tags LIKE ? OR url LIKE ?
        ");
        $countStmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    } else {
        $stmt = $db->prepare("
            SELECT * FROM bookmarks
            WHERE (title LIKE ? OR description LIKE ? OR tags LIKE ? OR url LIKE ?) AND private = 0
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit, $offset]);

        $countStmt = $db->prepare("
            SELECT COUNT(*) as total FROM bookmarks
            WHERE (title LIKE ? OR description LIKE ? OR tags LIKE ? OR url LIKE ?) AND private = 0
        ");
        $countStmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
} else {
    if ($isLoggedIn) {
        $stmt = $db->prepare("
            SELECT * FROM bookmarks
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);

        $countStmt = $db->query("SELECT COUNT(*) as total FROM bookmarks");
    } else {
        $stmt = $db->prepare("
            SELECT * FROM bookmarks
            WHERE private = 0
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);

        $countStmt = $db->query("SELECT COUNT(*) as total FROM bookmarks WHERE private = 0");
    }
}

$bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($total / $limit);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">
</head>

<body>
    <?php render_nav($config, $isLoggedIn, 'index'); ?>

    <div class="page-container">
        <div class="search-header">
            <form method="get" action="" class="search-form">
                <input type="text" name="q" placeholder="Search bookmarks..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit">Search</button>
            </form>

            <?php if (!empty($search)): ?>
                <div class="filter-notice">
                    Searching for: <strong><?= htmlspecialchars($search) ?></strong>
                    <a href="<?= $config['base_path'] ?>" class="btn">Clear</a>
                </div>
            <?php endif; ?>

            <?php if (!empty($tag)): ?>
                <div class="filter-notice">
                    Showing bookmarks tagged with: <strong><?= htmlspecialchars($tag) ?></strong>
                    <a href="<?= $config['base_path'] ?>" class="btn">Clear</a>
                </div>
            <?php endif; ?>

            <?php if ($showBroken): ?>
                <div class="filter-notice" style="background: #fee; border-left: 3px solid #e74c3c;">
                    Showing <strong style="color: #e74c3c;">broken links only</strong>
                    <a href="<?= $config['base_path'] ?>" class="btn">Show All</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (empty($bookmarks)): ?>
            <div class="no-results">
                <?php if (!empty($search)): ?>
                    <p>No bookmarks found for "<?= htmlspecialchars($search) ?>"</p>
                <?php elseif ($showBroken): ?>
                    <p>🎉 No broken links found! All your bookmarks are working.</p>
                <?php elseif (!empty($tag)): ?>
                    <p>No bookmarks found with tag "<?= htmlspecialchars($tag) ?>"</p>
                <?php else: ?>
                    <p>No bookmarks yet. Add your first bookmark!</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($bookmarks as $bookmark): ?>
                <div class="bookmark<?= !empty($bookmark['private']) ? ' private' : '' ?><?= isset($bookmark['broken_url']) && !empty($bookmark['broken_url']) ? ' broken' : '' ?>"
                    id="bookmark-<?= $bookmark['id'] ?>">

                    <!-- Screenshot Container (floats right) -->
                    <?php if (!empty($bookmark['screenshot'])): ?>
                        <div class="bookmark-screenshot-container">
                            <a href="<?= htmlspecialchars($bookmark['url']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="<?= $config['base_path'] . '/' . htmlspecialchars($bookmark['screenshot']) ?>"
                                    alt="Screenshot" title="Click to open bookmark">
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="bookmark-screenshot-container">
                            <div class="bookmark-screenshot-placeholder">
                                <?= strtoupper(substr($bookmark['title'], 0, 1)) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Content (wraps around screenshot) -->
                    <div class="bookmark-content">
                        <div class="bookmark-header">
                            <h2>
                                <a href="<?= htmlspecialchars($bookmark['url']) ?>" target="_blank"
                                    rel="noopener noreferrer"><?= htmlspecialchars($bookmark['title']) ?></a>
                                <?php if (!empty($bookmark['private'])): ?>
                                    <span class="private-badge">PRIVATE</span>
                                <?php endif; ?>
                                <?php if (isset($bookmark['broken_url']) && !empty($bookmark['broken_url'])): ?>
                                    <span class="broken-badge"
                                        title="This URL appears to be broken. Last checked: <?= !empty($bookmark['last_checked']) ? date($config['date_format'], strtotime($bookmark['last_checked'])) : 'N/A' ?>">BROKEN</span>
                                <?php endif; ?>
                            </h2>
                            <div class="url"><?= htmlspecialchars($bookmark['url']) ?></div>
                        </div>

                        <?php if (!empty($bookmark['description'])): ?>
                            <div class="description"><?= parseMarkdown($bookmark['description']) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($bookmark['tags'])): ?>
                            <div class="bookmark-tags">
                                <?php
                                $tagList = array_map('trim', explode(',', $bookmark['tags']));
                                foreach ($tagList as $tagItem) {
                                    echo '<a href="?tag=' . urlencode($tagItem) . '" class="bookmark-tag">' . htmlspecialchars($tagItem) . '</a>';
                                }
                                ?>
                            </div>
                        <?php endif; ?>

                        <div class="meta">
                            <div class="bookmark-meta-left">
                                <span class="bookmark-meta-item">
                                    <?= date($config['date_format'], strtotime($bookmark['created_at'])) ?>
                                </span>
                                <?php if (!empty($bookmark['archive_url'])): ?>
                                    <span class="bookmark-meta-item">
                                        <a href="<?= htmlspecialchars($bookmark['archive_url']) ?>" target="_blank"
                                            rel="noopener noreferrer" class="bookmark-archive-link">📦 Archive</a>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($isLoggedIn): ?>
                                <div class="actions">
                                    <a href="#" onclick="editBookmark(<?= $bookmark['id'] ?>); return false;">Edit</a>
                                    <a href="#" onclick="deleteBookmark(<?= $bookmark['id'] ?>); return false;">Delete</a>
                                    <a href="#" onclick="regenerateScreenshot(<?= $bookmark['id'] ?>); return false;">Regenerate
                                        Screenshot</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $paginationParams = ['q' => $search, 'tag' => $tag];
                    if ($showBroken) {
                        $paginationParams['broken'] = '1';
                    }
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $page - 1])) ?>">Previous</a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i == $page): ?>
                            <span><?= $i ?></span>
                        <?php else: ?>
                            <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $i])) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $page + 1])) ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php render_nav_scripts(); ?>
    <script>
        const BASE_PATH = <?= json_encode($config['base_path']) ?>;
        const IS_LOGGED_IN = <?= json_encode($isLoggedIn) ?>;
        const CSRF_TOKEN = <?= json_encode(csrf_get_token()) ?>;

        function editBookmark(id) {
            if (!IS_LOGGED_IN) return;
            // Close any other open edit forms
            const existingForms = document.querySelectorAll('.edit-form');
            existingForms.forEach(form => form.remove());

            const bookmarkElement = document.getElementById('bookmark-' + id);

            fetch(BASE_PATH + '/api.php?action=get&id=' + id)
                .then(r => r.json())
                .then(bookmark => {
                    const formHtml = `
                    <div class="edit-form">
                        <div class="form-group">
                            <label for="edit-url-${id}">URL</label>
                            <input type="text" id="edit-url-${id}" value="${escapeHtml(bookmark.url)}" required>
                        </div>
                        <div class="form-group">
                            <label for="edit-title-${id}">Title</label>
                            <input type="text" id="edit-title-${id}" value="${escapeHtml(bookmark.title)}" required>
                        </div>
                        <div class="form-group">
                            <label for="edit-description-${id}">Description</label>
                            <textarea id="edit-description-${id}">${escapeHtml(bookmark.description || '')}</textarea>
                        </div>
                        <div class="form-group tag-autocomplete">
                            <label for="edit-tags-${id}">Tags (comma-separated)</label>
                            <input type="text" id="edit-tags-${id}" value="${escapeHtml(bookmark.tags || '')}" autocomplete="off">
                            <div class="tag-suggestions" id="edit-tags-suggestions-${id}"></div>
                        </div>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="edit-private-${id}" ${bookmark.private ? 'checked' : ''}>
                                <label for="edit-private-${id}">Private (hidden from RSS feed and recent bookmarks)</label>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button class="btn-save" onclick="saveBookmark(${id}); return false;">Save Changes</button>
                            <button class="btn-cancel" onclick="cancelEdit(${id}); return false;">Cancel</button>
                        </div>
                    </div>
                `;

                    bookmarkElement.insertAdjacentHTML('beforeend', formHtml);

                    // Initialize tag autocomplete after the form is inserted
                    initTagAutocomplete(`edit-tags-${id}`, `edit-tags-suggestions-${id}`);
                })
                .catch(err => alert('Error loading bookmark: ' + err));
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function cancelEdit(id) {
            const form = document.querySelector(`#bookmark-${id} .edit-form`);
            if (form) {
                form.remove();
            }
        }

        function saveBookmark(id) {
            const url = document.getElementById(`edit-url-${id}`).value.trim();
            const title = document.getElementById(`edit-title-${id}`).value.trim();
            const description = document.getElementById(`edit-description-${id}`).value.trim();
            const tags = document.getElementById(`edit-tags-${id}`).value.trim();
            const isPrivate = document.getElementById(`edit-private-${id}`).checked;

            if (!url || !title) {
                alert('URL and title are required!');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'edit');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('id', id);
            formData.append('url', url);
            formData.append('title', title);
            formData.append('description', description);
            formData.append('tags', tags);
            formData.append('private', isPrivate ? '1' : '0');

            fetch(BASE_PATH + '/api.php', {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(err => alert('Error: ' + err));
        }

        function deleteBookmark(id) {
            if (!IS_LOGGED_IN) return;
            if (!confirm('Are you sure you want to delete this bookmark?')) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('id', id);

            fetch(BASE_PATH + '/api.php', {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('bookmark-' + id).remove();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(err => alert('Error: ' + err));
        }

        function regenerateScreenshot(id) {
            if (!IS_LOGGED_IN) return;
            if (!confirm('Regenerate screenshot using PageSpeed API? This may take 10-30 seconds.')) return;

            const bookmarkEl = document.getElementById('bookmark-' + id);
            const screenshotDiv = bookmarkEl.querySelector('.screenshot');

            // Show loading indicator
            const originalContent = screenshotDiv ? screenshotDiv.innerHTML : null;
            if (screenshotDiv) {
                screenshotDiv.innerHTML = '<div style="padding: 20px; text-align: center; color: #666;">Generating screenshot...</div>';
            }

            const formData = new FormData();
            formData.append('bookmark_id', id);

            fetch(BASE_PATH + '/regenerate-screenshot.php', {
                method: 'POST',
                body: formData
            })
                .then(r => {
                    // Log response for debugging
                    console.log('Response status:', r.status);
                    console.log('Response headers:', r.headers.get('content-type'));

                    // Get text first to see what we're actually receiving
                    return r.text().then(text => {
                        console.log('Response text:', text);

                        // Try to parse as JSON
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('JSON parse error:', e);
                            console.error('First 200 chars:', text.substring(0, 200));
                            throw new Error('Invalid JSON response: ' + text.substring(0, 100));
                        }
                    });
                })
                .then(data => {
                    if (data.success) {
                        // Reload the page to show the new screenshot
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                        if (screenshotDiv && originalContent) {
                            screenshotDiv.innerHTML = originalContent;
                        }
                    }
                })
                .catch(err => {
                    alert('Error: ' + err);
                    if (screenshotDiv && originalContent) {
                        screenshotDiv.innerHTML = originalContent;
                    }
                });
        }

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
    </script>
</body>

</html>