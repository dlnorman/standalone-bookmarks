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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_token();

    if (isset($_POST['action'])) {
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
        }
    }
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
        <?php endif; ?>
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
    </script>
</body>

</html>
