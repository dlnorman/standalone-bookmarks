<?php
/**
 * Tag helper functions
 * Provides utilities for parsing and managing tag types
 */

/**
 * Escape special characters for SQL LIKE patterns
 *
 * @param string $str The string to escape
 * @return string The escaped string safe for LIKE patterns
 */
function escapeLikePattern($str) {
    // Escape %, _, and \ which have special meaning in LIKE patterns
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $str);
}

/**
 * Parse a tag to determine its type and name
 *
 * @param string $tag The full tag string
 * @return array ['type' => 'tag'|'person'|'via', 'name' => string]
 */
function parseTagType($tag) {
    $tag = trim($tag);

    if (strpos($tag, 'person:') === 0) {
        return ['type' => 'person', 'name' => substr($tag, 7)];
    } elseif (strpos($tag, 'via:') === 0) {
        return ['type' => 'via', 'name' => substr($tag, 4)];
    }

    return ['type' => 'tag', 'name' => $tag];
}

/**
 * Build a full tag string from type and name
 *
 * @param string $type The tag type: 'tag', 'person', or 'via'
 * @param string $name The tag name (without prefix)
 * @return string The full tag string
 */
function buildTagString($type, $name) {
    $name = trim($name);

    switch ($type) {
        case 'person':
            return 'person:' . $name;
        case 'via':
            return 'via:' . $name;
        default:
            return $name;
    }
}

/**
 * Get CSS class for tag type
 *
 * @param string $type The tag type
 * @return string CSS class name
 */
function getTagTypeClass($type) {
    switch ($type) {
        case 'person':
            return 'tag-person';
        case 'via':
            return 'tag-via';
        default:
            return '';
    }
}

/**
 * Get icon for tag type
 *
 * @param string $type The tag type
 * @return string Icon HTML/emoji
 */
function getTagTypeIcon($type) {
    switch ($type) {
        case 'person':
            return '<span class="tag-icon">&#128100;</span>'; // 👤
        case 'via':
            return '<span class="tag-icon">&#128228;</span>'; // 📤
        default:
            return '';
    }
}

/**
 * Render a tag with appropriate styling
 *
 * @param string $tag The full tag string
 * @param string $basePath The base path for links
 * @param string $additionalClasses Additional CSS classes
 * @return string HTML for the tag
 */
function renderTag($tag, $basePath = '', $additionalClasses = 'bookmark-tag') {
    $parsed = parseTagType($tag);
    $typeClass = getTagTypeClass($parsed['type']);
    $icon = getTagTypeIcon($parsed['type']);

    $classes = trim($additionalClasses . ' ' . $typeClass);
    $displayName = htmlspecialchars($parsed['name']);
    $fullTag = htmlspecialchars($tag);

    return '<a href="' . $basePath . '/?tag=' . urlencode($tag) . '" class="' . $classes . '">'
         . $icon . $displayName . '</a>';
}

/**
 * Get all unique tags from bookmarks with counts
 *
 * @param PDO $db Database connection
 * @param bool $includePrivate Include private bookmarks
 * @return array Associative array of tag => count
 */
function getAllTagsWithCounts($db, $includePrivate = false) {
    if ($includePrivate) {
        $stmt = $db->query("SELECT tags FROM bookmarks WHERE tags IS NOT NULL AND tags != ''");
    } else {
        $stmt = $db->query("SELECT tags FROM bookmarks WHERE tags IS NOT NULL AND tags != '' AND private = 0");
    }

    $allTags = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tags = array_map('trim', explode(',', $row['tags']));
        foreach ($tags as $tag) {
            if (!empty($tag)) {
                // Keep original case for display, use lowercase for counting
                $tagLower = strtolower($tag);
                if (!isset($allTags[$tagLower])) {
                    $allTags[$tagLower] = ['count' => 0, 'display' => $tag];
                }
                $allTags[$tagLower]['count']++;
            }
        }
    }

    return $allTags;
}

/**
 * Get bookmarks containing a specific tag
 *
 * @param PDO $db Database connection
 * @param string $tag The tag to search for
 * @param bool $includePrivate Include private bookmarks
 * @return array Array of bookmark IDs
 */
