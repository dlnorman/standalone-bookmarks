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
$page = max(1, intval($_GET['page'] ?? 1));
$limit = $config['items_per_page'];
$offset = ($page - 1) * $limit;

// Fetch bookmarks
if (!empty($tag)) {
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

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .search-form input[type="text"] {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .search-form button {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .search-form button:hover {
            background: #2980b9;
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

        .btn-primary {
            background: #27ae60;
        }

        .btn-primary:hover {
            background: #229954;
        }

        .bookmark {
            background: white;
            padding: 20px;
            margin-bottom: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .bookmark h2 {
            margin: 0 0 10px 0;
            font-size: 18px;
        }

        .bookmark h2 a {
            color: #2c3e50;
            text-decoration: none;
        }

        .bookmark h2 a:hover {
            color: #3498db;
        }

        .bookmark .url {
            color: #7f8c8d;
            font-size: 13px;
            word-break: break-all;
            margin-bottom: 10px;
        }

        .bookmark .description {
            color: #555;
            margin-bottom: 10px;
            line-height: 1.6;
        }

        /* Markdown styling within descriptions */
        .bookmark .description p {
            margin: 0.75em 0;
        }

        .bookmark .description p:first-child {
            margin-top: 0;
        }

        .bookmark .description p:last-child {
            margin-bottom: 0;
        }

        .bookmark .description h1,
        .bookmark .description h2,
        .bookmark .description h3,
        .bookmark .description h4,
        .bookmark .description h5,
        .bookmark .description h6 {
            margin: 15px 0 10px 0;
            color: #2c3e50;
            font-weight: 600;
        }

        .bookmark .description h1 { font-size: 1.5em; }
        .bookmark .description h2 { font-size: 1.3em; }
        .bookmark .description h3 { font-size: 1.1em; }
        .bookmark .description h4 { font-size: 1em; }
        .bookmark .description h5 { font-size: 0.9em; }
        .bookmark .description h6 { font-size: 0.85em; }

        .bookmark .description strong {
            font-weight: 600;
            color: #2c3e50;
        }

        .bookmark .description em {
            font-style: italic;
        }

        .bookmark .description code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Monaco', 'Menlo', 'Courier New', monospace;
            font-size: 0.9em;
            color: #c7254e;
        }

        .bookmark .description pre {
            background: #f4f4f4;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 0 12px;
            overflow-x: auto;
            margin: 0;
        }

        .bookmark .description pre code {
            background: none;
            padding: 0;
            color: #333;
            font-size: 0.85em;
        }

        .bookmark .description ul,
        .bookmark .description ol {
            margin: 10px 0;
            padding-left: 30px;
        }

        .bookmark .description li {
            margin: 5px 0;
        }

        .bookmark .description blockquote {
            border-left: 4px solid #3498db;
            padding-left: 15px;
            margin: 0;
            color: #666;
            font-style: italic;
        }

        .bookmark .description hr {
            border: none;
            border-top: 2px solid #ddd;
            margin: 15px 0;
        }

        .bookmark .description a {
            color: #3498db;
            text-decoration: none;
        }

        .bookmark .description a:hover {
            text-decoration: underline;
        }

        .bookmark .screenshot {
            margin: 10px 0;
        }

        .bookmark .screenshot img {
            max-width: 100%;
            height: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
        }

        .bookmark .screenshot img.thumbnail {
            max-width: 300px;
        }

        .bookmark .meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 13px;
            color: #7f8c8d;
        }

        .bookmark.private {
            border-left: 4px solid #e67e22;
        }

        .bookmark .private-badge {
            display: inline-block;
            padding: 2px 8px;
            background: #e67e22;
            color: white;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            margin-left: 10px;
        }

        .bookmark .tags {
            color: #3498db;
        }

        .bookmark .actions {
            display: flex;
            gap: 10px;
        }

        .bookmark .actions a {
            color: #3498db;
            text-decoration: none;
            font-size: 13px;
        }

        .bookmark .actions a:hover {
            text-decoration: underline;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            background: white;
            color: #3498db;
            text-decoration: none;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .pagination span {
            background: #3498db;
            color: white;
        }

        .pagination a:hover {
            background: #ecf0f1;
        }

        .no-results {
            background: white;
            padding: 40px;
            text-align: center;
            border-radius: 8px;
            color: #7f8c8d;
        }

        .edit-form {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
            border: 1px solid #ddd;
        }

        .edit-form .form-group {
            margin-bottom: 15px;
        }

        .edit-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #2c3e50;
            font-size: 14px;
        }

        .edit-form input[type="text"],
        .edit-form textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }

        .edit-form textarea {
            resize: vertical;
            min-height: 80px;
        }

        .edit-form .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .edit-form .checkbox-group input[type="checkbox"] {
            width: auto;
            cursor: pointer;
        }

        .edit-form .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: normal;
        }

        .edit-form .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .edit-form button {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .edit-form .btn-save {
            background: #27ae60;
            color: white;
        }

        .edit-form .btn-save:hover {
            background: #229954;
        }

        .edit-form .btn-cancel {
            background: #95a5a6;
            color: white;
        }

        .edit-form .btn-cancel:hover {
            background: #7f8c8d;
        }

        .tag-autocomplete {
            position: relative;
        }

        .tag-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .tag-suggestions.active {
            display: block;
        }

        .tag-suggestion {
            padding: 8px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }

        .tag-suggestion:last-child {
            border-bottom: none;
        }

        .tag-suggestion:hover,
        .tag-suggestion.selected {
            background: #e8f4f8;
        }

        .tag-suggestion-match {
            font-weight: bold;
            color: #3498db;
        }

        .menu-dropdown {
            position: relative;
            display: inline-block;
        }

        .menu-trigger {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .menu-trigger:hover {
            background: #7f8c8d;
        }

        .menu-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 5px;
            background: white;
            min-width: 160px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-radius: 4px;
            z-index: 1000;
            overflow: hidden;
        }

        .menu-content.active {
            display: block;
        }

        .menu-content a {
            display: block;
            padding: 10px 15px;
            color: #2c3e50;
            text-decoration: none;
            font-size: 13px;
            transition: background 0.15s;
        }

        .menu-content a:hover {
            background: #f8f9fa;
        }

        .menu-divider {
            height: 1px;
            background: #ecf0f1;
            margin: 5px 0;
        }

        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            header {
                padding: 15px;
            }

            .search-form {
                flex-direction: column;
            }

            .bookmark {
                padding: 15px;
            }

            .bookmark .meta {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1><?= htmlspecialchars($config['site_title']) ?></h1>

        <form method="get" action="" class="search-form">
            <input type="text" name="q" placeholder="Search bookmarks..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">Search</button>
        </form>

        <?php if (!empty($tag)): ?>
            <div style="margin-bottom: 15px; padding: 10px; background: #e8f4f8; border-radius: 4px; color: #2c3e50;">
                Showing bookmarks tagged with: <strong><?= htmlspecialchars($tag) ?></strong>
            </div>
        <?php endif; ?>

        <div class="actions">
            <?php if (!empty($search)): ?>
                <a href="<?= $config['base_path'] ?>" class="btn">Clear Search</a>
            <?php endif; ?>
            <?php if (!empty($tag)): ?>
                <a href="<?= $config['base_path'] ?>" class="btn">Clear Filter</a>
            <?php endif; ?>
            <?php if ($isLoggedIn): ?>
                <a href="#" onclick="showAddBookmark(); return false;" class="btn btn-primary">Add Bookmark</a>
                <a href="<?= $config['base_path'] ?>/dashboard.php" class="btn">Dashboard</a>
                <a href="<?= $config['base_path'] ?>/gallery.php" class="btn">Gallery</a>
                <a href="<?= $config['base_path'] ?>/archive.php" class="btn">Archive</a>
                <a href="<?= $config['base_path'] ?>/tags.php" class="btn">Tags</a>
                <div class="menu-dropdown">
                    <button class="menu-trigger" onclick="toggleMenu(event)">
                        <span>⚙</span> Menu
                    </button>
                    <div class="menu-content" id="mainMenu">
                        <a href="<?= $config['base_path'] ?>/bookmarklet-setup.php">Bookmarklet</a>
                        <a href="<?= $config['base_path'] ?>/rss.php">RSS Feed</a>
                        <div class="menu-divider"></div>
                        <a href="<?= $config['base_path'] ?>/import.php">Import</a>
                        <a href="<?= $config['base_path'] ?>/export.php">Export</a>
                        <div class="menu-divider"></div>
                        <a href="<?= $config['base_path'] ?>/logout.php">Logout</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= $config['base_path'] ?>/login.php" class="btn btn-primary">Login</a>
                <a href="<?= $config['base_path'] ?>/gallery.php" class="btn">Gallery</a>
                <a href="<?= $config['base_path'] ?>/archive.php" class="btn">Archive</a>
                <a href="<?= $config['base_path'] ?>/rss.php" class="btn">RSS Feed</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (empty($bookmarks)): ?>
        <div class="no-results">
            <?php if (!empty($search)): ?>
                <p>No bookmarks found for "<?= htmlspecialchars($search) ?>"</p>
            <?php else: ?>
                <p>No bookmarks yet. Add your first bookmark!</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($bookmarks as $bookmark): ?>
            <div class="bookmark<?= !empty($bookmark['private']) ? ' private' : '' ?>" id="bookmark-<?= $bookmark['id'] ?>">
                <h2>
                    <a href="<?= htmlspecialchars($bookmark['url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($bookmark['title']) ?></a>
                    <?php if (!empty($bookmark['private'])): ?>
                        <span class="private-badge">PRIVATE</span>
                    <?php endif; ?>
                </h2>
                <div class="url"><?= htmlspecialchars($bookmark['url']) ?></div>
                <?php if (!empty($bookmark['description'])): ?>
                    <div class="description"><?= parseMarkdown($bookmark['description']) ?></div>
                <?php endif; ?>
                <?php if (!empty($bookmark['screenshot'])): ?>
                    <div class="screenshot">
                        <img src="<?= $config['base_path'] . '/' . htmlspecialchars($bookmark['screenshot']) ?>"
                             class="thumbnail"
                             alt="Screenshot"
                             onclick="this.classList.toggle('thumbnail')"
                             title="Click to toggle size">
                    </div>
                <?php endif; ?>
                <div class="meta">
                    <div>
                        <?php if (!empty($bookmark['tags'])): ?>
                            <span class="tags">Tags:
                            <?php
                                $tagList = array_map('trim', explode(',', $bookmark['tags']));
                                $tagLinks = [];
                                foreach ($tagList as $tagItem) {
                                    $tagLinks[] = '<a href="?tag=' . urlencode($tagItem) . '" style="color: #3498db; text-decoration: none;">' . htmlspecialchars($tagItem) . '</a>';
                                }
                                echo implode(', ', $tagLinks);
                            ?>
                            </span>
                        <?php endif; ?>
                        <span> &middot; <?= date($config['date_format'], strtotime($bookmark['created_at'])) ?></span>
                        <?php if (!empty($bookmark['archive_url'])): ?>
                            <span> &middot; <a href="<?= htmlspecialchars($bookmark['archive_url']) ?>" target="_blank" rel="noopener noreferrer" style="color: #27ae60;">Archive</a></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isLoggedIn): ?>
                    <div class="actions">
                        <a href="#" onclick="editBookmark(<?= $bookmark['id'] ?>); return false;">Edit</a>
                        <a href="#" onclick="deleteBookmark(<?= $bookmark['id'] ?>); return false;">Delete</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                $paginationParams = ['q' => $search, 'tag' => $tag];
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

    <script>
        const BASE_PATH = <?= json_encode($config['base_path']) ?>;
        const IS_LOGGED_IN = <?= json_encode($isLoggedIn) ?>;
        const CSRF_TOKEN = <?= json_encode(csrf_get_token()) ?>;

        function showAddBookmark() {
            if (!IS_LOGGED_IN) return;
            const url = prompt('Enter URL:');
            if (!url) return;

            const title = prompt('Enter title:');
            if (!title) return;

            const description = prompt('Enter description (optional):') || '';
            const tags = prompt('Enter tags (comma-separated, optional):') || '';
            const isPrivate = confirm('Make this bookmark private? (Hidden from RSS feed and recent bookmarks)');

            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('url', url);
            formData.append('title', title);
            formData.append('description', description);
            formData.append('tags', tags);
            if (isPrivate) {
                formData.append('private', '1');
            }

            fetch(BASE_PATH + '/api.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Bookmark added successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(err => alert('Error: ' + err));
        }

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

            input.addEventListener('input', async function() {
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
                    suggestionEl.addEventListener('click', function() {
                        const tag = this.getAttribute('data-tag');
                        insertTag(input, tag, lastComma, cursorPos, nextComma === -1 ? value.length : cursorPos + nextComma);
                        suggestionsDiv.classList.remove('active');
                    });
                });
            });

            input.addEventListener('keydown', function(e) {
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
            document.addEventListener('click', function(e) {
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

        // Menu dropdown functionality
        function toggleMenu(event) {
            event.stopPropagation();
            const menu = document.getElementById('mainMenu');
            menu.classList.toggle('active');
        }

        // Close menu when clicking outside
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('mainMenu');
            if (menu && !event.target.closest('.menu-dropdown')) {
                menu.classList.remove('active');
            }
        });
    </script>
</body>
</html>
