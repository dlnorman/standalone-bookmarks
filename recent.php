<?php
/**
 * Recent bookmarks widget - embeddable in Hugo site
 *
 * Usage in Hugo (modern syntax with markdown support and styling):
 * {{ $url := "https://yourdomain.com/bookmarks/recent.php?limit=5" }}
 * {{ $data := (resources.GetRemote $url).Content | transform.Unmarshal }}
 * <div class="recent-bookmarks">
 * {{ range $data }}
 *   <article class="bookmark-item">
 *     <h3 class="bookmark-title">
 *       <a href="{{ .url }}" target="_blank" rel="noopener">{{ .title }}</a>
 *     </h3>
 *     <div class="bookmark-content">
 *       {{ if .screenshot }}
 *       <div class="bookmark-screenshot">
 *         <img src="{{ .screenshot }}" alt="{{ .title }}">
 *       </div>
 *       {{ end }}
 *       {{ if .description }}
 *       <div class="bookmark-description">
 *         {{ .description | markdownify }}
 *       </div>
 *       {{ end }}
 *       <div class="bookmark-meta">
 *         <time datetime="{{ .created_at }}">{{ .created_at }}</time>
 *         {{ if .tags }} • {{ .tags }}{{ end }}
 *         {{ if .archive_url }} • <a href="{{ .archive_url }}" target="_blank">Archive</a>{{ end }}
 *       </div>
 *     </div>
 *   </article>
 * {{ end }}
 * </div>
 *
 * Add this CSS to your Hugo theme:
 * <style>
 * .recent-bookmarks {
 *   display: flex;
 *   flex-direction: column;
 *   gap: 2rem;
 * }
 * .bookmark-item {
 *   border: 1px solid #e5e7eb;
 *   border-radius: 8px;
 *   padding: 1.5rem;
 *   background: #fff;
 *   box-shadow: 0 1px 3px rgba(0,0,0,0.1);
 * }
 * .bookmark-item:hover {
 *   box-shadow: 0 4px 6px rgba(0,0,0,0.1);
 * }
 * .bookmark-title {
 *   margin: 0 0 1rem 0;
 *   font-size: 1.25rem;
 * }
 * .bookmark-content {
 *   overflow: auto;
 * }
 * .bookmark-screenshot {
 *   float: right;
 *   margin: 0 0 1rem 1.5rem;
 *   max-width: 300px;
 * }
 * .bookmark-screenshot img {
 *   width: 100%;
 *   height: auto;
 *   border-radius: 4px;
 *   box-shadow: 0 2px 4px rgba(0,0,0,0.1);
 * }
 * @media (max-width: 640px) {
 *   .bookmark-screenshot {
 *     float: none;
 *     max-width: 100%;
 *     margin: 0 0 1rem 0;
 *   }
 * }
 * .bookmark-title a {
 *   color: #1d4ed8;
 *   text-decoration: none;
 * }
 * .bookmark-title a:hover {
 *   text-decoration: underline;
 * }
 * .bookmark-description {
 *   margin-bottom: 1rem;
 *   line-height: 1.6;
 *   color: #374151;
 * }
 * .bookmark-meta {
 *   font-size: 0.875rem;
 *   color: #6b7280;
 * }
 * .bookmark-meta a {
 *   color: #6b7280;
 * }
 * </style>
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
