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

// Require authentication for all operations
if (!is_logged_in()) {
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

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
