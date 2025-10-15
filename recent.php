<?php
/**
 * Recent bookmarks widget - embeddable in Hugo site
 *
 * Usage in Hugo (modern syntax):
 * {{ $url := "https://yourdomain.com/bookmarks/recent.php?limit=5" }}
 * {{ $data := (resources.GetRemote $url).Content | transform.Unmarshal }}
 * {{ range $data }}
 *   <div>
 *     {{ if .screenshot }}<img src="{{ .screenshot }}" alt="{{ .title }}">{{ end }}
 *     <a href="{{ .url }}">{{ .title }}</a>
 *     {{ if .description }}<p>{{ .description }}</p>{{ end }}
 *     <small>{{ .created_at }}</small>
 *     {{ if .archive_url }} | <a href="{{ .archive_url }}">Archive</a>{{ end }}
 *   </div>
 * {{ end }}
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration file not found']);
    exit;
}

$config = require __DIR__ . '/config.php';

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

// Get limit from query string
$limit = min(100, max(1, intval($_GET['limit'] ?? $config['recent_limit'])));

// Check if we should include private bookmarks
$includePrivate = isset($_GET['include_private']) && $_GET['include_private'] === '1';

// Fetch recent bookmarks
if ($includePrivate) {
    $stmt = $db->prepare("
        SELECT id, url, title, description, tags, screenshot, archive_url, created_at
        FROM bookmarks
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
} else {
    // Exclude private bookmarks by default
    $stmt = $db->prepare("
        SELECT id, url, title, description, tags, screenshot, archive_url, created_at
        FROM bookmarks
        WHERE private = 0
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
}
$bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Add full URLs for screenshots
foreach ($bookmarks as &$bookmark) {
    if (!empty($bookmark['screenshot'])) {
        $bookmark['screenshot'] = $config['site_url'] . '/' . $bookmark['screenshot'];
    }
}

// Set content type and allow cross-origin requests (for embedding)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Output JSON
echo json_encode($bookmarks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
