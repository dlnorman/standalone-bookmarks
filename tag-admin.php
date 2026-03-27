<?php
/**
 * Tag Management Admin Page
 * Provides tools for renaming, merging, deleting, and changing tag types
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/tags.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Require admin authentication
require_admin($config);

$success_msg = '';
$error_msg = '';

// Connect to database
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Ensure tag_connections table exists (for existing installs that haven't re-run db_setup)
$db->exec("CREATE TABLE IF NOT EXISTS tag_connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_from TEXT NOT NULL,
    tag_to TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tag_from, tag_to)
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_tag_connections_from ON tag_connections(tag_from)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_tag_connections_to ON tag_connections(tag_to)");

// Ensure tag_aliases table exists (for existing installs that haven't re-run db_setup)
$db->exec("CREATE TABLE IF NOT EXISTS tag_aliases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alias TEXT NOT NULL UNIQUE,
    canonical TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_tag_aliases_alias ON tag_aliases(alias)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_tag_aliases_canonical ON tag_aliases(canonical)");

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_token();

    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'rename':
                    $oldTag = $_POST['old_tag'] ?? '';
                    $newTag = $_POST['new_tag'] ?? '';

                    if (empty($oldTag) || empty($newTag)) {
                        $error_msg = 'Both old and new tag names are required.';
                    } else {
                        $count = renameTag($db, $oldTag, $newTag);
                        $success_msg = "Renamed tag across $count bookmark(s).";
                    }
                    break;

                case 'merge':
                    $sourceTags = $_POST['source_tags'] ?? [];
                    $targetTag = $_POST['target_tag'] ?? '';

                    if (empty($sourceTags) || empty($targetTag)) {
                        $error_msg = 'Source tag(s) and target tag are required.';
                    } else {
                        if (!is_array($sourceTags)) {
                            $sourceTags = [$sourceTags];
                        }
                        $count = mergeTags($db, $sourceTags, $targetTag);
                        $success_msg = "Merged tags across $count bookmark(s).";
                    }
                    break;

                case 'delete':
                    $tag = $_POST['tag'] ?? '';

                    if (empty($tag)) {
                        $error_msg = 'Tag name is required.';
                    } else {
                        $count = deleteTag($db, $tag);
                        $success_msg = "Deleted tag from $count bookmark(s).";
                    }
                    break;

                case 'change_type':
                    $tag = $_POST['tag'] ?? '';
                    $newType = $_POST['new_type'] ?? '';

                    if (empty($tag) || empty($newType)) {
                        $error_msg = 'Tag and new type are required.';
                    } elseif (!in_array($newType, ['tag', 'person', 'via'])) {
                        $error_msg = 'Invalid tag type.';
                    } else {
                        $count = changeTagType($db, $tag, $newType);
                        $success_msg = "Changed tag type across $count bookmark(s).";
                    }
                    break;

                case 'add_connection':
                    $tagA = trim($_POST['tag_a'] ?? '');
                    $tagB = trim($_POST['tag_b'] ?? '');
                    if (empty($tagA) || empty($tagB)) {
                        $error_msg = 'Both tags are required to create a connection.';
                    } elseif (strtolower($tagA) === strtolower($tagB)) {
                        $error_msg = 'Cannot connect a tag to itself.';
                    } else {
                        $added = addTagConnection($db, $tagA, $tagB);
                        header('Location: tag-admin.php?tab=connections&msg=' . urlencode($added ? "Connection added: $tagA ↔ $tagB" : "Connection already exists."));
                        exit;
                    }
                    break;

                case 'remove_connection':
                    $tagA = trim($_POST['tag_a'] ?? '');
                    $tagB = trim($_POST['tag_b'] ?? '');
                    if (empty($tagA) || empty($tagB)) {
                        $error_msg = 'Both tags are required.';
                    } else {
                        $removed = removeTagConnection($db, $tagA, $tagB);
                        header('Location: tag-admin.php?tab=connections&msg=' . urlencode($removed ? "Connection removed: $tagA ↔ $tagB" : "Connection not found."));
                        exit;
                    }
                    break;

                case 'define_alias':
                    $alias = trim($_POST['alias_tag'] ?? '');
                    $canonical = trim($_POST['canonical_tag'] ?? '');
                    if (empty($alias) || empty($canonical)) {
                        $error_msg = 'Both alias and canonical tag names are required.';
                    } else {
                        $result = defineAlias($db, $alias, $canonical);
                        if ($result === false) {
                            $error_msg = 'Could not define alias — it may already exist, or would create a chain (aliases cannot point to other aliases).';
                        } else {
                            $success_msg = "Alias defined: \"{$alias}\" → \"{$canonical}\".";
                        }
                    }
                    break;

                case 'remove_alias':
                    $alias = trim($_POST['alias_tag'] ?? '');
                    if (empty($alias)) {
                        $error_msg = 'Alias name is required.';
                    } else {
                        $removed = removeAlias($db, $alias);
                        $success_msg = $removed ? "Alias \"{$alias}\" removed." : "Alias not found.";
                    }
                    break;
            }
        } catch (Exception $e) {
            $error_msg = 'Operation failed: ' . htmlspecialchars($e->getMessage()) . '. No changes were made.';
        }
    }
}

// Determine active tab
$activeTab = $_GET['tab'] ?? 'tags';
if (!in_array($activeTab, ['tags', 'connections', 'aliases'])) $activeTab = 'tags';

// Pick up redirect messages
if (!empty($_GET['msg']) && empty($success_msg)) {
    $success_msg = $_GET['msg']; // Will be escaped in template via htmlspecialchars
}

// Get filter parameters
$filterType = $_GET['type'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';
$sortBy = $_GET['sort'] ?? 'name';

// Fetch all tags with counts
$allTags = getAllTagsWithCounts($db, true);

// Process tags into displayable format with type info
$processedTags = [];
foreach ($allTags as $tagLower => $data) {
    $parsed = parseTagType($data['display']);
    $processedTags[] = [
        'full' => $data['display'],
        'name' => $parsed['name'],
        'type' => $parsed['type'],
        'count' => $data['count'],
    ];
}

// Filter by type
if ($filterType !== 'all') {
    $processedTags = array_filter($processedTags, function($t) use ($filterType) {
        return $t['type'] === $filterType;
    });
}

// Filter by search
if (!empty($searchQuery)) {
    $searchLower = strtolower($searchQuery);
    $processedTags = array_filter($processedTags, function($t) use ($searchLower) {
        return strpos(strtolower($t['name']), $searchLower) !== false;
    });
}

// Sort tags
if ($sortBy === 'count') {
    usort($processedTags, function($a, $b) {
        return $b['count'] - $a['count'];
    });
} else {
    usort($processedTags, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
}

// Get all unique tags for dropdowns
$allTagsList = array_map(function($t) { return $t['full']; }, $processedTags);
sort($allTagsList, SORT_STRING | SORT_FLAG_CASE);

// Count by type for stats
$typeCounts = ['tag' => 0, 'person' => 0, 'via' => 0];
foreach ($allTags as $tagLower => $data) {
    $parsed = parseTagType($data['display']);
    $typeCounts[$parsed['type']]++;
}

// Fetch explicit connections for the Connections tab
$allConnections = getAllTagConnections($db);

// Build display name map for connections (lowercase → display)
$tagDisplayMap = [];
foreach ($allTags as $tagLower => $data) {
    $tagDisplayMap[$tagLower] = $data['display'];
}

// Compute co-occurrence suggestions (top pairs not yet explicitly connected)
$coSuggestions = [];
$tagRows = $db->query("SELECT tags FROM bookmarks WHERE tags IS NOT NULL AND tags != ''")->fetchAll(PDO::FETCH_COLUMN);
$tagCooccurrence = [];
foreach ($tagRows as $tagStr) {
    $tags = array_unique(array_map('strtolower', array_map('trim', explode(',', $tagStr))));
    $tags = array_values(array_filter($tags));
    for ($i = 0; $i < count($tags); $i++) {
        for ($j = $i + 1; $j < count($tags); $j++) {
            $a = $tags[$i]; $b = $tags[$j];
            $key = $a < $b ? "$a|$b" : "$b|$a";
            $tagCooccurrence[$key] = ($tagCooccurrence[$key] ?? 0) + 1;
        }
    }
}
// Build set of already-connected pairs
$connectedPairs = [];
foreach ($allConnections as $conn) {
    $a = $conn['from']; $b = $conn['to'];
    $key = $a < $b ? "$a|$b" : "$b|$a";
    $connectedPairs[$key] = true;
}
// Filter to unconnected pairs and sort by co-occurrence count
arsort($tagCooccurrence);
foreach ($tagCooccurrence as $key => $count) {
    if (isset($connectedPairs[$key])) continue;
    [$tagA, $tagB] = explode('|', $key);
    // Only suggest if both tags actually exist
    if (!isset($tagDisplayMap[$tagA]) || !isset($tagDisplayMap[$tagB])) continue;
    $coSuggestions[] = ['tag_a' => $tagA, 'tag_b' => $tagB, 'count' => $count];
    if (count($coSuggestions) >= 15) break;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tag Management - <?= htmlspecialchars($config['site_title']) ?></title>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .tag-admin-header {
            background: var(--bg-secondary);
            backdrop-filter: blur(10px);
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
        }

        .tag-stats {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tag-stat {
            background: var(--bg-tertiary);
            padding: 12px 20px;
            border-radius: 8px;
            text-align: center;
            min-width: 100px;
        }

        .tag-stat .value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .tag-stat .label {
            font-size: 12px;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .tag-stat.person .value { color: var(--accent-green); }
        .tag-stat.via .value { color: var(--accent-amber); }

        .filter-controls {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-tabs {
            display: inline-flex;
            background: var(--bg-tertiary);
            padding: 4px;
            border-radius: 10px;
        }

        .filter-tabs button {
            background: transparent;
            color: var(--text-secondary);
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            box-shadow: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-tabs button.active {
            background: var(--bg-primary);
            color: var(--primary);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .filter-tabs button:hover:not(.active) {
            color: var(--text-primary);
        }

        .search-input {
            flex: 1;
            min-width: 200px;
            max-width: 300px;
        }

        .sort-select {
            padding: 10px 16px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: var(--text-primary);
            font-size: 14px;
        }

        .tag-table-container {
            background: var(--bg-secondary);
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .tag-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tag-table th {
            text-align: left;
            padding: 16px 20px;
            background: var(--bg-tertiary);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-tertiary);
        }

        .tag-table td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .tag-table tr:last-child td {
            border-bottom: none;
        }

        .tag-table tr:hover td {
            background: var(--bg-tertiary);
        }

        .tag-name {
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .tag-type-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .tag-type-badge.tag { background: rgba(0, 122, 255, 0.1); color: var(--primary); }
        .tag-type-badge.person { background: rgba(52, 199, 89, 0.1); color: var(--accent-green); }
        .tag-type-badge.via { background: rgba(255, 149, 0, 0.1); color: var(--accent-amber); }

        .tag-count {
            font-weight: 600;
            color: var(--text-secondary);
        }

        .tag-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .tag-actions .btn {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
        }

        .btn-rename { background: var(--primary); color: white; }
        .btn-merge { background: var(--accent-green); color: white; }
        .btn-delete { background: var(--accent-red); color: white; }
        .btn-type { background: var(--accent-amber); color: white; }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: var(--bg-primary);
            border-radius: 16px;
            padding: 30px;
            max-width: 500px;
            width: 100%;
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--border-color);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 20px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-tertiary);
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .modal-close:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-primary);
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 122, 255, 0.15);
        }

        .affected-count {
            background: var(--bg-tertiary);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .affected-count strong {
            color: var(--primary);
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .modal-actions .btn {
            padding: 12px 24px;
        }

        .btn-cancel {
            background: var(--bg-tertiary);
            color: var(--text-secondary);
        }

        .btn-cancel:hover {
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-tertiary);
        }

        .empty-state h3 {
            margin-bottom: 8px;
            color: var(--text-secondary);
        }

        /* Page tab navigation */
        .page-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 0;
        }

        .page-tab {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .page-tab:hover {
            color: var(--text-primary);
        }

        .page-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        /* Connections tab styles */
        .connections-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 900px) {
            .connections-layout {
                grid-template-columns: 1fr;
            }
        }

        .connections-panel {
            background: var(--bg-secondary);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .connections-panel-header {
            padding: 16px 20px;
            background: var(--bg-tertiary);
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .connections-panel-body {
            padding: 20px;
        }

        .connection-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 6px;
            background: var(--bg-tertiary);
            gap: 12px;
        }

        .connection-item:last-child {
            margin-bottom: 0;
        }

        .connection-tags {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            min-width: 0;
        }

        .connection-tag {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 500;
            background: rgba(0, 122, 255, 0.1);
            color: var(--primary);
            white-space: nowrap;
        }

        .connection-arrow {
            color: var(--text-tertiary);
            font-size: 13px;
            flex-shrink: 0;
        }

        .btn-remove-connection {
            background: none;
            border: 1px solid var(--border-color);
            color: var(--accent-red);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.15s;
        }

        .btn-remove-connection:hover {
            background: var(--accent-red);
            color: white;
            border-color: var(--accent-red);
        }

        .connections-empty {
            padding: 30px 20px;
            text-align: center;
            color: var(--text-tertiary);
            font-size: 14px;
        }

        .add-connection-form .form-row {
            display: flex;
            gap: 8px;
            align-items: flex-end;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .add-connection-form .form-row .form-group {
            flex: 1;
            min-width: 120px;
            margin-bottom: 0;
        }

        .add-connection-form .form-row .form-group label {
            font-size: 12px;
        }

        .add-connection-form .form-row .form-group input {
            padding: 10px 12px;
            font-size: 13px;
        }

        .suggestion-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 6px;
            background: var(--bg-tertiary);
            gap: 12px;
        }

        .suggestion-item:last-child {
            margin-bottom: 0;
        }

        .suggestion-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 0;
            flex-wrap: wrap;
        }

        .suggestion-count {
            font-size: 11px;
            color: var(--text-tertiary);
            white-space: nowrap;
        }

        .btn-connect {
            background: var(--accent-green);
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            flex-shrink: 0;
            transition: opacity 0.15s;
        }

        .btn-connect:hover {
            opacity: 0.85;
        }

        .connection-filter {
            margin-bottom: 16px;
        }

        .connection-filter input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-primary);
            font-size: 13px;
        }

        /* Mobile responsive styles for tag admin */
        @media (max-width: 768px) {
            .tag-admin-header {
                padding: 16px;
            }

            .tag-stats {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .tag-stat {
                padding: 10px 12px;
                min-width: auto;
            }

            .tag-stat .value {
                font-size: 20px;
            }

            .filter-controls {
                flex-direction: column;
                gap: 12px;
            }

            .filter-tabs {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(4, 1fr);
            }

            .filter-tabs button {
                padding: 10px 8px;
                font-size: 13px;
            }

            .search-input {
                max-width: none;
                width: 100%;
            }

            .sort-select {
                width: 100%;
            }

            .modal-content {
                margin: 10px;
                padding: 20px;
                max-height: 90vh;
                overflow-y: auto;
            }

            .modal-actions {
                flex-direction: column;
            }

            .modal-actions .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .filter-tabs {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
    <?php render_nav($config, true, 'tag-admin'); ?>

    <div class="page-container">
        <h1>Tag Management</h1>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <nav class="page-tabs">
            <a href="?tab=tags" class="page-tab <?= $activeTab === 'tags' ? 'active' : '' ?>">Tags</a>
            <a href="?tab=connections" class="page-tab <?= $activeTab === 'connections' ? 'active' : '' ?>">Connections
                <?php if (!empty($allConnections)): ?>
                    <span style="font-size:11px; color:var(--text-tertiary);">(<?= count($allConnections) ?>)</span>
                <?php endif; ?>
            </a>
            <a href="?tab=aliases" class="page-tab <?= $activeTab === 'aliases' ? 'active' : '' ?>">Aliases</a>
        </nav>

        <?php if ($activeTab === 'connections'): ?>

        <!-- Connections Tab -->
        <div class="connections-layout">

            <!-- Left: Existing Connections -->
            <div>
                <div class="connections-panel">
                    <div class="connections-panel-header">Explicit Connections (<?= count($allConnections) ?>)</div>
                    <div class="connections-panel-body">
                        <?php if (!empty($allConnections)): ?>
                        <div class="connection-filter">
                            <input type="text" id="connectionFilter" placeholder="Filter connections..." oninput="filterConnections(this.value)">
                        </div>
                        <div id="connectionList">
                            <?php foreach ($allConnections as $conn): ?>
                            <?php
                                $dispA = $tagDisplayMap[$conn['from']] ?? $conn['from'];
                                $dispB = $tagDisplayMap[$conn['to']] ?? $conn['to'];
                            ?>
                            <div class="connection-item" data-tags="<?= htmlspecialchars(strtolower($dispA . ' ' . $dispB)) ?>">
                                <div class="connection-tags">
                                    <span class="connection-tag"><?= htmlspecialchars($dispA) ?></span>
                                    <span class="connection-arrow">↔</span>
                                    <span class="connection-tag"><?= htmlspecialchars($dispB) ?></span>
                                </div>
                                <form method="post" style="display:inline;">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="remove_connection">
                                    <input type="hidden" name="tag_a" value="<?= htmlspecialchars($conn['from']) ?>">
                                    <input type="hidden" name="tag_b" value="<?= htmlspecialchars($conn['to']) ?>">
                                    <input type="hidden" name="tab" value="connections">
                                    <button type="submit" class="btn-remove-connection" onclick="return confirm('Remove connection: <?= htmlspecialchars(addslashes($dispA)) ?> ↔ <?= htmlspecialchars(addslashes($dispB)) ?>?')">Remove</button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="connections-empty">No connections yet.<br>Add some using the form.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Add Connection Form -->
                <div class="connections-panel" style="margin-top: 16px;">
                    <div class="connections-panel-header">Add Connection</div>
                    <div class="connections-panel-body">
                        <form method="post" class="add-connection-form">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="add_connection">
                            <input type="hidden" name="tab" value="connections">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="connTagA">Tag A</label>
                                    <input type="text" name="tag_a" id="connTagA" list="tagListA" placeholder="First tag..." required autocomplete="off">
                                    <datalist id="tagListA">
                                        <?php foreach ($allTagsList as $t): ?>
                                        <option value="<?= htmlspecialchars($t) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div style="display:flex; align-items:center; padding-bottom:2px; color:var(--text-tertiary);">↔</div>
                                <div class="form-group">
                                    <label for="connTagB">Tag B</label>
                                    <input type="text" name="tag_b" id="connTagB" list="tagListB" placeholder="Second tag..." required autocomplete="off">
                                    <datalist id="tagListB">
                                        <?php foreach ($allTagsList as $t): ?>
                                        <option value="<?= htmlspecialchars($t) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                            <button type="submit" class="btn" style="background:var(--primary); color:white;">Connect</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right: Co-occurrence Suggestions -->
            <div>
                <div class="connections-panel">
                    <div class="connections-panel-header">Suggestions from Co-occurrence</div>
                    <div class="connections-panel-body">
                        <?php if (!empty($coSuggestions)): ?>
                        <?php foreach ($coSuggestions as $sug): ?>
                        <?php
                            $dispA = $tagDisplayMap[$sug['tag_a']] ?? $sug['tag_a'];
                            $dispB = $tagDisplayMap[$sug['tag_b']] ?? $sug['tag_b'];
                        ?>
                        <div class="suggestion-item">
                            <div class="suggestion-info">
                                <span class="connection-tag"><?= htmlspecialchars($dispA) ?></span>
                                <span class="connection-arrow">↔</span>
                                <span class="connection-tag"><?= htmlspecialchars($dispB) ?></span>
                                <span class="suggestion-count"><?= $sug['count'] ?> co-occurrence<?= $sug['count'] !== 1 ? 's' : '' ?></span>
                            </div>
                            <form method="post" style="display:inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="add_connection">
                                <input type="hidden" name="tag_a" value="<?= htmlspecialchars($sug['tag_a']) ?>">
                                <input type="hidden" name="tag_b" value="<?= htmlspecialchars($sug['tag_b']) ?>">
                                <input type="hidden" name="tab" value="connections">
                                <button type="submit" class="btn-connect">Connect</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="connections-empty">No suggestions available.<br>Suggestions are based on tags that frequently appear together.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <?php elseif ($activeTab === 'aliases'): ?>

        <!-- Aliases Tab -->
        <?php
        $currentAliases = $db->query("SELECT alias, canonical, created_at FROM tag_aliases ORDER BY canonical, alias")->fetchAll(PDO::FETCH_ASSOC);

        try {
            $aliasSuggestions = getSuggestedAliases($db);
            $aliasSuggestions = array_slice($aliasSuggestions, 0, 20);
            $aliasSuggestionsError = false;
        } catch (Exception $e) {
            $aliasSuggestions = [];
            $aliasSuggestionsError = 'Could not compute suggestions: ' . htmlspecialchars($e->getMessage());
        }
        ?>

        <div class="connections-layout">

            <!-- Left: Current aliases + Define new alias form -->
            <div>
                <!-- Current Aliases -->
                <div class="connections-panel">
                    <div class="connections-panel-header">Current Aliases (<?= count($currentAliases) ?>)</div>
                    <div class="connections-panel-body">
                        <?php if (!empty($currentAliases)): ?>
                        <div class="tag-table-container" style="border:none; border-radius:0; overflow:visible;">
                        <table class="tag-table" style="font-size:13px;">
                            <thead>
                                <tr>
                                    <th>Alias</th>
                                    <th>Canonical</th>
                                    <th>Added</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($currentAliases as $row): ?>
                                <tr>
                                    <td><span class="connection-tag"><?= htmlspecialchars($row['alias']) ?></span></td>
                                    <td><span class="connection-tag" style="background:rgba(52,199,89,0.1); color:var(--accent-green);"><?= htmlspecialchars($row['canonical']) ?></span></td>
                                    <td style="color:var(--text-tertiary); font-size:12px;"><?= htmlspecialchars(substr($row['created_at'], 0, 10)) ?></td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="action" value="remove_alias">
                                            <input type="hidden" name="alias_tag" value="<?= htmlspecialchars($row['alias']) ?>">
                                            <button type="submit" class="btn-remove-connection" onclick="return confirm('Remove alias: <?= htmlspecialchars(addslashes($row['alias'])) ?> → <?= htmlspecialchars(addslashes($row['canonical'])) ?>?')">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php else: ?>
                        <div class="connections-empty">No aliases defined yet.<br>Add one using the form below.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Define New Alias Form -->
                <div class="connections-panel" style="margin-top: 16px;">
                    <div class="connections-panel-header">Define New Alias</div>
                    <div class="connections-panel-body">
                        <form method="post" class="add-connection-form">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="define_alias">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="aliasTagInput">Alias (variant form)</label>
                                    <input type="text" name="alias_tag" id="aliasTagInput" list="aliasTagList" placeholder="e.g. books" required autocomplete="off">
                                    <datalist id="aliasTagList">
                                        <?php foreach ($allTagsList as $t): ?>
                                        <option value="<?= htmlspecialchars($t) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div style="display:flex; align-items:center; padding-bottom:2px; color:var(--text-tertiary);">→</div>
                                <div class="form-group">
                                    <label for="canonicalTagInput">Canonical (preferred form)</label>
                                    <input type="text" name="canonical_tag" id="canonicalTagInput" list="canonicalTagList" placeholder="e.g. book" required autocomplete="off">
                                    <datalist id="canonicalTagList">
                                        <?php foreach ($allTagsList as $t): ?>
                                        <option value="<?= htmlspecialchars($t) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                            <button type="submit" class="btn" style="background:var(--primary); color:white;">Define Alias</button>
                        </form>
                        <p style="margin-top:12px; font-size:12px; color:var(--text-tertiary);">To permanently merge tags instead of aliasing them, use the Merge tool on the Tags tab.</p>
                    </div>
                </div>
            </div>

            <!-- Right: Suggestions -->
            <div>
                <div class="connections-panel">
                    <div class="connections-panel-header">Alias Suggestions</div>
                    <div class="connections-panel-body">
                        <?php if ($aliasSuggestionsError): ?>
                        <div class="connections-empty"><?= $aliasSuggestionsError ?></div>
                        <?php elseif (!empty($aliasSuggestions)): ?>
                        <table class="tag-table" style="font-size:13px;">
                            <thead>
                                <tr>
                                    <th>Alias candidate</th>
                                    <th></th>
                                    <th>Canonical candidate</th>
                                    <th>Reason</th>
                                    <th>Counts</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($aliasSuggestions as $sug): ?>
                                <tr>
                                    <td><span class="connection-tag"><?= htmlspecialchars($sug['alias']) ?></span></td>
                                    <td style="color:var(--text-tertiary);">→</td>
                                    <td><span class="connection-tag" style="background:rgba(52,199,89,0.1); color:var(--accent-green);"><?= htmlspecialchars($sug['canonical']) ?></span></td>
                                    <td style="color:var(--text-tertiary); font-size:12px;"><?= htmlspecialchars($sug['reason']) ?></td>
                                    <td style="color:var(--text-tertiary); font-size:12px;"><?= (int)$sug['alias_count'] ?> / <?= (int)$sug['canonical_count'] ?></td>
                                    <td style="white-space:nowrap;">
                                        <form method="post" style="display:inline;">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="action" value="define_alias">
                                            <input type="hidden" name="alias_tag" value="<?= htmlspecialchars($sug['alias']) ?>">
                                            <input type="hidden" name="canonical_tag" value="<?= htmlspecialchars($sug['canonical']) ?>">
                                            <button type="submit" class="btn-connect">Accept</button>
                                        </form>
                                        <a href="#" style="font-size:12px; color:var(--text-tertiary); margin-left:6px;" onclick="this.closest('tr').remove(); return false;">Ignore</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="connections-empty">No suggestions found — your tags look well-organised.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <?php else: ?>
        <!-- Tags Tab -->

        <div class="tag-admin-header">
            <div class="tag-stats">
                <div class="tag-stat">
                    <div class="value"><?= count($allTags) ?></div>
                    <div class="label">Total Tags</div>
                </div>
                <div class="tag-stat">
                    <div class="value"><?= $typeCounts['tag'] ?></div>
                    <div class="label">Normal</div>
                </div>
                <div class="tag-stat person">
                    <div class="value"><?= $typeCounts['person'] ?></div>
                    <div class="label">People</div>
                </div>
                <div class="tag-stat via">
                    <div class="value"><?= $typeCounts['via'] ?></div>
                    <div class="label">Via</div>
                </div>
            </div>

            <form method="get" class="filter-controls">
                <div class="filter-tabs">
                    <button type="submit" name="type" value="all" class="<?= $filterType === 'all' ? 'active' : '' ?>">All</button>
                    <button type="submit" name="type" value="tag" class="<?= $filterType === 'tag' ? 'active' : '' ?>">Tags</button>
                    <button type="submit" name="type" value="person" class="<?= $filterType === 'person' ? 'active' : '' ?>">People</button>
                    <button type="submit" name="type" value="via" class="<?= $filterType === 'via' ? 'active' : '' ?>">Via</button>
                </div>

                <input type="text" name="search" placeholder="Search tags..." value="<?= htmlspecialchars($searchQuery) ?>" class="search-input">

                <select name="sort" class="sort-select" onchange="this.form.submit()">
                    <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Sort by Name</option>
                    <option value="count" <?= $sortBy === 'count' ? 'selected' : '' ?>>Sort by Count</option>
                </select>

                <?php if (!empty($searchQuery)): ?>
                    <input type="hidden" name="search" value="<?= htmlspecialchars($searchQuery) ?>">
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($processedTags)): ?>
            <div class="tag-table-container">
                <div class="empty-state">
                    <h3>No tags found</h3>
                    <p>
                        <?php if (!empty($searchQuery) || $filterType !== 'all'): ?>
                            Try adjusting your filters or search query.
                        <?php else: ?>
                            Add tags to your bookmarks to see them here.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="tag-table-container">
                <div class="table-responsive">
                <table class="tag-table">
                    <thead>
                        <tr>
                            <th>Tag</th>
                            <th>Type</th>
                            <th>Count</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processedTags as $tag): ?>
                            <tr>
                                <td>
                                    <span class="tag-name">
                                        <?php echo getTagTypeIcon($tag['type']); ?>
                                        <?= htmlspecialchars($tag['name']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="tag-type-badge <?= $tag['type'] ?>">
                                        <?= $tag['type'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="tag-count"><?= $tag['count'] ?></span>
                                </td>
                                <td>
                                    <div class="tag-actions">
                                        <button class="btn btn-rename" onclick="openRenameModal('<?= htmlspecialchars(addslashes($tag['full'])) ?>', <?= $tag['count'] ?>)">Rename</button>
                                        <button class="btn btn-merge" onclick="openMergeModal('<?= htmlspecialchars(addslashes($tag['full'])) ?>', <?= $tag['count'] ?>)">Merge</button>
                                        <button class="btn btn-delete" onclick="openDeleteModal('<?= htmlspecialchars(addslashes($tag['full'])) ?>', <?= $tag['count'] ?>)">Delete</button>
                                        <button class="btn btn-type" onclick="openTypeModal('<?= htmlspecialchars(addslashes($tag['full'])) ?>', '<?= $tag['type'] ?>', <?= $tag['count'] ?>)">Type</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>

        <?php endif; // end $activeTab === 'tags' ?>
    </div>

    <!-- Rename Modal -->
    <div id="renameModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Rename Tag</h3>
                <button class="modal-close" onclick="closeModal('renameModal')">&times;</button>
            </div>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="old_tag" id="renameOldTag">

                <div class="affected-count">
                    This will affect <strong id="renameCount">0</strong> bookmark(s).
                </div>

                <div class="form-group">
                    <label>Current Tag</label>
                    <input type="text" id="renameOldTagDisplay" readonly>
                </div>

                <div class="form-group">
                    <label for="renameNewTag">New Tag Name</label>
                    <input type="text" name="new_tag" id="renameNewTag" required>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal('renameModal')">Cancel</button>
                    <button type="submit" class="btn btn-rename">Rename Tag</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Merge Modal -->
    <div id="mergeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Merge Tag</h3>
                <button class="modal-close" onclick="closeModal('mergeModal')">&times;</button>
            </div>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="merge">
                <input type="hidden" name="source_tags[]" id="mergeSourceTag">

                <div class="affected-count">
                    This will affect <strong id="mergeCount">0</strong> bookmark(s).
                </div>

                <div class="form-group">
                    <label>Source Tag (will be removed)</label>
                    <input type="text" id="mergeSourceTagDisplay" readonly>
                </div>

                <div class="form-group">
                    <label for="mergeTargetTag">Merge Into</label>
                    <select name="target_tag" id="mergeTargetTag" required>
                        <option value="">Select target tag...</option>
                        <?php foreach ($allTagsList as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal('mergeModal')">Cancel</button>
                    <button type="submit" class="btn btn-merge">Merge Tags</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Tag</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="tag" id="deleteTag">

                <div class="affected-count" style="background: rgba(239, 68, 68, 0.1); color: var(--accent-red);">
                    This will remove the tag from <strong id="deleteCount">0</strong> bookmark(s).
                </div>

                <div class="form-group">
                    <label>Tag to Delete</label>
                    <input type="text" id="deleteTagDisplay" readonly>
                </div>

                <p style="color: var(--text-secondary); font-size: 14px;">
                    This action cannot be undone. The tag will be removed from all bookmarks, but the bookmarks themselves will not be deleted.
                </p>

                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" class="btn btn-delete">Delete Tag</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Type Modal -->
    <div id="typeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Change Tag Type</h3>
                <button class="modal-close" onclick="closeModal('typeModal')">&times;</button>
            </div>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="change_type">
                <input type="hidden" name="tag" id="typeTag">

                <div class="affected-count">
                    This will affect <strong id="typeCount">0</strong> bookmark(s).
                </div>

                <div class="form-group">
                    <label>Current Tag</label>
                    <input type="text" id="typeTagDisplay" readonly>
                </div>

                <div class="form-group">
                    <label>Current Type</label>
                    <input type="text" id="typeCurrentType" readonly>
                </div>

                <div class="form-group">
                    <label for="typeNewType">New Type</label>
                    <select name="new_type" id="typeNewType" required>
                        <option value="tag">Normal Tag</option>
                        <option value="person">Person</option>
                        <option value="via">Via (Source)</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal('typeModal')">Cancel</button>
                    <button type="submit" class="btn btn-type">Change Type</button>
                </div>
            </form>
        </div>
    </div>

    <?php render_nav_scripts(); ?>

    <script>
        function openRenameModal(tag, count) {
            document.getElementById('renameOldTag').value = tag;
            document.getElementById('renameOldTagDisplay').value = tag;
            document.getElementById('renameNewTag').value = tag;
            document.getElementById('renameCount').textContent = count;
            document.getElementById('renameModal').classList.add('active');
        }

        function openMergeModal(tag, count) {
            document.getElementById('mergeSourceTag').value = tag;
            document.getElementById('mergeSourceTagDisplay').value = tag;
            document.getElementById('mergeCount').textContent = count;

            // Remove the source tag from target options
            const targetSelect = document.getElementById('mergeTargetTag');
            Array.from(targetSelect.options).forEach(option => {
                option.disabled = (option.value === tag);
            });
            targetSelect.value = '';

            document.getElementById('mergeModal').classList.add('active');
        }

        function openDeleteModal(tag, count) {
            document.getElementById('deleteTag').value = tag;
            document.getElementById('deleteTagDisplay').value = tag;
            document.getElementById('deleteCount').textContent = count;
            document.getElementById('deleteModal').classList.add('active');
        }

        function openTypeModal(tag, currentType, count) {
            document.getElementById('typeTag').value = tag;
            document.getElementById('typeTagDisplay').value = tag;
            document.getElementById('typeCurrentType').value = currentType.charAt(0).toUpperCase() + currentType.slice(1);
            document.getElementById('typeCount').textContent = count;

            // Pre-select the next logical type
            const typeSelect = document.getElementById('typeNewType');
            typeSelect.value = currentType === 'tag' ? 'person' : (currentType === 'person' ? 'via' : 'tag');

            document.getElementById('typeModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Close modal on background click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });

        // Filter connections list
        function filterConnections(query) {
            const q = query.toLowerCase();
            document.querySelectorAll('#connectionList .connection-item').forEach(item => {
                const tags = item.dataset.tags || '';
                item.style.display = tags.includes(q) ? '' : 'none';
            });
        }
    </script>
</body>

</html>
