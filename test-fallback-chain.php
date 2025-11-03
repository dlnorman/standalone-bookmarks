<?php
/**
 * Test Script for Screenshot Fallback Chain
 *
 * Tests the three-tier fallback system:
 * 1. PageSpeed API screenshot
 * 2. og:image from HTML meta tags
 * 3. First content image from page
 */

// Check if config.php exists, otherwise use example config
if (file_exists(__DIR__ . '/config.php')) {
    $config = require __DIR__ . '/config.php';
} else {
    echo "Notice: config.php not found, using minimal test config (PageSpeed will be skipped)\n\n";
    $config = [
        'db_path' => __DIR__ . '/bookmarks.db',
        'timezone' => 'UTC',
        'screenshot_max_width' => 300,
        // PageSpeed API key not configured - will test fallback methods
    ];
}

require_once __DIR__ . '/includes/screenshot-generator.php';
$generator = new ScreenshotGenerator($config);

echo "=== Screenshot Fallback Chain Test ===\n\n";

// Test URLs (you can modify these)
$testUrls = [
    // Test with a URL that should work with PageSpeed
    'https://example.com',

    // Test with a URL that has og:image (will likely fail PageSpeed if quota exceeded)
    'https://github.com',

    // Test with a blog post that has content images
    'https://blog.praveen.science',
];

foreach ($testUrls as $url) {
    echo "Testing: $url\n";
    echo str_repeat('-', 60) . "\n";

    // Test individual methods
    echo "\n1. Testing PageSpeed API...\n";
    $pagespeedResult = $generator->generateScreenshot($url, 'desktop');
    if ($pagespeedResult['success']) {
        echo "   ✓ PageSpeed API: Success ({$pagespeedResult['size']} bytes)\n";
    } else {
        echo "   ✗ PageSpeed API: {$pagespeedResult['error']}\n";
    }

    echo "\n2. Testing og:image extraction...\n";
    $ogImageResult = $generator->getOgImage($url);
    if ($ogImageResult['success']) {
        echo "   ✓ og:image found: {$ogImageResult['url']}\n";
    } else {
        echo "   ✗ og:image: {$ogImageResult['error']}\n";
    }

    echo "\n3. Testing content image extraction...\n";
    $contentImageResult = $generator->getFirstContentImage($url);
    if ($contentImageResult['success']) {
        echo "   ✓ Content image found: {$contentImageResult['url']}\n";
    } else {
        echo "   ✗ Content image: {$contentImageResult['error']}\n";
    }

    echo "\n4. Testing full fallback chain...\n";
    $fallbackResult = $generator->generateWithFallback($url, 'desktop', __DIR__ . '/screenshots');
    if ($fallbackResult['success']) {
        echo "   ✓ SUCCESS via {$fallbackResult['method']}\n";
        echo "   Path: {$fallbackResult['path']}\n";
    } else {
        echo "   ✗ FAILED: {$fallbackResult['error']}\n";
    }

    echo "\n" . str_repeat('=', 60) . "\n\n";
}

echo "Test complete!\n";
