<?php
/**
 * Screenshot Gallery - displays all bookmark screenshots in a masonry grid
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

// Check if logged in (but don't require it for public galleries)
$isLoggedIn = is_logged_in();

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed.');
}

// Pagination settings
$itemsPerPage = 100;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $itemsPerPage;

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM bookmarks WHERE screenshot IS NOT NULL AND screenshot != ''";
if (!$isLoggedIn) {
    $countQuery .= " AND private = 0";
}
$totalBookmarks = $db->query($countQuery)->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalBookmarks / $itemsPerPage);

// Fetch bookmarks with screenshots for current page
$query = "SELECT id, url, title, screenshot, created_at, tags FROM bookmarks WHERE screenshot IS NOT NULL AND screenshot != ''";

// If not logged in, only show public bookmarks
if (!$isLoggedIn) {
    $query .= " AND private = 0";
}

$query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";

$stmt = $db->prepare($query);
$stmt->execute([$itemsPerPage, $offset]);
$bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Screenshot Gallery - <?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #1a1a1a;
            color: #fff;
            margin: 0;
            padding: 0;
        }

        .app-nav {
            background: rgba(0, 0, 0, 0.95);
        }

        .app-nav .brand-link,
        .app-nav .nav-link {
            color: rgba(255, 255, 255, 0.9);
        }

        .app-nav .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .app-nav .nav-link.active {
            background: #667eea;
            color: white;
        }

        .app-nav .nav-btn,
        .app-nav .nav-dropdown-trigger {
            color: rgba(255, 255, 255, 0.9);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .app-nav .nav-btn:hover,
        .app-nav .nav-dropdown-trigger:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .app-nav .nav-dropdown-content {
            background: rgba(0, 0, 0, 0.95);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .app-nav .dropdown-item {
            color: rgba(255, 255, 255, 0.9);
        }

        .app-nav .dropdown-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .app-nav .hamburger-icon,
        .app-nav .hamburger-icon::before,
        .app-nav .hamburger-icon::after {
            background: rgba(255, 255, 255, 0.9);
        }

        .filter-bar {
            max-width: 1400px;
            margin: 20px auto;
            padding: 0 20px;
        }

        .filter-input {
            width: 100%;
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            color: white;
            font-size: 14px;
            transition: all 0.2s;
        }

        .filter-input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.15);
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filter-input::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        .gallery-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .gallery-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .gallery-item:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
            border-color: rgba(102, 126, 234, 0.5);
        }

        .gallery-item-image {
            width: 100%;
            height: 250px;
            object-fit: cover;
            display: block;
            transition: transform 0.3s;
        }

        .gallery-item:hover .gallery-item-image {
            transform: scale(1.05);
        }

        .gallery-item-info {
            padding: 15px;
        }

        .gallery-item-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #fff;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.4;
        }

        .gallery-item-url {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 10px;
        }

        .gallery-item-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .gallery-tag {
            font-size: 11px;
            padding: 3px 8px;
            background: rgba(102, 126, 234, 0.2);
            color: #a0b0ff;
            border-radius: 4px;
            border: 1px solid rgba(102, 126, 234, 0.3);
        }

        .gallery-item-date {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
            margin-top: 8px;
        }

        .stats {
            text-align: center;
            margin: 20px 0;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.6);
        }

        .no-results {
            text-align: center;
            padding: 80px 20px;
            color: rgba(255, 255, 255, 0.5);
        }

        .no-results-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        /* Modal for full-size image */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            z-index: 2000;
            padding: 20px;
            overflow: auto;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            max-width: 90%;
            max-height: 90%;
            position: relative;
        }

        .modal-image {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
            border-radius: 8px;
        }

        .modal-close {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            font-size: 24px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .modal-info {
            background: rgba(0, 0, 0, 0.8);
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            max-width: 800px;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .modal-url {
            color: #667eea;
            text-decoration: none;
            word-break: break-all;
        }

        .modal-url:hover {
            text-decoration: underline;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin: 40px 0;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 10px 16px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.2s;
            font-size: 14px;
            min-width: 44px;
            text-align: center;
        }

        .pagination a:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }

        .pagination .current {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: rgba(102, 126, 234, 0.5);
            font-weight: 600;
        }

        .pagination .disabled {
            opacity: 0.3;
            pointer-events: none;
        }

        .pagination-info {
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 15px;
            }

            .gallery-item-image {
                height: 200px;
            }

            h1 {
                font-size: 20px;
            }
        }

        @media (max-width: 480px) {
            .gallery-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php render_nav($config, $isLoggedIn, 'gallery', 'Screenshot Gallery'); ?>

    <div class="gallery-container">
        <div class="filter-bar">
            <input type="text"
                   id="filterInput"
                   class="filter-input"
                   placeholder="🔍 Filter by title, URL, or tags...">
        </div>

        <div class="stats">
            <span id="visibleCount"><?= count($bookmarks) ?></span> of <?= count($bookmarks) ?> screenshots on this page
            <?php if ($totalPages > 1): ?>
                <span class="pagination-info">
                    (<?= $totalBookmarks ?> total across <?= $totalPages ?> pages)
                </span>
            <?php endif; ?>
        </div>

        <?php if (empty($bookmarks)): ?>
            <div class="no-results">
                <div class="no-results-icon">📷</div>
                <p>No screenshots available yet.</p>
                <p style="margin-top: 10px; font-size: 13px;">Screenshots are automatically captured when you bookmark pages.</p>
            </div>
        <?php else: ?>
            <div class="gallery-grid" id="galleryGrid">
                <?php foreach ($bookmarks as $bookmark): ?>
                    <div class="gallery-item"
                         data-id="<?= $bookmark['id'] ?>"
                         data-title="<?= htmlspecialchars($bookmark['title']) ?>"
                         data-url="<?= htmlspecialchars($bookmark['url']) ?>"
                         data-tags="<?= htmlspecialchars(strtolower($bookmark['tags'] ?? '')) ?>"
                         onclick="openModal(<?= $bookmark['id'] ?>)">
                        <img src="<?= $config['base_path'] ?>/<?= htmlspecialchars($bookmark['screenshot']) ?>"
                             alt="<?= htmlspecialchars($bookmark['title']) ?>"
                             class="gallery-item-image"
                             loading="lazy">
                        <div class="gallery-item-info">
                            <div class="gallery-item-title"><?= htmlspecialchars($bookmark['title']) ?></div>
                            <div class="gallery-item-url"><?= htmlspecialchars(parse_url($bookmark['url'], PHP_URL_HOST)) ?></div>
                            <?php if (!empty($bookmark['tags'])): ?>
                                <div class="gallery-item-tags">
                                    <?php
                                    $tags = array_map('trim', explode(',', $bookmark['tags']));
                                    foreach (array_slice($tags, 0, 3) as $tag):
                                    ?>
                                        <span class="gallery-tag"><?= htmlspecialchars($tag) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($tags) > 3): ?>
                                        <span class="gallery-tag">+<?= count($tags) - 3 ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="gallery-item-date">
                                <?= date($config['date_format'], strtotime($bookmark['created_at'])) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=1">« First</a>
                        <a href="?page=<?= $page - 1 ?>">‹ Previous</a>
                    <?php else: ?>
                        <span class="disabled">« First</span>
                        <span class="disabled">‹ Previous</span>
                    <?php endif; ?>

                    <?php
                    // Show page numbers with ellipsis for large page counts
                    $range = 2; // Pages to show on each side of current page
                    $start = max(1, $page - $range);
                    $end = min($totalPages, $page + $range);

                    if ($start > 1) {
                        echo '<a href="?page=1">1</a>';
                        if ($start > 2) {
                            echo '<span class="disabled">...</span>';
                        }
                    }

                    for ($i = $start; $i <= $end; $i++) {
                        if ($i == $page) {
                            echo '<span class="current">' . $i . '</span>';
                        } else {
                            echo '<a href="?page=' . $i . '">' . $i . '</a>';
                        }
                    }

                    if ($end < $totalPages) {
                        if ($end < $totalPages - 1) {
                            echo '<span class="disabled">...</span>';
                        }
                        echo '<a href="?page=' . $totalPages . '">' . $totalPages . '</a>';
                    }
                    ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>">Next ›</a>
                        <a href="?page=<?= $totalPages ?>">Last »</a>
                    <?php else: ?>
                        <span class="disabled">Next ›</span>
                        <span class="disabled">Last »</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal -->
    <div class="modal" id="modal" onclick="closeModal(event)">
        <button class="modal-close" onclick="closeModal(event)">×</button>
        <div class="modal-content" onclick="event.stopPropagation()">
            <img id="modalImage" class="modal-image" src="" alt="">
            <div class="modal-info">
                <div class="modal-title" id="modalTitle"></div>
                <a id="modalUrl" class="modal-url" href="" target="_blank" rel="noopener noreferrer"></a>
            </div>
        </div>
    </div>

    <script>
        const bookmarksData = <?= json_encode($bookmarks) ?>;
        const basePath = <?= json_encode($config['base_path']) ?>;

        // Filter functionality
        const filterInput = document.getElementById('filterInput');
        const galleryGrid = document.getElementById('galleryGrid');
        const visibleCount = document.getElementById('visibleCount');

        filterInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const items = galleryGrid.querySelectorAll('.gallery-item');
            let visible = 0;

            items.forEach(item => {
                const title = item.dataset.title.toLowerCase();
                const url = item.dataset.url.toLowerCase();
                const tags = item.dataset.tags.toLowerCase();

                const matches = title.includes(query) || url.includes(query) || tags.includes(query);

                if (matches) {
                    item.style.display = 'block';
                    visible++;
                } else {
                    item.style.display = 'none';
                }
            });

            visibleCount.textContent = visible;
        });

        // Modal functionality
        function openModal(bookmarkId) {
            const bookmark = bookmarksData.find(b => b.id == bookmarkId);
            if (!bookmark) return;

            const modal = document.getElementById('modal');
            const modalImage = document.getElementById('modalImage');
            const modalTitle = document.getElementById('modalTitle');
            const modalUrl = document.getElementById('modalUrl');

            modalImage.src = basePath + '/' + bookmark.screenshot;
            modalTitle.textContent = bookmark.title;
            modalUrl.href = bookmark.url;
            modalUrl.textContent = bookmark.url;

            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(event) {
            const modal = document.getElementById('modal');
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
    <?php render_nav_scripts(); ?>
</body>
</html>
