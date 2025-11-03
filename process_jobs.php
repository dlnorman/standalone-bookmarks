<?php
/**
 * Background job processor
 * Run this via cron every few minutes to process pending jobs
 * Example: Run every 5 minutes with cron
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die("Error: config.php not found.\n");
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/screenshot-generator.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

/**
 * Resize image if wider than max width, maintaining aspect ratio
 * @param string $imageData Binary image data
 * @param string $mimeType MIME type of the image
 * @param int $maxWidth Maximum width (default 1200px)
 * @return string|false Resized image data or original if resize not needed/possible
 */
function resize_image($imageData, $mimeType, $maxWidth = 1200) {
    // Check if GD is available
    if (!extension_loaded('gd')) {
        return $imageData; // Return original if GD not available
    }

    // Create image resource from data based on type
    $image = false;
    switch ($mimeType) {
        case 'image/jpeg':
            $image = @imagecreatefromstring($imageData);
            break;
        case 'image/png':
            $image = @imagecreatefromstring($imageData);
            break;
        case 'image/gif':
            $image = @imagecreatefromstring($imageData);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $image = @imagecreatefromstring($imageData);
            }
            break;
        default:
            return $imageData; // Unsupported format, return original
    }

    if (!$image) {
        return $imageData; // Failed to create image, return original
    }

    // Get dimensions
    $width = imagesx($image);
    $height = imagesy($image);

    // Check if resize is needed
    if ($width <= $maxWidth) {
        imagedestroy($image);
        return $imageData; // No resize needed
    }

    // Calculate new dimensions maintaining aspect ratio
    $newWidth = $maxWidth;
    $newHeight = (int)($height * ($maxWidth / $width));

    // Create new image
    $newImage = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG and GIF
    if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
    }

    // Resize
    imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Output to buffer
    ob_start();
    $success = false;
    switch ($mimeType) {
        case 'image/jpeg':
            $success = imagejpeg($newImage, null, 85); // 85% quality for compression
            break;
        case 'image/png':
            $success = imagepng($newImage, null, 8); // Compression level 8
            break;
        case 'image/gif':
            $success = imagegif($newImage);
            break;
        case 'image/webp':
            if (function_exists('imagewebp')) {
                $success = imagewebp($newImage, null, 85);
            }
            break;
    }

    $resizedData = $success ? ob_get_clean() : false;

    // Clean up
    imagedestroy($image);
    imagedestroy($newImage);

    return $resizedData ?: $imageData; // Return resized or original if failed
}

try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get pending jobs (limit to 3 per run since PageSpeed screenshots take 10-30s each)
    $stmt = $db->prepare("
        SELECT * FROM jobs
        WHERE status = 'pending' AND attempts < 3
        ORDER BY created_at ASC
        LIMIT 3
    ");
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Processing " . count($jobs) . " jobs...\n";

    foreach ($jobs as $job) {
        echo "Job #{$job['id']}: {$job['job_type']} for bookmark #{$job['bookmark_id']}... ";

        // Mark job as processing
        $now = date('Y-m-d H:i:s');
        $db->prepare("UPDATE jobs SET status = 'processing', updated_at = ? WHERE id = ?")
           ->execute([$now, $job['id']]);

        $success = false;
        $result = '';

        try {
            if ($job['job_type'] === 'archive') {
                // Archive with Wayback Machine
                $url = $job['payload'];

                // Validate URL for SSRF protection
                if (!is_safe_url($url)) {
                    $result = 'URL not allowed: Private/internal addresses are blocked';
                    $success = false;
                } else {
                    $archiveApiUrl = 'https://web.archive.org/save/' . $url;

                    $context = stream_context_create([
                        'http' => [
                            'method' => 'GET',
                            'timeout' => 30,
                            'user_agent' => 'Mozilla/5.0 (compatible; BookmarksApp/1.0)',
                            'follow_location' => false,
                        ]
                    ]);

                    $response = @file_get_contents($archiveApiUrl, false, $context);
                $archiveUrl = '';

                if (isset($http_response_header)) {
                    foreach ($http_response_header as $header) {
                        if (stripos($header, 'Location:') === 0) {
                            $archiveUrl = trim(substr($header, 9));
                            break;
                        }
                        if (stripos($header, 'Content-Location:') === 0) {
                            $archiveUrl = trim(substr($header, 17));
                            break;
                        }
                    }
                }

                if (empty($archiveUrl)) {
                    $archiveUrl = 'https://web.archive.org/web/*/' . $url;
                }

                // Update bookmark with archive URL
                $db->prepare("UPDATE bookmarks SET archive_url = ?, updated_at = ? WHERE id = ?")
                   ->execute([$archiveUrl, $now, $job['bookmark_id']]);

                    $result = $archiveUrl;
                    $success = true;
                }

            } elseif ($job['job_type'] === 'thumbnail') {
                // Generate screenshot using fallback chain:
                // 1. PageSpeed API screenshot
                // 2. og:image from HTML
                // 3. First content image
                $url = $job['payload'];

                // Validate URL for SSRF protection
                if (!is_safe_url($url)) {
                    $result = 'URL not allowed: Private/internal addresses are blocked';
                    $success = false;
                } else {
                    // Initialize screenshot generator
                    $generator = new ScreenshotGenerator($config);

                    // Use fallback chain for robust screenshot generation
                    $screenshotResult = $generator->generateWithFallback($url, 'desktop', __DIR__ . '/screenshots');

                    if ($screenshotResult['success']) {
                        // Update bookmark with screenshot path
                        $db->prepare("UPDATE bookmarks SET screenshot = ?, updated_at = ? WHERE id = ?")
                           ->execute([$screenshotResult['path'], $now, $job['bookmark_id']]);

                        $method = $screenshotResult['method'] ?? 'unknown';
                        $result = $screenshotResult['path'] . " (via {$method})";
                        $success = true;
                    } else {
                        $result = 'All screenshot methods failed: ' . $screenshotResult['error'];
                        $success = false;
                    }
                }
            }

        } catch (Exception $e) {
            $result = 'Error: ' . $e->getMessage();
        }

        // Update job status
        $attempts = $job['attempts'] + 1;
        if ($success) {
            $db->prepare("UPDATE jobs SET status = 'completed', result = ?, attempts = ?, updated_at = ? WHERE id = ?")
               ->execute([$result, $attempts, $now, $job['id']]);
            echo "✓ Success: $result\n";
        } else {
            $status = ($attempts >= 3) ? 'failed' : 'pending';
            $db->prepare("UPDATE jobs SET status = ?, result = ?, attempts = ?, updated_at = ? WHERE id = ?")
               ->execute([$status, $result, $attempts, $now, $job['id']]);
            echo "✗ Failed (attempt $attempts/3): $result\n";
        }
    }

    echo "Done!\n";

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}
