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

            } elseif ($job['job_type'] === 'check_url') {
                // Check if URL is accessible (not broken)
                $url = $job['payload'];

                // Validate URL for SSRF protection
                if (!is_safe_url($url)) {
                    $result = 'URL not allowed: Private/internal addresses are blocked';
                    $success = false;
                } else {
                    // Check URL accessibility with HEAD request first (faster)
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_NOBODY => true, // HEAD request
                        CURLOPT_FOLLOWLOCATION => false, // Don't follow redirects to prevent SSRF
                        CURLOPT_MAXREDIRS => 5,
                        CURLOPT_TIMEOUT => 15,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BookmarksApp/1.0; +URL-Checker)',
                        CURLOPT_HTTPHEADER => [
                            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
                        ]
                    ]);

                    curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                    curl_close($ch);

                    // Consider 2xx and 3xx codes as success
                    // 200-299: Success
                    // 300-399: Redirects (Link is valid, just points elsewhere)
                    // 401, 403: Unauthorized/Forbidden (site exists but requires auth - not broken)
                    // 405: Method Not Allowed (might not support HEAD, but site exists)
                    // 429: Rate limited (site exists)
                    $isBroken = false;
                    $statusMessage = "HTTP $httpCode";

                    if (!empty($curlError)) {
                        $isBroken = true;
                        $statusMessage = "Connection error: $curlError";
                    } elseif ($httpCode >= 200 && $httpCode < 400) {
                        $isBroken = false;
                        if ($httpCode >= 300) {
                            $statusMessage = "Redirect (HTTP $httpCode)";
                        } else {
                            $statusMessage = "OK (HTTP $httpCode)";
                        }
                    } elseif (in_array($httpCode, [401, 403, 405, 429])) {
                        // These codes mean the server responded, so URL is not broken
                        $isBroken = false;
                        $statusMessage = "Accessible but restricted (HTTP $httpCode)";
                    } elseif ($httpCode >= 400) {
                        $isBroken = true;
                        $statusMessage = "Broken (HTTP $httpCode)";
                    } elseif ($httpCode === 0) {
                        $isBroken = true;
                        $statusMessage = "No response";
                    }

                    // Update bookmark with broken status and last checked time
                    // First, check if broken_url column exists, if not add it
                    $columns = $db->query("PRAGMA table_info(bookmarks)")->fetchAll(PDO::FETCH_ASSOC);
                    $hasBrokenUrl = false;
                    $hasLastChecked = false;
                    foreach ($columns as $column) {
                        if ($column['name'] === 'broken_url')
                            $hasBrokenUrl = true;
                        if ($column['name'] === 'last_checked')
                            $hasLastChecked = true;
                    }

                    if (!$hasBrokenUrl) {
                        $db->exec("ALTER TABLE bookmarks ADD COLUMN broken_url INTEGER DEFAULT 0");
                    }
                    if (!$hasLastChecked) {
                        $db->exec("ALTER TABLE bookmarks ADD COLUMN last_checked DATETIME");
                    }

                    $db->prepare("UPDATE bookmarks SET broken_url = ?, last_checked = ?, updated_at = ? WHERE id = ?")
                        ->execute([$isBroken ? 1 : 0, $now, $now, $job['bookmark_id']]);

                    $result = $statusMessage;
                    $success = true;
                }
            }

        } catch (\Throwable $e) {
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
