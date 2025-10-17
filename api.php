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
$publicEndpoints = ['dashboard_stats'];

// Require authentication for all operations except public endpoints
if (!in_array($action, $publicEndpoints) && !is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
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

        echo json_encode([
            'success' => true,
            'id' => $db->lastInsertId(),
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
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = intval($_GET['limit'] ?? $config['items_per_page']);
        $offset = ($page - 1) * $limit;

        // Build query
        if (!empty($search)) {
            $searchTerm = '%' . $search . '%';
            $stmt = $db->prepare("
                SELECT * FROM bookmarks
                WHERE title LIKE ? OR description LIKE ? OR tags LIKE ? OR url LIKE ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit, $offset]);

            $countStmt = $db->prepare("
                SELECT COUNT(*) as total FROM bookmarks
                WHERE title LIKE ? OR description LIKE ? OR tags LIKE ? OR url LIKE ?
            ");
            $countStmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        } else {
            $stmt = $db->prepare("
                SELECT * FROM bookmarks
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);

            $countStmt = $db->query("SELECT COUNT(*) as total FROM bookmarks");
        }

        $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        $meta = [
            'url' => $url,
            'title' => '',
            'description' => '',
            'tags' => ''
        ];

        // Fetch the page content
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Mozilla/5.0 (compatible; BookmarksApp/1.0)',
            ]
        ]);

        $html = @file_get_contents($url, false, $context);

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
        $stmt = $db->query("SELECT DISTINCT tags FROM bookmarks WHERE tags IS NOT NULL AND tags != ''");
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

    case 'dashboard_stats':
        // Get comprehensive dashboard statistics

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

        $topTags = array_slice($tagFrequency, 0, 30);
        $tagStats = [];
        foreach ($topTags as $normalizedTag => $data) {
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

        // Prepare final output
        echo json_encode([
            'basic_stats' => $basicStats,
            'timeline' => $timeline,
            'tag_stats' => $tagStats,
            'tag_cooccurrence' => $cooccurrenceData,
            'top_domains' => $topDomains
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
