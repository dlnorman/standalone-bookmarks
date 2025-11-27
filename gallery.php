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
require_once __DIR__ . '/includes/markdown.php';
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
$query = "SELECT id, url, title, description, screenshot, created_at, tags FROM bookmarks WHERE screenshot IS NOT NULL AND screenshot != ''";

// If not logged in, only show public bookmarks
if (!$isLoggedIn) {
    $query .= " AND private = 0";
}

$query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";

$stmt = $db->prepare($query);
$stmt->execute([$itemsPerPage, $offset]);
$bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-render markdown descriptions
foreach ($bookmarks as &$bookmark) {
    $bookmark['description_html'] = parseMarkdown($bookmark['description'] ?? '');
}
unset($bookmark); // Break reference
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Screenshot Gallery - <?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">
</head>

<body>
    <?php render_nav($config, $isLoggedIn, 'gallery', 'Screenshot Gallery'); ?>

    <div class="gallery-container">
        <div class="filter-bar">
            <input type="text" id="filterInput" class="filter-input" placeholder="🔍 Filter by title, URL, or tags...">
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
                <p style="margin-top: 10px; font-size: 13px;">Screenshots are automatically captured when you bookmark
                    pages.</p>
            </div>
        <?php else: ?>
            <div class="gallery-grid" id="galleryGrid">
                <?php foreach ($bookmarks as $bookmark): ?>
                    <div class="gallery-item" data-id="<?= $bookmark['id'] ?>"
                        data-title="<?= htmlspecialchars($bookmark['title']) ?>"
                        data-url="<?= htmlspecialchars($bookmark['url']) ?>"
                        data-tags="<?= htmlspecialchars(strtolower($bookmark['tags'] ?? '')) ?>"
                        onclick="window.open('<?= htmlspecialchars($bookmark['url']) ?>', '_blank')">
                        <img src="<?= $config['base_path'] ?>/<?= htmlspecialchars($bookmark['screenshot']) ?>"
                            alt="<?= htmlspecialchars($bookmark['title']) ?>" class="gallery-item-image" loading="lazy">
                        <div class="gallery-item-info">
                            <div class="gallery-item-header">
                                <div class="gallery-item-title"><?= htmlspecialchars($bookmark['title']) ?></div>
                                <button class="btn-details" onclick="event.stopPropagation(); openModal(<?= $bookmark['id'] ?>)"
                                    title="View Details">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <line x1="12" y1="16" x2="12" y2="12"></line>
                                        <line x1="12" y1="8" x2="12.01" y2="8"></line>
                                    </svg>
                                </button>
                            </div>
                            <div class="gallery-item-url"><?= htmlspecialchars(parse_url($bookmark['url'], PHP_URL_HOST)) ?>
                            </div>
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
                <div id="modalDescription" class="modal-description"></div>
                <div id="modalTags" class="modal-tags"></div>
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

        filterInput.addEventListener('input', function () {
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
            const modalDescription = document.getElementById('modalDescription');
            const modalTags = document.getElementById('modalTags');

            modalImage.src = basePath + '/' + bookmark.screenshot;
            modalTitle.textContent = bookmark.title;
            modalUrl.href = bookmark.url;
            modalUrl.textContent = bookmark.url;

            // Handle description
            if (bookmark.description_html) {
                modalDescription.innerHTML = bookmark.description_html;
                modalDescription.style.display = 'block';
            } else if (bookmark.description) {
                // Fallback for raw description if html not present
                modalDescription.textContent = bookmark.description;
                modalDescription.style.display = 'block';
            } else {
                modalDescription.style.display = 'none';
            }

            // Handle tags
            modalTags.innerHTML = '';
            if (bookmark.tags) {
                const tags = bookmark.tags.split(',').map(t => t.trim()).filter(t => t);
                if (tags.length > 0) {
                    tags.forEach(tag => {
                        const span = document.createElement('span');
                        span.className = 'modal-tag';
                        span.textContent = tag;
                        modalTags.appendChild(span);
                    });
                    modalTags.style.display = 'flex';
                } else {
                    modalTags.style.display = 'none';
                }
            } else {
                modalTags.style.display = 'none';
            }

            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(event) {
            const modal = document.getElementById('modal');
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close modal on escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
    <?php render_nav_scripts(); ?>
</body>

</html>