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
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 0;
            line-height: 1.6;
            background: #f5f5f5;
            color: #333;
        }

        .page-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .bookmarklet-box {
            background: #e8f4f8;
            border: 2px dashed #3498db;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            margin: 30px 0;
        }

        .bookmarklet-link {
            display: inline-block;
            padding: 15px 30px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: bold;
            cursor: move;
            transition: all 0.2s;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .bookmarklet-link:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .instructions {
            margin-top: 30px;
        }

        .instructions h2 {
            color: #2c3e50;
            font-size: 20px;
            margin-bottom: 15px;
        }

        .instructions ol {
            padding-left: 25px;
        }

        .instructions li {
            margin-bottom: 15px;
        }

        .instructions strong {
            color: #2c3e50;
        }

        .code-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            overflow-x: auto;
            margin: 15px 0;
            word-break: break-all;
        }

        .note {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }

        .success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }

        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            header,
            .content {
                padding: 15px;
            }

            .bookmarklet-box {
                padding: 20px;
            }

            .bookmarklet-link {
                padding: 12px 20px;
                font-size: 16px;
            }
        }
    </style>
</head>

<body>
    <?php render_nav($config, $isLoggedIn, 'bookmarklet-setup', 'Install Bookmarklet'); ?>

    <div class="page-container">
        <div class="content">
            <p>A bookmarklet lets you quickly save any webpage you're viewing to your bookmarks with just one click!</p>

            <div class="bookmarklet-box">
                <p style="margin-top: 0; color: #555; font-size: 14px;">Drag this button to your bookmarks bar:</p>
                <a href="<?= htmlspecialchars($bookmarkletCode) ?>" class="bookmarklet-link"
                    onclick="alert('Please drag this link to your bookmarks bar instead of clicking it!'); return false;">
                    📚 Add to Bookmarks
                </a>
                <p style="margin-bottom: 0; color: #7f8c8d; font-size: 13px; margin-top: 15px;">
                    (Click and drag to your bookmarks bar)
                </p>
            </div>

            <div class="instructions">
                <h2>Installation Instructions</h2>

                <ol>
                    <li>
                        <strong>Show your bookmarks bar</strong> if it's not already visible:
                        <ul style="margin-top: 8px; color: #555;">
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

            <div class="success">
                <strong>✓ How to use:</strong> When you're on a webpage you want to bookmark, just click the bookmarklet
                in
                your bookmarks bar. It will automatically capture the page title, URL, and metadata, and open a popup
                where
                you can review and save it.
            </div>

            <div class="note">
                <strong>Note:</strong> You need to stay logged in to <?= htmlspecialchars($config['site_title']) ?> for
                the
                bookmarklet to work. Your login session lasts for <?= intval($config['session_timeout'] / 86400) ?>
                days.
            </div>

            <details style="margin-top: 30px;">
                <summary style="cursor: pointer; color: #3498db; font-weight: bold;">Alternative: Manual Installation
                </summary>
                <div style="margin-top: 15px;">
                    <p>If dragging doesn't work in your browser, you can manually create a bookmark:</p>
                    <ol>
                        <li>Create a new bookmark in your bookmarks bar</li>
                        <li>Name it "Add to Bookmarks" (or whatever you like)</li>
                        <li>Copy the code below and paste it as the URL/Location:</li>
                    </ol>
                    <div class="code-box"><?= htmlspecialchars($bookmarkletCode) ?></div>
                    <button onclick="copyCode()"
                        style="padding: 8px 15px; background: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer;">
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