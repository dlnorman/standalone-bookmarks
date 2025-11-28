<?php
/**
 * Bookmarklet setup page
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

// Require authentication
require_auth($config);

// Check if user is authenticated
$isLoggedIn = is_logged_in();

// Build the bookmarklet code
$bookmarkletCode = "javascript:(function(){var sel=window.getSelection().toString();window.open('" . $config['site_url'] . "/bookmarklet.php?url='+encodeURIComponent(location.href)+'&title='+encodeURIComponent(document.title)+'&selected='+encodeURIComponent(sel),'bookmarklet','width=600,height=700');})();";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install Bookmarklet - <?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">
</head>

<body>
    <?php render_nav($config, $isLoggedIn, 'bookmarklet-setup', 'Install Bookmarklet'); ?>

    <div class="page-container">
        <div class="content">
            <p>A bookmarklet lets you quickly save any webpage you're viewing to your bookmarks with just one click!</p>

            <div class="bookmarklet-box">
                <p class="bookmarklet-helper-text">Drag this button to your
                    bookmarks bar:</p>
                <a href="<?= htmlspecialchars($bookmarkletCode) ?>" class="bookmarklet-link"
                    onclick="alert('Please drag this link to your bookmarks bar instead of clicking it!'); return false;">
                    📚 Add to Bookmarks
                </a>
                <p class="bookmarklet-sub-text">
                    (Click and drag to your bookmarks bar)
                </p>
            </div>

            <div class="instructions">
                <h2>Installation Instructions</h2>

                <ol>
                    <li>
                        <strong>Show your bookmarks bar</strong> if it's not already visible:
                        <ul class="bookmarklet-list">
                            <li><strong>Safari:</strong> View → Show Favorites Bar (or ⌘⇧B)</li>
                            <li><strong>Chrome:</strong> View → Always Show Bookmarks Bar (or ⌘⇧B)</li>
                            <li><strong>Firefox:</strong> View → Toolbars → Bookmarks Toolbar (or ⌘⇧B)</li>
                            <li><strong>Edge:</strong> Settings → Appearance → Show favorites bar</li>
                        </ul>
                    </li>
                    <li>
                        <strong>Drag the blue "Add to Bookmarks" button</strong> above to your bookmarks bar
                    </li>
                    <li>
                        <strong>That's it!</strong> Now when you're on any webpage, click the bookmarklet to save it
                    </li>
                </ol>
            </div>

            <div class="success-box">
                <strong>✓ How to use:</strong> When you're on a webpage you want to bookmark, just click the bookmarklet
                in
                your bookmarks bar. It will automatically capture the page title, URL, and metadata, and open a popup
                where
                you can review and save it.
            </div>

            <div class="note-box">
                <strong>Note:</strong> You need to stay logged in to <?= htmlspecialchars($config['site_title']) ?> for
                the
                bookmarklet to work. Your login session lasts for <?= intval($config['session_timeout'] / 86400) ?>
                days.
            </div>

            <details class="manual-install-details">
                <summary class="manual-install-summary">Alternative: Manual
                    Installation
                </summary>
                <div class="manual-install-content">
                    <p>If dragging doesn't work in your browser, you can manually create a bookmark:</p>
                    <ol>
                        <li>Create a new bookmark in your bookmarks bar</li>
                        <li>Name it "Add to Bookmarks" (or whatever you like)</li>
                        <li>Copy the code below and paste it as the URL/Location:</li>
                    </ol>
                    <div class="code-box"><?= htmlspecialchars($bookmarkletCode) ?></div>
                    <button onclick="copyCode()" class="btn-copy">
                        Copy Code
                    </button>
                </div>
            </details>
        </div>
    </div>

    <?php render_nav_scripts(); ?>
    <script>
        function copyCode() {
            const code = <?= json_encode($bookmarkletCode) ?>;
            navigator.clipboard.writeText(code).then(() => {
                alert('Bookmarklet code copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy:', err);
                alert('Failed to copy. Please copy the code manually.');
            });
        }
    </script>
</body>

</html>