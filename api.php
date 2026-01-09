<?php
/**
 * API endpoint for bookmark operations
 * Handles: adding, editing, deleting, searching bookmarks
 */

header('Content-Type: application/json');

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration file not found']);
    exit;
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/markdown.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Public endpoints that don't require authentication
$publicEndpoints = ['dashboard_stats', 'get_tags', 'list'];

// Read-only endpoints that don't require CSRF tokens
$readOnlyEndpoints = ['get', 'list', 'fetch_meta', 'get_tags', 'dashboard_stats', 'check_status'];

// Require authentication for all operations except public endpoints
if (!in_array($action, $publicEndpoints) && !is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Require CSRF token for state-changing operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $readOnlyEndpoints)) {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate_token($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token. Please refresh the page and try again.']);
        exit;
    }
}

switch ($action) {
    case 'add':
        $url = trim($_POST['url'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $tags = trim($_POST['tags'] ?? '');
        $private = isset($_POST['private']) ? intval($_POST['private']) : 0;

        if (empty($url) || empty($title)) {
            http_response_code(400);
            echo json_encode(['error' => 'URL and title are required']);
            exit;
        }

        // Use PHP's current time (respects timezone setting) instead of SQLite's CURRENT_TIMESTAMP (always UTC)
        $now = date('Y-m-d H:i:s');

        $stmt = $db->prepare("
            INSERT INTO bookmarks (url, title, description, tags, private, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$url, $title, $description, $tags, $private, $now, $now]);

        $bookmarkId = $db->lastInsertId();

        // Queue background jobs for archiving and screenshot capture
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'")->fetchAll();
        if (!empty($tables)) {
            // Queue archive job
            $stmt = $db->prepare("
                INSERT INTO jobs (bookmark_id, job_type, payload, created_at, updated_at)
                VALUES (?, 'archive', ?, ?, ?)
            ");
            $stmt->execute([$bookmarkId, $url, $now, $now]);

            // Queue thumbnail job
            $stmt = $db->prepare("
                INSERT INTO jobs (bookmark_id, job_type, payload, created_at, updated_at)
                VALUES (?, 'thumbnail', ?, ?, ?)
            ");
            $stmt->execute([$bookmarkId, $url, $now, $now]);
        }

        echo json_encode([
            'success' => true,
            'id' => $bookmarkId,
            'message' => 'Bookmark added successfully'
        ]);
        break;

    case 'edit':
        $id = intval($_POST['id'] ?? 0);
        $url = trim($_POST['url'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $tags = trim($_POST['tags'] ?? '');
        $private = isset($_POST['private']) ? intval($_POST['private']) : 0;

        if ($id <= 0 || empty($url) || empty($title)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid input']);
            exit;
        }

        // Use PHP's current time (respects timezone setting)
        $now = date('Y-m-d H:i:s');

        $stmt = $db->prepare("
            UPDATE bookmarks
            SET url = ?, title = ?, description = ?, tags = ?, private = ?, updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$url, $title, $description, $tags, $private, $now, $id]);

        echo json_encode([
            'success' => true,
            'message' => 'Bookmark updated successfully'
        ]);
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid ID']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM bookmarks WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode([
            'success' => true,
            'message' => 'Bookmark deleted successfully'
        ]);
        break;

    case 'get':
        $id = intval($_GET['id'] ?? 0);

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid ID']);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM bookmarks WHERE id = ?");
        $stmt->execute([$id]);
        $bookmark = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bookmark) {
            http_response_code(404);
            echo json_encode(['error' => 'Bookmark not found']);
            exit;
        }

        echo json_encode($bookmark);
        break;

    case 'list':
        $search = $_GET['q'] ?? '';
        $tag = $_GET['tag'] ?? '';
        $showBroken = isset($_GET['broken']) && $_GET['broken'] === '1';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = intval($_GET['limit'] ?? $config['items_per_page']);
        $offset = ($page - 1) * $limit;
        $isLoggedIn = is_logged_in();

        // Check if broken_url column exists
        $columns = $db->query("PRAGMA table_info(bookmarks)")->fetchAll(PDO::FETCH_ASSOC);
        $hasBrokenUrl = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'broken_url') {
                $hasBrokenUrl = true;
                break;
            }
        }

        // Build query based on filters
        $whereConditions = [];
        $params = [];

        // Privacy filter
        if (!$isLoggedIn) {
            $whereConditions[] = "private = 0";
        }

        // Broken URL filter
        if ($showBroken && $hasBrokenUrl) {
            $whereConditions[] = "broken_url = 1";
        }

        // Tag filter
        if (!empty($tag)) {
            $tagPattern = '%,' . strtolower(trim($tag)) . ',%';
            $whereConditions[] = "',' || REPLACE(LOWER(tags), ', ', ',') || ',' LIKE ?";
            $params[] = $tagPattern;
        }

        // Search filter
        if (!empty($search)) {
            $searchTerm = '%' . $search . '%';
            $whereConditions[] = "(title LIKE ? OR description LIKE ? OR tags LIKE ? OR url LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Build WHERE clause
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        // Execute query
        $query = "SELECT * FROM bookmarks $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $stmt = $db->prepare($query);
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse markdown descriptions
        foreach ($bookmarks as &$bookmark) {
            if (!empty($bookmark['description'])) {
                $bookmark['description_html'] = parseMarkdown($bookmark['description']);
            } else {
                $bookmark['description_html'] = '';
            }
        }
        unset($bookmark); // Break reference

        // Count total
        $countQuery = "SELECT COUNT(*) as total FROM bookmarks $whereClause";
        $countStmt = $db->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        echo json_encode([
            'bookmarks' => $bookmarks,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]);
        break;

    case 'fetch_meta':
        // Fetch metadata from a URL for the bookmarklet
        $url = $_GET['url'] ?? '';

        if (empty($url)) {
            http_response_code(400);
            echo json_encode(['error' => 'URL is required']);
            exit;
        }

        // Validate URL for SSRF protection
        if (!is_safe_url($url)) {
            http_response_code(400);
            echo json_encode(['error' => 'URL not allowed: Private/internal addresses are blocked for security']);
            exit;
        }

        $meta = [
            'url' => $url,
            'title' => '',
            'description' => '',
            'tags' => ''
        ];

        // Fetch the page content safely
        $result = safe_fetch_url($url, 10);

        if (!$result['success']) {
            http_response_code(400);
            echo json_encode(['error' => $result['error']]);
            exit;
        }

        $html = $result['content'];

        if ($html) {
            // Extract title
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
                $meta['title'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            }

            // Extract meta description (try multiple patterns)
            // Pattern 1: name before content
            if (preg_match('/<meta\s+[^>]*name\s*=\s*["\']description["\']\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*>/is', $html, $matches)) {
                $meta['description'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            }
            // Pattern 2: content before name
            elseif (preg_match('/<meta\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*name\s*=\s*["\']description["\']/is', $html, $matches)) {
                $meta['description'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            }
            // Pattern 3: Open Graph description
            elseif (preg_match('/<meta\s+[^>]*property\s*=\s*["\']og:description["\']\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*>/is', $html, $matches)) {
                $meta['description'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            }
            // Pattern 4: OG content before property
            elseif (preg_match('/<meta\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*property\s*=\s*["\']og:description["\']/is', $html, $matches)) {
                $meta['description'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            }

            // Extract keywords/tags (try multiple patterns)
            if (preg_match('/<meta\s+[^>]*name\s*=\s*["\']keywords["\']\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*>/is', $html, $matches)) {
                $meta['tags'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<meta\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*name\s*=\s*["\']keywords["\']/is', $html, $matches)) {
                $meta['tags'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            }
        }

        echo json_encode($meta);
        break;

    case 'get_tags':
        // Get all unique tags for autocomplete

        // HTTP Caching: Get last modification time
        $isLoggedIn = is_logged_in();
        if ($isLoggedIn) {
            $lastModStmt = $db->query("SELECT MAX(updated_at) as last_mod FROM bookmarks WHERE tags IS NOT NULL AND tags != ''");
        } else {
            $lastModStmt = $db->query("SELECT MAX(updated_at) as last_mod FROM bookmarks WHERE tags IS NOT NULL AND tags != '' AND private = 0");
        }
        $lastMod = $lastModStmt->fetch(PDO::FETCH_ASSOC)['last_mod'];

        if ($lastMod) {
            $lastModTime = strtotime($lastMod);
            $etag = md5($lastMod . 'get_tags' . ($isLoggedIn ? 'auth' : 'public'));

            // Set caching headers (cache for 5 minutes)
            header('Cache-Control: public, max-age=300');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModTime) . ' GMT');
            header('ETag: "' . $etag . '"');

            // Check if client has cached version
            $ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;
            $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') : '';

            // Return 304 Not Modified if content hasn't changed
            if ($ifModifiedSince >= $lastModTime || $ifNoneMatch === $etag) {
                header('HTTP/1.1 304 Not Modified');
                exit;
            }
        }

        if ($isLoggedIn) {
            $stmt = $db->query("SELECT DISTINCT tags FROM bookmarks WHERE tags IS NOT NULL AND tags != ''");
        } else {
            $stmt = $db->query("SELECT DISTINCT tags FROM bookmarks WHERE tags IS NOT NULL AND tags != '' AND private = 0");
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse comma-separated tags and create unique list
        $allTags = [];
        foreach ($rows as $row) {
            $tags = array_map('trim', explode(',', $row['tags']));
            foreach ($tags as $tag) {
                if (!empty($tag) && !in_array($tag, $allTags)) {
                    $allTags[] = $tag;
                }
            }
        }

        // Sort alphabetically (case-insensitive)
        usort($allTags, function($a, $b) {
            return strcasecmp($a, $b);
        });

        echo json_encode(['tags' => $allTags]);
        break;

    case 'queue_url_checks':
        // Queue URL checks for all bookmarks
        $now = date('Y-m-d H:i:s');

        // Get all bookmarks
        $stmt = $db->query("SELECT id, url FROM bookmarks ORDER BY id");
        $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $queued = 0;
        $skipped = 0;
        foreach ($bookmarks as $bookmark) {
            // Check if there's already a pending or processing check_url job for this bookmark
            $existingJob = $db->prepare("
                SELECT id FROM jobs
                WHERE bookmark_id = ?
                AND job_type = 'check_url'
                AND status IN ('pending', 'processing')
            ");
            $existingJob->execute([$bookmark['id']]);

            if (!$existingJob->fetch()) {
                // Queue new check_url job
                $stmt = $db->prepare("
                    INSERT INTO jobs (bookmark_id, job_type, payload, created_at, updated_at)
                    VALUES (?, 'check_url', ?, ?, ?)
                ");
                $stmt->execute([$bookmark['id'], $bookmark['url'], $now, $now]);
                $queued++;
            } else {
                $skipped++;
            }
        }

        $message = "Queued $queued bookmark(s) for URL checking.";
        if ($skipped > 0) {
            $message .= " Skipped $skipped bookmark(s) that already have pending checks.";
        }
        if ($queued === 0 && $skipped === 0 && count($bookmarks) === 0) {
            $message = "No bookmarks found to check.";
        }

        echo json_encode([
            'success' => true,
            'queued' => $queued,
            'skipped' => $skipped,
            'total' => count($bookmarks),
            'message' => $message
        ]);
        break;

    case 'check_status':
        // Get status of URL check jobs
        $stats = $db->query("
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed
            FROM jobs
            WHERE job_type = 'check_url'
        ")->fetch(PDO::FETCH_ASSOC);

        // Check if broken_url column exists
        $columns = $db->query("PRAGMA table_info(bookmarks)")->fetchAll(PDO::FETCH_ASSOC);
        $hasBrokenUrl = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'broken_url') {
                $hasBrokenUrl = true;
                break;
            }
        }

        // Get broken bookmarks count (if column exists)
        $brokenCount = 0;
        if ($hasBrokenUrl) {
            $brokenCount = $db->query("
                SELECT COUNT(*) as count FROM bookmarks WHERE broken_url = 1
            ")->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Get recent check results
        $recentChecks = [];
        if ($hasBrokenUrl) {
            $recentChecks = $db->query("
                SELECT
                    j.id,
                    j.bookmark_id,
                    j.status,
                    j.result,
                    j.updated_at,
                    b.url,
                    b.title,
                    b.broken_url
                FROM jobs j
                JOIN bookmarks b ON j.bookmark_id = b.id
                WHERE j.job_type = 'check_url'
                ORDER BY j.updated_at DESC
                LIMIT 50
            ")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // If column doesn't exist yet, just show job status
            $recentChecks = $db->query("
                SELECT
                    j.id,
                    j.bookmark_id,
                    j.status,
                    j.result,
                    j.updated_at,
                    b.url,
                    b.title,
                    0 as broken_url
                FROM jobs j
                JOIN bookmarks b ON j.bookmark_id = b.id
                WHERE j.job_type = 'check_url'
                ORDER BY j.updated_at DESC
                LIMIT 50
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'stats' => $stats,
            'broken_count' => $brokenCount,
            'recent_checks' => $recentChecks
        ]);
        break;

    case 'dashboard_stats':
        // Get comprehensive dashboard statistics

        // HTTP Caching: Get last modification time
        $lastModStmt = $db->query("SELECT MAX(updated_at) as last_mod FROM bookmarks WHERE private = 0");
        $lastMod = $lastModStmt->fetch(PDO::FETCH_ASSOC)['last_mod'];

        if ($lastMod) {
            $lastModTime = strtotime($lastMod);
            $etag = md5($lastMod . 'dashboard_stats');

            // Set caching headers (cache for 5 minutes)
            header('Cache-Control: public, max-age=300');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModTime) . ' GMT');
            header('ETag: "' . $etag . '"');

            // Check if client has cached version
            $ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;
            $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') : '';

            // Return 304 Not Modified if content hasn't changed
            if ($ifModifiedSince >= $lastModTime || $ifNoneMatch === $etag) {
                header('HTTP/1.1 304 Not Modified');
                exit;
            }
        }

        // Basic stats
        $basicStats = $db->query("
            SELECT
                COUNT(*) as total_bookmarks,
                COUNT(CASE WHEN private = 0 THEN 1 END) as public_bookmarks,
                COUNT(CASE WHEN private = 1 THEN 1 END) as private_bookmarks,
                COUNT(CASE WHEN screenshot IS NOT NULL AND screenshot != '' THEN 1 END) as with_screenshots,
                COUNT(CASE WHEN archive_url IS NOT NULL AND archive_url != '' THEN 1 END) as with_archives,
                COUNT(CASE WHEN description IS NOT NULL AND description != '' THEN 1 END) as with_descriptions,
                MIN(created_at) as first_bookmark,
                MAX(created_at) as last_bookmark
            FROM bookmarks
        ")->fetch(PDO::FETCH_ASSOC);

        // Activity timeline (last 90 days)
        $timeline = $db->query("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM bookmarks
            WHERE created_at >= date('now', '-90 days')
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Tag statistics with co-occurrence data
        $tagRows = $db->query("
            SELECT tags, created_at
            FROM bookmarks
            WHERE tags IS NOT NULL AND tags != ''
        ")->fetchAll(PDO::FETCH_ASSOC);

        $tagFrequency = [];
        $tagCooccurrence = [];
        $tagFirstSeen = [];

        foreach ($tagRows as $row) {
            $tags = array_map('trim', explode(',', $row['tags']));
            $normalizedTags = array_map('strtolower', $tags);

            // Track tag frequency and first appearance
            foreach ($tags as $i => $tag) {
                $normalizedTag = $normalizedTags[$i];

                if (!isset($tagFrequency[$normalizedTag])) {
                    $tagFrequency[$normalizedTag] = ['count' => 0, 'display' => $tag];
                    $tagFirstSeen[$normalizedTag] = $row['created_at'];
                }
                $tagFrequency[$normalizedTag]['count']++;

                // Update first seen if this is earlier
                if ($row['created_at'] < $tagFirstSeen[$normalizedTag]) {
                    $tagFirstSeen[$normalizedTag] = $row['created_at'];
                }
            }

            // Track tag co-occurrence
            if (count($tags) > 1) {
                for ($i = 0; $i < count($normalizedTags); $i++) {
                    for ($j = $i + 1; $j < count($normalizedTags); $j++) {
                        $tag1 = $normalizedTags[$i];
                        $tag2 = $normalizedTags[$j];

                        // Create consistent key (alphabetically sorted)
                        $key = ($tag1 < $tag2) ? "$tag1|$tag2" : "$tag2|$tag1";

                        if (!isset($tagCooccurrence[$key])) {
                            $tagCooccurrence[$key] = 0;
                        }
                        $tagCooccurrence[$key]++;
                    }
                }
            }
        }

        // Sort tags by frequency and prepare output
        uasort($tagFrequency, function($a, $b) {
            return $b['count'] - $a['count'];
        });

        $tagStats = [];
        foreach ($tagFrequency as $normalizedTag => $data) {
            $tagStats[] = [
                'tag' => $data['display'],
                'count' => $data['count'],
                'first_seen' => $tagFirstSeen[$normalizedTag]
            ];
        }

        // Prepare co-occurrence data (only for top tags)
        $topTagNames = array_map('strtolower', array_column($tagStats, 'tag'));
        $cooccurrenceData = [];
        foreach ($tagCooccurrence as $key => $count) {
            list($tag1, $tag2) = explode('|', $key);
            // Only include if both tags are in top tags
            if (in_array($tag1, $topTagNames) && in_array($tag2, $topTagNames)) {
                $cooccurrenceData[] = [
                    'source' => $tag1,
                    'target' => $tag2,
                    'count' => $count
                ];
            }
        }

        // Domain statistics
        $domainStats = [];
        $urlRows = $db->query("SELECT url FROM bookmarks")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($urlRows as $row) {
            $url = $row['url'];
            $host = parse_url($url, PHP_URL_HOST);
            if ($host) {
                // Remove www. prefix
                $host = preg_replace('/^www\./', '', $host);
                if (!isset($domainStats[$host])) {
                    $domainStats[$host] = 0;
                }
                $domainStats[$host]++;
            }
        }
        arsort($domainStats);
        $topDomains = array_slice($domainStats, 0, 10);

        // Tag activity over time - use DAILY granularity for better resolution
        $dailyTagData = $db->query("
            SELECT
                DATE(created_at) as date,
                tags
            FROM bookmarks
            WHERE tags IS NOT NULL
                AND tags != ''
            ORDER BY created_at ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Process into daily tag counts
        $dailyTagCounts = [];
        $allTagsSet = new stdClass(); // Use object as set

        foreach ($dailyTagData as $row) {
            $date = $row['date'];
            $tags = array_map('trim', explode(',', $row['tags']));

            if (!isset($dailyTagCounts[$date])) {
                $dailyTagCounts[$date] = [];
            }

            foreach ($tags as $tag) {
                $normalizedTag = strtolower($tag);
                if (!isset($dailyTagCounts[$date][$normalizedTag])) {
                    $dailyTagCounts[$date][$normalizedTag] = ['count' => 0, 'display' => $tag];
                }
                $dailyTagCounts[$date][$normalizedTag]['count']++;
                $allTagsSet->$normalizedTag = true;
            }
        }

        // Get top tags for the period
        $tagTotals = [];
        foreach ($dailyTagCounts as $date => $tags) {
            foreach ($tags as $normalizedTag => $data) {
                if (!isset($tagTotals[$normalizedTag])) {
                    $tagTotals[$normalizedTag] = ['count' => 0, 'display' => $data['display']];
                }
                $tagTotals[$normalizedTag]['count'] += $data['count'];
            }
        }

        // Sort by count (descending)
        uasort($tagTotals, function($a, $b) {
            return $b['count'] - $a['count'];
        });

        $topTagsForChart = array_slice($tagTotals, 0, 10, true);

        // Build the final data structure with daily data
        $tagActivity = [];
        foreach ($topTagsForChart as $normalizedTag => $data) {
            $days = [];
            foreach ($dailyTagCounts as $date => $tagData) {
                if (isset($tagData[$normalizedTag])) {
                    $days[] = [
                        'date' => $date,
                        'count' => $tagData[$normalizedTag]['count']
                    ];
                }
            }
            $tagActivity[] = [
                'tag' => $data['display'],
                'days' => $days,
                'total' => $data['count']
            ];
        }

        // Prepare final output
        echo json_encode([
            'basic_stats' => $basicStats,
            'timeline' => $timeline,
            'tag_stats' => $tagStats,
            'tag_cooccurrence' => $cooccurrenceData,
            'top_domains' => $topDomains,
            'tag_activity_heatmap' => $tagActivity
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
