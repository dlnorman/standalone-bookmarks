<?php
/**
 * Single bookmark permalink view.
 *
 * Gives every bookmark entry a shareable URL of its own (bookmark.php?id=N) so
 * the notes, tags and screenshot can be shared directly instead of only the
 * bookmarked page's URL. Private bookmarks are invisible (404) to anyone who
 * isn't logged in.
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found. Please copy config-example.php to config.php and adjust settings.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/markdown.php';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/tags.php';
require_once __DIR__ . '/includes/security.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Check if user is authenticated (but don't force login — permalinks are public)
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

$id = intval($_GET['id'] ?? 0);

$bookmark = null;
if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM bookmarks WHERE id = ?");
    $stmt->execute([$id]);
    $bookmark = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Private bookmarks don't exist as far as the public is concerned
if ($bookmark && !empty($bookmark['private']) && !$isLoggedIn) {
    $bookmark = false;
}

if (!$bookmark) {
    http_response_code(404);
    header('Cache-Control: no-store');
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex">
        <title>Bookmark not found - <?= htmlspecialchars($config['site_title']) ?></title>
        <?php render_nav_styles(); ?>
        <link rel="stylesheet" href="<?= $config['base_path'] ?>/css/main.css">
    </head>

    <body>
        <?php render_nav($config, $isLoggedIn, 'index'); ?>
        <div class="page-container">
            <div class="no-results">
                <p>That bookmark doesn't exist (or isn't public).</p>
                <p><a href="<?= $config['base_path'] ?>/" class="btn">Browse all bookmarks</a></p>
            </div>
        </div>
        <?php render_nav_scripts(); ?>
    </body>

    </html>
    <?php
    exit;
}

// Absolute permalink — used for the canonical link, sharing meta and Copy Link
$permalink = rtrim($config['site_url'], '/') . '/bookmark.php?id=' . $bookmark['id'];

// HTTP caching. Public entries can sit in shared caches; anything only visible
// to a logged-in user must not.
$isPublicView = empty($bookmark['private']) && !$isLoggedIn;
$lastMod = $bookmark['updated_at'] ?: $bookmark['created_at'];
if ($lastMod) {
    $lastModTime = strtotime($lastMod);
    $etag = md5($bookmark['id'] . '|' . $lastMod . '|' . ($isLoggedIn ? 'auth' : 'public'));

    header('Cache-Control: ' . ($isPublicView ? 'public, max-age=300' : 'private, no-cache'));
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModTime) . ' GMT');
    header('ETag: "' . $etag . '"');

    $ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;
    $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') : '';

    if ($ifModifiedSince >= $lastModTime || $ifNoneMatch === $etag) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
}

$tagList = !empty($bookmark['tags'])
    ? array_values(array_filter(array_map('trim', explode(',', $bookmark['tags']))))
    : [];

// Plain-text blurb for og:description — strip the rendered Markdown back down
$shareDescription = '';
if (!empty($bookmark['description'])) {
    // Tags become spaces (not nothing) so block boundaries don't glue words together
    $plain = preg_replace('/<[^>]*>/', ' ', parseMarkdown($bookmark['description']));
    $shareDescription = trim(preg_replace('/\s+/', ' ', html_entity_decode($plain, ENT_QUOTES, 'UTF-8')));
    if (mb_strlen($shareDescription) > 200) {
        $shareDescription = mb_substr($shareDescription, 0, 197) . '…';
    }
}
if ($shareDescription === '' && $tagList) {
    $shareDescription = 'Tagged ' . implode(', ', $tagList);
}

$shareImage = !empty($bookmark['screenshot'])
    ? rtrim($config['site_url'], '/') . '/' . ltrim($bookmark['screenshot'], '/')
    : '';

$domain = parse_url($bookmark['url'], PHP_URL_HOST);
$domain = $domain ? preg_replace('/^www\./', '', $domain) : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($bookmark['title']) ?> - <?= htmlspecialchars($config['site_title']) ?></title>
    <link rel="canonical" href="<?= htmlspecialchars($permalink) ?>">
    <?php if (!empty($bookmark['private'])): ?>
        <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>

    <!-- Sharing preview -->
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="<?= htmlspecialchars($config['site_title']) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($bookmark['title']) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($permalink) ?>">
    <?php if ($shareDescription !== ''): ?>
        <meta property="og:description" content="<?= htmlspecialchars($shareDescription) ?>">
        <meta name="description" content="<?= htmlspecialchars($shareDescription) ?>">
    <?php endif; ?>
    <?php if ($shareImage !== ''): ?>
        <meta property="og:image" content="<?= htmlspecialchars($shareImage) ?>">
        <meta name="twitter:card" content="summary_large_image">
    <?php else: ?>
        <meta name="twitter:card" content="summary">
    <?php endif; ?>

    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="<?= $config['base_path'] ?>/css/main.css">
</head>

<body>
    <?php render_nav($config, $isLoggedIn, 'index'); ?>

    <div class="page-container">
        <div class="bookmark-permalink-header">
            <a href="<?= $config['base_path'] ?>/" class="permalink-back">← All bookmarks</a>
        </div>

        <div class="bookmark bookmark-permalink<?= !empty($bookmark['private']) ? ' private' : '' ?><?= !empty($bookmark['broken_url']) ? ' broken' : '' ?>"
            id="bookmark-<?= $bookmark['id'] ?>">

            <?php if (!empty($bookmark['screenshot'])): ?>
                <div class="bookmark-screenshot-container">
                    <a href="<?= htmlspecialchars($bookmark['url']) ?>" target="_blank" rel="noopener noreferrer">
                        <img src="<?= $config['base_path'] . '/' . htmlspecialchars($bookmark['screenshot']) ?>"
                            alt="Screenshot of <?= htmlspecialchars($bookmark['title']) ?>" title="Click to open bookmark">
                    </a>
                </div>
            <?php endif; ?>

            <div class="bookmark-content">
                <div class="bookmark-header">
                    <h1>
                        <a href="<?= htmlspecialchars($bookmark['url']) ?>" target="_blank"
                            rel="noopener noreferrer"><?= htmlspecialchars($bookmark['title']) ?></a>
                        <?php if (!empty($bookmark['private'])): ?>
                            <span class="private-badge">PRIVATE</span>
                        <?php endif; ?>
                        <?php if (!empty($bookmark['broken_url'])): ?>
                            <span class="broken-badge"
                                title="This URL appears to be broken. Last checked: <?= !empty($bookmark['last_checked']) ? date($config['date_format'], strtotime($bookmark['last_checked'])) : 'N/A' ?>">BROKEN</span>
                        <?php endif; ?>
                    </h1>
                    <div class="url">
                        <a href="<?= htmlspecialchars($bookmark['url']) ?>" target="_blank"
                            rel="noopener noreferrer"><?= htmlspecialchars($bookmark['url']) ?></a>
                    </div>
                </div>

                <?php if (!empty($bookmark['description'])): ?>
                    <div class="description"><?= parseMarkdown($bookmark['description']) ?></div>
                <?php endif; ?>

                <?php if ($tagList): ?>
                    <div class="bookmark-tags">
                        <?php foreach ($tagList as $tagItem): ?>
                            <?= renderTag($tagItem, $config['base_path']) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="meta">
                    <div class="bookmark-meta-left">
                        <span class="bookmark-meta-item">
                            Added <?= date($config['date_format'], strtotime($bookmark['created_at'])) ?>
                        </span>
                        <?php if (!empty($bookmark['updated_at']) && strtotime($bookmark['updated_at']) > strtotime($bookmark['created_at']) + 60): ?>
                            <span class="bookmark-meta-item">
                                Updated <?= date($config['date_format'], strtotime($bookmark['updated_at'])) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($domain !== ''): ?>
                            <span class="bookmark-meta-item"><?= htmlspecialchars($domain) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($bookmark['archive_url'])): ?>
                            <span class="bookmark-meta-item">
                                <a href="<?= htmlspecialchars($bookmark['archive_url']) ?>" target="_blank"
                                    rel="noopener noreferrer" class="bookmark-archive-link">📦 Archive</a>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="permalink-actions">
                    <button type="button" class="btn btn-secondary" id="copyPermalinkBtn"
                        onclick="copyPermalink()">🔗 Copy link to this bookmark</button>
                    <a href="<?= htmlspecialchars($bookmark['url']) ?>" target="_blank" rel="noopener noreferrer"
                        class="btn">Visit page →</a>
                </div>
            </div>
        </div>
    </div>

    <?php render_nav_scripts(); ?>
    <script>
        const PERMALINK = <?= json_encode($permalink) ?>;

        function copyPermalink() {
            const btn = document.getElementById('copyPermalinkBtn');
            const original = btn.textContent;
            const done = (ok) => {
                btn.textContent = ok ? '✓ Link copied' : '✗ Copy failed';
                setTimeout(() => { btn.textContent = original; }, 2000);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(PERMALINK).then(() => done(true), () => done(false));
                return;
            }

            // Fallback for non-secure contexts (no navigator.clipboard)
            const ta = document.createElement('textarea');
            ta.value = PERMALINK;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            let ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(ta);
            done(ok);
        }
    </script>
</body>

</html>