function getBookmarksWithTag($db, $tag, $includePrivate = false) {
    $tagPattern = '%,' . escapeLikePattern(strtolower(trim($tag))) . ',%';

    $sql = "SELECT id FROM bookmarks WHERE ',' || REPLACE(LOWER(tags), ', ', ',') || ',' LIKE ? ESCAPE '\\'";
    if (!$includePrivate) {
        $sql .= " AND private = 0";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute([$tagPattern]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Rename a tag across all bookmarks
 *
 * @param PDO $db Database connection
 * @param string $oldTag The tag to rename
 * @param string $newTag The new tag name
 * @return int Number of bookmarks updated
 */
function renameTag($db, $oldTag, $newTag) {
    $oldTag = trim($oldTag);
    $newTag = trim($newTag);

    if (empty($oldTag) || empty($newTag)) {
        return 0;
    }

    // Use transaction for atomicity - check if already in one (e.g., from mergeTags)
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }

    try {
        // Get all bookmarks with the old tag
        $tagPattern = '%,' . escapeLikePattern(strtolower($oldTag)) . ',%';
        $stmt = $db->prepare("SELECT id, tags FROM bookmarks WHERE ',' || REPLACE(LOWER(tags), ', ', ',') || ',' LIKE ? ESCAPE '\\'");
        $stmt->execute([$tagPattern]);
        $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updateStmt = $db->prepare("UPDATE bookmarks SET tags = ?, updated_at = datetime('now') WHERE id = ?");
        $count = 0;

        foreach ($bookmarks as $bookmark) {
            $tags = array_map('trim', explode(',', $bookmark['tags']));
            // Filter out empty tags to clean up any malformed data
            $tags = array_filter($tags, function($t) { return $t !== ''; });
            $newTags = [];
            $hasNewTag = false;

            // Only check for existing newTag if it's different from oldTag (case-insensitive)
            // This prevents the bug where renaming to the same tag or changing case causes deletion
            if (strtolower($oldTag) !== strtolower($newTag)) {
                foreach ($tags as $tag) {
                    if (strtolower($tag) === strtolower($newTag)) {
                        $hasNewTag = true;
                        break;
                    }
                }
            }

            foreach ($tags as $tag) {
                if (strtolower($tag) === strtolower($oldTag)) {
                    // Replace old tag with new tag (handles both rename and case normalization)
                    if (!$hasNewTag) {
                        $newTags[] = $newTag;
                        $hasNewTag = true;
                    }
                    // If hasNewTag is true (target already exists), skip to avoid duplicate
                } else {
                    $newTags[] = $tag;
                }
            }

            $newTagsStr = implode(', ', $newTags);
            $updateStmt->execute([$newTagsStr, $bookmark['id']]);
            $count++;
        }

        if ($ownTransaction) {
            $db->commit();
        }

        return $count;
    } catch (Exception $e) {
        if ($ownTransaction) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Merge source tags into a target tag
 *
 * @param PDO $db Database connection
 * @param array $sourceTags Array of tags to merge from
 * @param string $targetTag The tag to merge into
 * @return int Number of bookmarks updated
 */
function mergeTags($db, $sourceTags, $targetTag) {
    $targetTag = trim($targetTag);
    if (empty($targetTag)) {
        return 0;
    }

    // Wrap entire merge in a transaction for atomicity
    $db->beginTransaction();

    try {
        $count = 0;

        foreach ($sourceTags as $sourceTag) {
            $sourceTag = trim($sourceTag);
            if (empty($sourceTag) || strtolower($sourceTag) === strtolower($targetTag)) {
                continue;
            }

            $count += renameTag($db, $sourceTag, $targetTag);
        }

        $db->commit();
        return $count;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Delete a tag from all bookmarks
 *
 * @param PDO $db Database connection
 * @param string $tag The tag to delete
 * @return int Number of bookmarks updated
 */
function deleteTag($db, $tag) {
    $tag = trim($tag);

    if (empty($tag)) {
        return 0;
    }

    // Use transaction for atomicity
    $db->beginTransaction();

    try {
        // Get all bookmarks with the tag
        $tagPattern = '%,' . escapeLikePattern(strtolower($tag)) . ',%';
        $stmt = $db->prepare("SELECT id, tags FROM bookmarks WHERE ',' || REPLACE(LOWER(tags), ', ', ',') || ',' LIKE ? ESCAPE '\\'");
        $stmt->execute([$tagPattern]);
        $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updateStmt = $db->prepare("UPDATE bookmarks SET tags = ?, updated_at = datetime('now') WHERE id = ?");
        $count = 0;

        foreach ($bookmarks as $bookmark) {
            $tags = array_map('trim', explode(',', $bookmark['tags']));
            // Filter out empty tags and the target tag
            $newTags = array_filter($tags, function($t) use ($tag) {
                return $t !== '' && strtolower($t) !== strtolower($tag);
            });

            $newTagsStr = implode(', ', $newTags);
            $updateStmt->execute([$newTagsStr, $bookmark['id']]);
            $count++;
        }

        $db->commit();
        return $count;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Change the type of a tag
 *
 * @param PDO $db Database connection
 * @param string $tag The current full tag string
 * @param string $newType The new type: 'tag', 'person', or 'via'
 * @return int Number of bookmarks updated
 */
function changeTagType($db, $tag, $newType) {
    $parsed = parseTagType($tag);
    $newTag = buildTagString($newType, $parsed['name']);

    if ($tag === $newTag) {
        return 0;
    }

    return renameTag($db, $tag, $newTag);
}
