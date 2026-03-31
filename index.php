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
require_once __DIR__ . '/includes/tags.php';
require_once __DIR__ . '/includes/security.php';

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
    $db->exec("PRAGMA cache_size = -8000"); // 8MB page cache
    $db->exec("PRAGMA temp_store = MEMORY");
} catch (PDOException $e) {
    die('Database connection failed. Run init_db.php first.');
}

// Rate-limit anonymous visitors before doing any real work
if (!$isLoggedIn) {
    enforce_page_rate_limit($db);
}

// Get parameters
$search = $_GET['q'] ?? '';
$tag = $_GET['tag'] ?? '';
$activeTags = !empty($tag) ? array_values(array_filter(array_map('trim', explode(',', $tag)))) : [];
$showBroken = isset($_GET['broken']) && $_GET['broken'] === '1';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = $config['items_per_page'];
$offset = ($page - 1) * $limit;

// broken_url column is part of the schema (added in db_setup.php)
$hasBrokenUrl = true;

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
} elseif (!empty($activeTags)) {
    // Filter by tags (comma-separated = AND logic, case-insensitive)
    // Each tag is expanded to its alias group; results matching any form count as a hit.
    $tagConditions = [];
    $tagParams = [];
    foreach ($activeTags as $activeTag) {
        $group = expandTagGroup($db, strtolower($activeTag));
        $likeClauses = [];
        foreach ($group as $t) {
            $likeClauses[] = "',' || REPLACE(LOWER(tags), ', ', ',') || ',' LIKE ? ESCAPE '\\'";
            $tagParams[] = '%,' . escapeLikePattern($t) . ',%';
        }
        $tagConditions[] = '(' . implode(' OR ', $likeClauses) . ')';
    }
    $tagWhere = implode(' AND ', $tagConditions);

    if ($isLoggedIn) {
        $stmt = $db->prepare("
            SELECT * FROM bookmarks
            WHERE $tagWhere
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($tagParams, [$limit, $offset]));

        $countStmt = $db->prepare("SELECT COUNT(*) as total FROM bookmarks WHERE $tagWhere");
        $countStmt->execute($tagParams);
    } else {
        $stmt = $db->prepare("
            SELECT * FROM bookmarks
            WHERE $tagWhere AND private = 0
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($tagParams, [$limit, $offset]));

        $countStmt = $db->prepare("SELECT COUNT(*) as total FROM bookmarks WHERE $tagWhere AND private = 0");
        $countStmt->execute($tagParams);
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

// Fetch related tags for the active tag filter (union connections across all active tags)
$relatedTags = [];
if (!empty($activeTags)) {
    $lowerActive = array_map('strtolower', $activeTags);
    foreach ($activeTags as $activeTag) {
        foreach (getTagConnections($db, $activeTag) as $related) {
            $lowerRelated = strtolower($related);
            if (!in_array($lowerRelated, $lowerActive) && !in_array($related, $relatedTags)) {
                $relatedTags[] = $related;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .tag-alias-hint {
            font-size: 0.82em;
            color: var(--text-secondary, #888);
            margin-top: 0.3em;
            padding: 0.3em 0.5em;
            background: var(--bg-popup, #f5f5f5);
            border-radius: 4px;
            border-left: 3px solid var(--accent-amber, #f0a500);
        }
        .tag-alias-hint a {
            color: var(--accent-amber, #f0a500);
            text-decoration: underline;
            cursor: pointer;
        }
        .tag-alias-hint a:hover {
            opacity: 0.8;
        }
    </style>
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

            <?php if (!empty($activeTags)): ?>
                <div class="filter-notice">
                    Showing bookmarks tagged:
                    <?php foreach ($activeTags as $activeTag):
                        $removeUrl = count($activeTags) === 1
                            ? $config['base_path'] . '/'
                            : $config['base_path'] . '/?tag=' . urlencode(implode(',', array_filter($activeTags, fn($t) => strtolower(trim($t)) !== strtolower(trim($activeTag)))));
                    ?>
                        <span class="filter-tag-chip">
                            <?= htmlspecialchars($activeTag) ?>
                            <a href="<?= $removeUrl ?>" class="filter-tag-remove" title="Remove tag">×</a>
                        </span>
                    <?php endforeach; ?>
                    <a href="<?= $config['base_path'] ?>" class="btn">Clear all</a>
                </div>
                <?php if (!empty($relatedTags)): ?>
                <div class="related-tags-bar">
                    <span class="related-tags-label">Related:</span>
                    <?php foreach ($relatedTags as $relatedTag): ?>
                        <a href="<?= $config['base_path'] ?>/?tag=<?= urlencode(implode(',', array_merge($activeTags, [$relatedTag]))) ?>" class="related-tag-chip"><?= htmlspecialchars($relatedTag) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
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
                <?php elseif (!empty($activeTags)): ?>
                    <p>No bookmarks found tagged with <?= implode(' + ', array_map(fn($t) => '"' . htmlspecialchars($t) . '"', $activeTags)) ?></p>
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
                                    echo renderTag($tagItem, $config['base_path'], 'bookmark-tag', $activeTags);
                                }
                                ?>
                            </div>
                        <?php endif; ?>

                        <div class="meta">
                            <div class="bookmark-meta-left">
                                <span class="bookmark-meta-item">
                                    <?= date($config['date_format'], strtotime($bookmark['created_at'])) ?>
                                </span>
                                <?php if (!empty($bookmark['archive_url']) && !$isLoggedIn): ?>
                                    <span class="bookmark-meta-item">
                                        <a href="<?= htmlspecialchars($bookmark['archive_url']) ?>" target="_blank"
                                            rel="noopener noreferrer" class="bookmark-archive-link">📦 Archive</a>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($isLoggedIn): ?>
                                <div class="bookmark-actions-menu">
                                    <button class="bookmark-actions-trigger" onclick="toggleBookmarkMenu(event)" aria-haspopup="true" aria-expanded="false">⋯</button>
                                    <div class="bookmark-actions-dropdown">
                                        <a href="#" class="dropdown-item bookmark-action-danger" onclick="deleteBookmark(<?= $bookmark['id'] ?>); return false;">Delete</a>
                                        <div class="dropdown-divider"></div>
                                        <?php if (!empty($bookmark['screenshot'])): ?>
                                            <a href="#" class="dropdown-item" onclick="deleteScreenshot(<?= $bookmark['id'] ?>); return false;">Delete Screenshot</a>
                                        <?php endif; ?>
                                        <a href="#" class="dropdown-item" onclick="regenerateScreenshot(<?= $bookmark['id'] ?>); return false;">Regenerate Screenshot</a>
                                        <div class="dropdown-divider"></div>
                                        <?php if (!empty($bookmark['archive_url'])): ?>
                                            <a href="<?= htmlspecialchars($bookmark['archive_url']) ?>" target="_blank" rel="noopener noreferrer" class="dropdown-item">📦 Archive</a>
                                        <?php endif; ?>
                                        <a href="#" class="dropdown-item" onclick="editBookmark(<?= $bookmark['id'] ?>); return false;">Edit</a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $paginationParams = ['q' => $search, 'tag' => implode(',', $activeTags)];
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
        const CSRF_TOKEN = <?= $isLoggedIn ? json_encode(csrf_get_token()) : 'null' ?>;
        const DATE_FORMAT = <?= json_encode($config['date_format']) ?>;
        const CURRENT_TAGS = <?= json_encode($activeTags) ?>;
        const CURRENT_BROKEN = <?= json_encode($showBroken) ?>;

        // Tag type helper functions for JavaScript
        function parseTagTypeJS(tag) {
            tag = tag.trim();
            if (tag.startsWith('person:')) {
                return { type: 'person', name: tag.substring(7) };
            } else if (tag.startsWith('via:')) {
                return { type: 'via', name: tag.substring(4) };
            }
            return { type: 'tag', name: tag };
        }

        function getTagTypeClassJS(type) {
            switch (type) {
                case 'person': return 'tag-person';
                case 'via': return 'tag-via';
                default: return '';
            }
        }

        function getTagTypeIconJS(type) {
            switch (type) {
                case 'person': return '<span class="tag-icon">&#128100;</span>';
                case 'via': return '<span class="tag-icon">&#128228;</span>';
                default: return '';
            }
        }

        // Build a URL that toggles a tag in/out of the current active tag set
        function buildTagUrl(tag) {
            const lowerTag = tag.trim().toLowerCase();
            const lowerActive = CURRENT_TAGS.map(t => t.toLowerCase());
            let newTags;
            if (lowerActive.includes(lowerTag)) {
                newTags = CURRENT_TAGS.filter(t => t.toLowerCase() !== lowerTag);
            } else {
                newTags = [...CURRENT_TAGS, tag.trim()];
            }
            if (newTags.length === 0) return BASE_PATH + '/';
            return BASE_PATH + '/?tag=' + encodeURIComponent(newTags.join(','));
        }

        function toggleBookmarkMenu(event) {
            event.stopPropagation();
            const menu = event.currentTarget.closest('.bookmark-actions-menu');
            const dropdown = menu.querySelector('.bookmark-actions-dropdown');
            const trigger = menu.querySelector('.bookmark-actions-trigger');
            const isOpen = dropdown.classList.contains('active');

            // Close all other open menus
            document.querySelectorAll('.bookmark-actions-dropdown.active').forEach(d => {
                d.classList.remove('active');
                d.closest('.bookmark-actions-menu').querySelector('.bookmark-actions-trigger').setAttribute('aria-expanded', 'false');
            });

            if (!isOpen) {
                dropdown.classList.add('active');
                trigger.setAttribute('aria-expanded', 'true');
            }
        }

        // Close bookmark action menus when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.bookmark-actions-menu')) {
                document.querySelectorAll('.bookmark-actions-dropdown.active').forEach(d => {
                    d.classList.remove('active');
                    d.closest('.bookmark-actions-menu').querySelector('.bookmark-actions-trigger').setAttribute('aria-expanded', 'false');
                });
            }
        });

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
                            <div class="tag-alias-hint" id="edit-tags-alias-hint-${id}" hidden></div>
                        </div>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="edit-private-${id}" ${bookmark.private == 1 ? 'checked' : ''}>
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
                    initTagAutocomplete(`edit-tags-${id}`, `edit-tags-suggestions-${id}`, `edit-tags-alias-hint-${id}`);
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

        function deleteScreenshot(id) {
            if (!IS_LOGGED_IN) return;
            if (!confirm('Delete the screenshot for this bookmark? It will revert to showing a placeholder.')) return;

            const formData = new FormData();
            formData.append('bookmark_id', id);

            fetch(BASE_PATH + '/delete-screenshot.php', {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Reload the page to show the placeholder
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(err => alert('Error: ' + err));
        }

        // Tag autocomplete functionality
        let allTagsCache = null;
        let allAliasesCache = null;

        async function fetchAllTags() {
            if (allTagsCache) {
                return allTagsCache;
            }

            try {
                const response = await fetch(BASE_PATH + '/api.php?action=get_tags');
                const data = await response.json();
                allTagsCache = data.tags ?? data;
                allAliasesCache = data.aliases ?? {};
                return allTagsCache;
            } catch (err) {
                console.error('Error fetching tags:', err);
                allTagsCache = [];
                allAliasesCache = {};
                return allTagsCache;
            }
        }

        async function fetchAliases() {
            if (allAliasesCache !== null) return allAliasesCache;
            await fetchAllTags();
            return allAliasesCache;
        }

        function initTagAutocomplete(inputId, suggestionsId, aliasHintId) {
            const input = document.getElementById(inputId);
            const suggestionsDiv = document.getElementById(suggestionsId);
            const aliasHint = aliasHintId ? document.getElementById(aliasHintId) : null;

            if (!input || !suggestionsDiv) return;

            let selectedIndex = -1;
            let isInserting = false;

            function closeSuggestions() {
                suggestionsDiv.innerHTML = '';
                suggestionsDiv.classList.remove('active');
                selectedIndex = -1;
            }

            function dismissAliasHint() {
                if (aliasHint) {
                    aliasHint.hidden = true;
                    aliasHint.innerHTML = '';
                }
            }

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            async function checkAliasHint(committedTag) {
                if (!aliasHint) return;
                const aliases = await fetchAliases();
                const key = committedTag.toLowerCase();
                if (!aliases[key]) { dismissAliasHint(); return; }
                const canonical = aliases[key];
                aliasHint.innerHTML =
                    `\u201c<em>${escapeHtml(committedTag)}</em>\u201d is an alias for \u201c<strong>${escapeHtml(canonical)}</strong>\u201d \u2014 ` +
                    `<a href="#" class="tag-alias-accept">use canonical instead</a>`;
                aliasHint.hidden = false;
                aliasHint.querySelector('.tag-alias-accept').addEventListener('click', function(e) {
                    e.preventDefault();
                    // Replace the alias token with the canonical form
                    const parts = input.value.split(',').map(t => t.trim());
                    const replaced = parts.map(t => t.toLowerCase() === key ? canonical : t);
                    input.value = replaced.join(', ');
                    dismissAliasHint();
                    input.focus();
                });
            }

            input.addEventListener('input', async function () {
                if (isInserting) return;

                const value = this.value;
                const cursorPos = this.selectionStart;

                // Find the current tag being typed (between commas)
                const beforeCursor = value.substring(0, cursorPos);
                const afterCursor = value.substring(cursorPos);
                const lastComma = beforeCursor.lastIndexOf(',');
                const nextComma = afterCursor.indexOf(',');

                const currentTag = beforeCursor.substring(lastComma + 1).trim();

                // If the user just typed a comma, check the token they just finished
                if (currentTag.length === 0 && lastComma >= 0) {
                    const finishedToken = value.substring(0, lastComma).split(',').pop().trim();
                    if (finishedToken) checkAliasHint(finishedToken);
                    closeSuggestions();
                    return;
                }

                // Dismiss hint while user is actively typing a new token
                dismissAliasHint();

                if (currentTag.length === 0) {
                    closeSuggestions();
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
                    closeSuggestions();
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

                const nextCommaPos = nextComma === -1 ? value.length : cursorPos + nextComma;

                // Use mousedown (fires before blur) so clicking doesn't dismiss the dropdown first.
                // touchend handles iOS where mousedown may not fire reliably.
                suggestionsDiv.querySelectorAll('.tag-suggestion').forEach(suggestionEl => {
                    function selectSuggestion(e) {
                        e.preventDefault();
                        const tag = this.getAttribute('data-tag');
                        isInserting = true;
                        insertTag(input, tag, lastComma, cursorPos, nextCommaPos);
                        closeSuggestions();
                        setTimeout(() => { isInserting = false; }, 50);
                    }
                    suggestionEl.addEventListener('mousedown', selectSuggestion);
                    suggestionEl.addEventListener('touchend', selectSuggestion);
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
                        suggestions[selectedIndex].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                    } else {
                        closeSuggestions();
                    }
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    closeSuggestions();
                }
            });

            input.addEventListener('blur', function () {
                setTimeout(() => closeSuggestions(), 150);
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
                // Skip past the comma at nextCommaPos (if any) to avoid doubling the separator
                const afterTag = nextCommaPos < value.length
                    ? value.substring(nextCommaPos + 1).trimStart()
                    : '';

                input.value = beforeTag + tag + (afterTag.length > 0 ? ', ' : '') + afterTag;
                const newCursorPos = beforeTag.length + tag.length + (afterTag.length > 0 ? 2 : 0);
                input.setSelectionRange(newCursorPos, newCursorPos);
                input.focus();
                // Check if the inserted tag is a known alias
                checkAliasHint(tag);
            }
        }

        // Instant search functionality
        let searchTimeout;
        let isSearching = false;
        const searchInput = document.querySelector('input[name="q"]');
        const searchForm = document.querySelector('.search-form');
        const pageContainer = document.querySelector('.page-container');

        if (searchInput) {
            // Prevent default form submission and use instant search instead
            searchForm.addEventListener('submit', function(e) {
                e.preventDefault();
                performSearch(searchInput.value.trim(), 1);
            });

            // Debounced instant search on input
            searchInput.addEventListener('input', function() {
                const query = this.value.trim();

                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    performSearch(query, 1);
                }, 300); // 300ms debounce
            });
        }

        function performSearch(query, page = 1) {
            if (isSearching) return;

            isSearching = true;
            showLoadingState();

            // Build URL with current filters
            const params = new URLSearchParams({
                action: 'list',
                q: query,
                page: page,
                limit: <?= $limit ?>
            });

            if (CURRENT_TAGS.length > 0) {
                params.set('tag', CURRENT_TAGS.join(','));
            }

            if (CURRENT_BROKEN) {
                params.set('broken', '1');
            }

            fetch(BASE_PATH + '/api.php?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    renderBookmarks(data, query);
                    isSearching = false;
                })
                .catch(err => {
                    console.error('Search error:', err);
                    isSearching = false;
                    hideLoadingState();
                });
        }

        function showLoadingState() {
            // Add loading indicator to search input
            searchInput.style.backgroundImage = 'linear-gradient(90deg, #3498db 0%, #3498db 50%, transparent 50%)';
            searchInput.style.backgroundSize = '200% 2px';
            searchInput.style.backgroundPosition = '100% 100%';
            searchInput.style.backgroundRepeat = 'no-repeat';
            searchInput.style.animation = 'searchLoading 1s linear infinite';

            // Dim existing results
            const existingResults = pageContainer.querySelector('.search-results-container');
            if (existingResults) {
                existingResults.style.opacity = '0.5';
                existingResults.style.pointerEvents = 'none';
            }

            // Add loading animation styles if not already present
            if (!document.getElementById('searchLoadingStyles')) {
                const style = document.createElement('style');
                style.id = 'searchLoadingStyles';
                style.textContent = `
                    @keyframes searchLoading {
                        0% { background-position: 100% 100%; }
                        100% { background-position: -100% 100%; }
                    }
                `;
                document.head.appendChild(style);
            }
        }

        function hideLoadingState() {
            // Remove loading indicator
            searchInput.style.backgroundImage = '';
            searchInput.style.animation = '';

            // Restore results
            const existingResults = pageContainer.querySelector('.search-results-container');
            if (existingResults) {
                existingResults.style.opacity = '1';
                existingResults.style.pointerEvents = 'auto';
            }
        }

        function renderBookmarks(data, query) {
            // Remove existing results if any
            const existingResults = pageContainer.querySelector('.search-results-container');
            if (existingResults) {
                existingResults.remove();
            }

            // Create results container
            const resultsContainer = document.createElement('div');
            resultsContainer.className = 'search-results-container';

            if (data.bookmarks.length === 0) {
                resultsContainer.innerHTML = `
                    <div class="no-results">
                        <p>No bookmarks found${query ? ' for "' + escapeHtml(query) + '"' : ''}.</p>
                    </div>
                `;
            } else {
                // Render bookmarks
                data.bookmarks.forEach(bookmark => {
                    resultsContainer.appendChild(createBookmarkElement(bookmark));
                });

                // Add pagination if needed
                if (data.pages > 1) {
                    resultsContainer.appendChild(createPagination(data, query));
                }
            }

            // Insert after search header
            const searchHeader = pageContainer.querySelector('.search-header');
            searchHeader.after(resultsContainer);
        }

        function createBookmarkElement(bookmark) {
            const div = document.createElement('div');
            div.className = 'bookmark' + (bookmark.private ? ' private' : '') + (bookmark.broken_url ? ' broken' : '');
            div.id = 'bookmark-' + bookmark.id;

            // Screenshot/placeholder
            let screenshotHTML = '';
            if (bookmark.screenshot) {
                screenshotHTML = `
                    <div class="bookmark-screenshot-container">
                        <a href="${escapeHtml(bookmark.url)}" target="_blank" rel="noopener noreferrer">
                            <img src="${BASE_PATH}/${escapeHtml(bookmark.screenshot)}" alt="Screenshot" title="Click to open bookmark">
                        </a>
                    </div>
                `;
            } else {
                screenshotHTML = `
                    <div class="bookmark-screenshot-container">
                        <div class="bookmark-screenshot-placeholder">
                            ${bookmark.title.substring(0, 1).toUpperCase()}
                        </div>
                    </div>
                `;
            }

            // Tags
            let tagsHTML = '';
            if (bookmark.tags) {
                const tags = bookmark.tags.split(',').map(t => t.trim());
                tagsHTML = '<div class="bookmark-tags">';
                tags.forEach(tag => {
                    const parsed = parseTagTypeJS(tag);
                    const typeClass = getTagTypeClassJS(parsed.type);
                    const icon = getTagTypeIconJS(parsed.type);
                    const isActive = CURRENT_TAGS.map(t => t.toLowerCase()).includes(tag.toLowerCase());
                    const activeClass = isActive ? ' active-filter' : '';
                    tagsHTML += `<a href="${buildTagUrl(tag)}" class="bookmark-tag ${typeClass}${activeClass}">${icon}${escapeHtml(parsed.name)}</a>`;
                });
                tagsHTML += '</div>';
            }

            // Actions (only if logged in)
            let actionsHTML = '';
            if (IS_LOGGED_IN) {
                const archiveItem = bookmark.archive_url
                    ? `<a href="${escapeHtml(bookmark.archive_url)}" target="_blank" rel="noopener noreferrer" class="dropdown-item">📦 Archive</a>`
                    : '';
                const deleteScreenshotItem = bookmark.screenshot
                    ? `<a href="#" class="dropdown-item" onclick="deleteScreenshot(${bookmark.id}); return false;">Delete Screenshot</a>`
                    : '';
                actionsHTML = `
                    <div class="bookmark-actions-menu">
                        <button class="bookmark-actions-trigger" onclick="toggleBookmarkMenu(event)" aria-haspopup="true" aria-expanded="false">⋯</button>
                        <div class="bookmark-actions-dropdown">
                            <a href="#" class="dropdown-item bookmark-action-danger" onclick="deleteBookmark(${bookmark.id}); return false;">Delete</a>
                            <div class="dropdown-divider"></div>
                            ${deleteScreenshotItem}
                            <a href="#" class="dropdown-item" onclick="regenerateScreenshot(${bookmark.id}); return false;">Regenerate Screenshot</a>
                            <div class="dropdown-divider"></div>
                            ${archiveItem}
                            <a href="#" class="dropdown-item" onclick="editBookmark(${bookmark.id}); return false;">Edit</a>
                        </div>
                    </div>
                `;
            }

            // Badges
            let badgesHTML = '';
            if (bookmark.private) {
                badgesHTML += '<span class="private-badge">PRIVATE</span>';
            }
            if (bookmark.broken_url) {
                badgesHTML += '<span class="broken-badge" title="This URL appears to be broken">BROKEN</span>';
            }

            // Archive link (only shown in meta for logged-out users)
            let archiveHTML = '';
            if (bookmark.archive_url && !IS_LOGGED_IN) {
                archiveHTML = `
                    <span class="bookmark-meta-item">
                        <a href="${escapeHtml(bookmark.archive_url)}" target="_blank" rel="noopener noreferrer" class="bookmark-archive-link">📦 Archive</a>
                    </span>
                `;
            }

            div.innerHTML = `
                ${screenshotHTML}
                <div class="bookmark-content">
                    <div class="bookmark-header">
                        <h2>
                            <a href="${escapeHtml(bookmark.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(bookmark.title)}</a>
                            ${badgesHTML}
                        </h2>
                        <div class="url">${escapeHtml(bookmark.url)}</div>
                    </div>
                    ${bookmark.description_html ? '<div class="description">' + bookmark.description_html + '</div>' : ''}
                    ${tagsHTML}
                    <div class="meta">
                        <div class="bookmark-meta-left">
                            <span class="bookmark-meta-item">
                                ${formatDate(bookmark.created_at)}
                            </span>
                            ${archiveHTML}
                        </div>
                        ${actionsHTML}
                    </div>
                </div>
            `;

            return div;
        }

        function createPagination(data, query) {
            const paginationDiv = document.createElement('div');
            paginationDiv.className = 'pagination';

            if (data.page > 1) {
                const prevLink = document.createElement('a');
                prevLink.href = '#';
                prevLink.textContent = 'Previous';
                prevLink.onclick = (e) => {
                    e.preventDefault();
                    performSearch(query, data.page - 1);
                    window.scrollTo(0, 0);
                };
                paginationDiv.appendChild(prevLink);
            }

            // Page numbers
            for (let i = Math.max(1, data.page - 2); i <= Math.min(data.pages, data.page + 2); i++) {
                if (i === data.page) {
                    const span = document.createElement('span');
                    span.textContent = i;
                    paginationDiv.appendChild(span);
                } else {
                    const link = document.createElement('a');
                    link.href = '#';
                    link.textContent = i;
                    link.onclick = (e) => {
                        e.preventDefault();
                        performSearch(query, i);
                        window.scrollTo(0, 0);
                    };
                    paginationDiv.appendChild(link);
                }
            }

            if (data.page < data.pages) {
                const nextLink = document.createElement('a');
                nextLink.href = '#';
                nextLink.textContent = 'Next';
                nextLink.onclick = (e) => {
                    e.preventDefault();
                    performSearch(query, data.page + 1);
                    window.scrollTo(0, 0);
                };
                paginationDiv.appendChild(nextLink);
            }

            return paginationDiv;
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        // Initialize instant search on page load if there's existing content
        window.addEventListener('DOMContentLoaded', function() {
            // If there are bookmarks shown on the initial page load, hide them and prepare for instant search
            const existingBookmarks = document.querySelectorAll('.bookmark');
            const existingPagination = document.querySelector('.pagination');
            const noResults = document.querySelector('.no-results');

            if (existingBookmarks.length > 0 || noResults) {
                // Wrap existing content in results container for consistency
                const resultsContainer = document.createElement('div');
                resultsContainer.className = 'search-results-container';

                const searchHeader = pageContainer.querySelector('.search-header');
                let nextElement = searchHeader.nextElementSibling;

                while (nextElement) {
                    const currentElement = nextElement;
                    nextElement = nextElement.nextElementSibling;
                    resultsContainer.appendChild(currentElement);
                }

                searchHeader.after(resultsContainer);
            }
        });
    </script>
</body>

</html>