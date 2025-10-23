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

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get pending jobs (limit to 10 per run to avoid timeout)
    $stmt = $db->prepare("
        SELECT * FROM jobs
        WHERE status = 'pending' AND attempts < 3
        ORDER BY created_at ASC
        LIMIT 10
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
                // Fetch thumbnail from OG image or favicon
                $url = $job['payload'];

                // Validate URL for SSRF protection
                if (!is_safe_url($url)) {
                    $result = 'URL not allowed: Private/internal addresses are blocked';
                    $success = false;
                } else {
                    // First, fetch the page to extract thumbnail URL
                    $fetchResult = safe_fetch_url($url, 10);

                    if (!$fetchResult['success']) {
                        $result = 'Failed to fetch page: ' . $fetchResult['error'];
                        $html = false;
                    } else {
                        $html = $fetchResult['content'];
                    }
                }
                $thumbnailUrl = '';

                if (isset($html) && $html) {
                    // Try OG image (multiple patterns)
                    if (preg_match('/<meta\s+[^>]*property\s*=\s*["\']og:image["\']\s+[^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*>/is', $html, $matches)) {
                        $thumbnailUrl = $matches[1];
                    } elseif (preg_match('/<meta\s+[^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*property\s*=\s*["\']og:image["\']/is', $html, $matches)) {
                        $thumbnailUrl = $matches[1];
                    }
                    // Try Twitter image
                    elseif (preg_match('/<meta\s+[^>]*name\s*=\s*["\']twitter:image["\']\s+[^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*>/is', $html, $matches)) {
                        $thumbnailUrl = $matches[1];
                    } elseif (preg_match('/<meta\s+[^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*name\s*=\s*["\']twitter:image["\']/is', $html, $matches)) {
                        $thumbnailUrl = $matches[1];
                    }
                    // Try favicon (multiple patterns)
                    elseif (preg_match('/<link\s+[^>]*rel\s*=\s*["\'](?:icon|shortcut icon|apple-touch-icon)["\']\s+[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>/is', $html, $matches)) {
                        $thumbnailUrl = $matches[1];
                    } elseif (preg_match('/<link\s+[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*rel\s*=\s*["\'](?:icon|shortcut icon|apple-touch-icon)["\']/is', $html, $matches)) {
                        $thumbnailUrl = $matches[1];
                    }

                    if (empty($thumbnailUrl)) {
                        $result = 'No thumbnail found (no OG image, Twitter image, or favicon)';
                    }
                } else {
                    $result = 'Failed to fetch page HTML';
                }

                if (!empty($thumbnailUrl)) {
                    // Make absolute URL
                    if (!preg_match('~^https?://~', $thumbnailUrl)) {
                        $urlParts = parse_url($url);
                        $base = $urlParts['scheme'] . '://' . $urlParts['host'];
                        if (substr($thumbnailUrl, 0, 2) === '//') {
                            $thumbnailUrl = $urlParts['scheme'] . ':' . $thumbnailUrl;
                        } elseif (strpos($thumbnailUrl, '/') === 0) {
                            $thumbnailUrl = $base . $thumbnailUrl;
                        } else {
                            $thumbnailUrl = $base . '/' . $thumbnailUrl;
                        }
                    }

                    // Download thumbnail
                    $imageData = @file_get_contents($thumbnailUrl, false, $context);

                    if ($imageData && strlen($imageData) > 0) {
                        // Parse domain for directory
                        $urlParts = parse_url($url);
                        $domain = $urlParts['host'] ?? 'unknown';
                        $domain = preg_replace('/[^a-z0-9\-\.]/', '_', strtolower($domain));

                        // Create directory
                        $domainDir = __DIR__ . '/screenshots/' . $domain;
                        if (!is_dir($domainDir)) {
                            mkdir($domainDir, 0755, true);
                        }

                        // Detect image type
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo->buffer($imageData);
                        $mimeToExt = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/gif' => 'gif',
                            'image/webp' => 'webp',
                            'image/svg+xml' => 'svg',
                            'image/x-icon' => 'ico',
                        ];
                        $extension = $mimeToExt[$mimeType] ?? 'png';

                        // Save file
                        $timestamp = time();
                        $filename = $timestamp . '_' . substr(md5($url . $now), 0, 8) . '.' . $extension;
                        $filePath = $domainDir . '/' . $filename;

                        if (file_put_contents($filePath, $imageData)) {
                            $screenshotPath = 'screenshots/' . $domain . '/' . $filename;

                            // Update bookmark
                            $db->prepare("UPDATE bookmarks SET screenshot = ?, updated_at = ? WHERE id = ?")
                               ->execute([$screenshotPath, $now, $job['bookmark_id']]);

                            $result = $screenshotPath;
                            $success = true;
                        }
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
