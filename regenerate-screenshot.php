<?php
/**
 * Regenerate Screenshot Endpoint
 *
 * AJAX endpoint to regenerate a screenshot for a bookmark using PageSpeed API
 */

// Start output buffering to catch any stray output
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors (they break JSON)
ini_set('log_errors', 1);

// Load configuration first (before session starts)
if (!file_exists(__DIR__ . '/config.php')) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'error' => 'Configuration file not found']));
}

$config = require __DIR__ . '/config.php';

// Now load auth (which starts session)
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/screenshot-generator.php';

// Clear any output that might have occurred
ob_end_clean();

// Set JSON content type
header('Content-Type: application/json');

try {

    // Set timezone
    if (isset($config['timezone'])) {
        date_default_timezone_set($config['timezone']);
    }

    // Must be logged in
    if (!is_logged_in()) {
        http_response_code(401);
        die(json_encode(['success' => false, 'error' => 'Not authenticated']));
    }

    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die(json_encode(['success' => false, 'error' => 'Method not allowed']));
    }

    // Get bookmark ID
    $bookmarkId = filter_input(INPUT_POST, 'bookmark_id', FILTER_VALIDATE_INT);

    if (!$bookmarkId) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'Invalid bookmark ID']));
    }
    // Connect to database
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get bookmark
    $stmt = $db->prepare("SELECT id, url, title, screenshot FROM bookmarks WHERE id = ?");
    $stmt->execute([$bookmarkId]);
    $bookmark = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bookmark) {
        http_response_code(404);
        die(json_encode(['success' => false, 'error' => 'Bookmark not found']));
    }

    // Initialize screenshot generator
    $generator = new ScreenshotGenerator($config);

    if (!$generator->isConfigured()) {
        die(json_encode([
            'success' => false,
            'error' => 'PageSpeed API key not configured. Add "pagespeed_api_key" to config.php'
        ]));
    }

    // Delete old screenshot if it exists
    $oldScreenshot = $bookmark['screenshot'];
    if ($oldScreenshot && file_exists(__DIR__ . '/' . $oldScreenshot)) {
        @unlink(__DIR__ . '/' . $oldScreenshot);
    }

    // Generate new screenshot (desktop view, resized to 300px)
    $result = $generator->generateAndSave($bookmark['url'], 'desktop', __DIR__ . '/screenshots');

    if (!$result['success']) {
        die(json_encode([
            'success' => false,
            'error' => 'Failed to generate screenshot: ' . $result['error']
        ]));
    }

    // Update database
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("UPDATE bookmarks SET screenshot = ?, updated_at = ? WHERE id = ?");
    $stmt->execute([$result['path'], $now, $bookmarkId]);

    // Return success with new screenshot path
    echo json_encode([
        'success' => true,
        'screenshot' => $result['path'],
        'message' => 'Screenshot regenerated successfully'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}

// Exit cleanly
exit;
