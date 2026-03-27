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
function renderTag($tag, $basePath = '', $additionalClasses = 'bookmark-tag', $activeTags = []) {
    $parsed = parseTagType($tag);
    $typeClass = getTagTypeClass($parsed['type']);
    $icon = getTagTypeIcon($parsed['type']);

    $lowerTag = strtolower(trim($tag));
    $lowerActive = array_map(function($t) { return strtolower(trim($t)); }, $activeTags);
    $isActive = !empty($activeTags) && in_array($lowerTag, $lowerActive);
    $activeClass = $isActive ? ' active-filter' : '';
    $classes = trim($additionalClasses . ' ' . $typeClass . $activeClass);
    $displayName = htmlspecialchars($parsed['name']);

    // Compute href: if activeTags provided, toggle this tag in/out of the set
    if (!empty($activeTags)) {
        if (in_array($lowerTag, $lowerActive)) {
            $newTags = array_values(array_filter($activeTags, function($t) use ($lowerTag) {
                return strtolower(trim($t)) !== $lowerTag;
            }));
            $href = empty($newTags) ? $basePath . '/' : $basePath . '/?tag=' . urlencode(implode(',', $newTags));
        } else {
            $href = $basePath . '/?tag=' . urlencode(implode(',', array_merge($activeTags, [$tag])));
        }
    } else {
        $href = $basePath . '/?tag=' . urlencode($tag);
    }

    return '<a href="' . $href . '" class="' . $classes . '">' . $icon . $displayName . '</a>';
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

        // Update tag_connections to use the new tag name
        $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tag_connections'")->fetch();
        if ($tableExists) {
            $oldLower = strtolower($oldTag);
            $newLower = strtolower($newTag);
            if ($oldLower !== $newLower) {
                $updateFrom = $db->prepare("UPDATE tag_connections SET tag_from = ? WHERE tag_from = ?");
                $updateFrom->execute([$newLower, $oldLower]);
                $updateTo = $db->prepare("UPDATE tag_connections SET tag_to = ? WHERE tag_to = ?");
                $updateTo->execute([$newLower, $oldLower]);
                // Remove self-connections that may have formed during merge
                $db->exec("DELETE FROM tag_connections WHERE tag_from = tag_to");
                // Remove duplicate pairs
                $db->exec("DELETE FROM tag_connections WHERE rowid NOT IN (SELECT MIN(rowid) FROM tag_connections GROUP BY tag_from, tag_to)");
            }
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

        // Remove connections for the deleted tag
        $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tag_connections'")->fetch();
        if ($tableExists) {
            $tagLower = strtolower($tag);
            $deleteConn = $db->prepare("DELETE FROM tag_connections WHERE tag_from = ? OR tag_to = ?");
            $deleteConn->execute([$tagLower, $tagLower]);
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

/**
 * Get related tags (explicit connections) for a given tag
 *
 * @param PDO $db Database connection
 * @param string $tag The tag to find connections for
 * @return array Array of related tag names
 */
function getTagConnections($db, $tag) {
    $tagLower = strtolower(trim($tag));

    // Check if table exists
    $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tag_connections'")->fetch();
    if (!$tableExists) return [];

    $stmt = $db->prepare("
        SELECT tag_to as related FROM tag_connections WHERE tag_from = ?
        UNION
        SELECT tag_from as related FROM tag_connections WHERE tag_to = ?
    ");
    $stmt->execute([$tagLower, $tagLower]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get all explicit tag connection pairs
 *
 * @param PDO $db Database connection
 * @return array Array of ['from' => string, 'to' => string] pairs (each pair listed once)
 */
function getAllTagConnections($db) {
    // Check if table exists
    $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tag_connections'")->fetch();
    if (!$tableExists) return [];

    $stmt = $db->query("
        SELECT tag_from as `from`, tag_to as `to`
        FROM tag_connections
        WHERE tag_from < tag_to
        ORDER BY tag_from, tag_to
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if an explicit connection exists between two tags
 *
 * @param PDO $db Database connection
 * @param string $tagA First tag
 * @param string $tagB Second tag
 * @return bool
 */
function tagConnectionExists($db, $tagA, $tagB) {
    $a = strtolower(trim($tagA));
    $b = strtolower(trim($tagB));

    // Check if table exists
    $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tag_connections'")->fetch();
    if (!$tableExists) return false;

    $stmt = $db->prepare("SELECT COUNT(*) FROM tag_connections WHERE (tag_from = ? AND tag_to = ?) OR (tag_from = ? AND tag_to = ?)");
    $stmt->execute([$a, $b, $b, $a]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Add a bidirectional explicit connection between two tags
 *
 * @param PDO $db Database connection
 * @param string $tagA First tag
 * @param string $tagB Second tag
 * @return bool True if added, false if already exists
 */
function addTagConnection($db, $tagA, $tagB) {
    $a = strtolower(trim($tagA));
    $b = strtolower(trim($tagB));

    if (empty($a) || empty($b) || $a === $b) return false;

    if (tagConnectionExists($db, $a, $b)) return false;

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT OR IGNORE INTO tag_connections (tag_from, tag_to) VALUES (?, ?)");
        $stmt->execute([$a, $b]);
        $stmt->execute([$b, $a]);
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Remove a bidirectional explicit connection between two tags
 *
 * @param PDO $db Database connection
 * @param string $tagA First tag
 * @param string $tagB Second tag
 * @return bool True if removed
 */
function removeTagConnection($db, $tagA, $tagB) {
    $a = strtolower(trim($tagA));
    $b = strtolower(trim($tagB));

    // Check if table exists
    $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tag_connections'")->fetch();
    if (!$tableExists) return false;

    $stmt = $db->prepare("DELETE FROM tag_connections WHERE (tag_from = ? AND tag_to = ?) OR (tag_from = ? AND tag_to = ?)");
    $stmt->execute([$a, $b, $b, $a]);
    return $stmt->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Tag Alias functions
// ---------------------------------------------------------------------------

/**
 * Define an alias -> canonical mapping.
 *
 * Rules enforced:
 * - alias and canonical must differ
 * - alias cannot already be used as a canonical (no chaining)
 * - canonical cannot itself be an alias of something else (no chaining)
 *
 * @param PDO $db
 * @param string $alias   The variant form (e.g. "books")
 * @param string $canonical The preferred form (e.g. "book")
 * @return bool True if inserted, false if the alias already exists
 */
function defineAlias($db, $alias, $canonical) {
    $alias     = strtolower(trim($alias));
    $canonical = strtolower(trim($canonical));

    if (empty($alias) || empty($canonical) || $alias === $canonical) return false;

    // Prevent chaining: alias must not already be a canonical that other aliases point to
    $isCanonical = $db->prepare("SELECT COUNT(*) FROM tag_aliases WHERE canonical = ?");
    $isCanonical->execute([$alias]);
    if ($isCanonical->fetchColumn() > 0) return false;

    // Prevent chaining: canonical must not itself be an alias
    $isAlias = $db->prepare("SELECT COUNT(*) FROM tag_aliases WHERE alias = ?");
    $isAlias->execute([$canonical]);
    if ($isAlias->fetchColumn() > 0) return false;

    $stmt = $db->prepare("INSERT OR IGNORE INTO tag_aliases (alias, canonical) VALUES (?, ?)");
    $stmt->execute([$alias, $canonical]);
    return $stmt->rowCount() > 0;
}

/**
 * Remove an alias mapping by alias name.
 *
 * @param PDO $db
 * @param string $alias
 * @return bool True if removed, false if not found
 */
function removeAlias($db, $alias) {
    $alias = strtolower(trim($alias));
    $stmt  = $db->prepare("DELETE FROM tag_aliases WHERE alias = ?");
    $stmt->execute([$alias]);
    return $stmt->rowCount() > 0;
}

/**
 * Return the canonical form of a tag.
 * If the tag is an alias, returns its canonical. Otherwise returns the tag unchanged.
 *
 * @param PDO $db
 * @param string $tag
 * @return string
 */
function getCanonical($db, $tag) {
    $tag  = strtolower(trim($tag));
    $stmt = $db->prepare("SELECT canonical FROM tag_aliases WHERE alias = ?");
    $stmt->execute([$tag]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['canonical'] : $tag;
}

/**
 * Return all aliases that point to the given canonical tag.
 *
 * @param PDO $db
 * @param string $canonical
 * @return string[]
 */
function getAliasesFor($db, $canonical) {
    $canonical = strtolower(trim($canonical));
    $stmt      = $db->prepare("SELECT alias FROM tag_aliases WHERE canonical = ? ORDER BY alias");
    $stmt->execute([$canonical]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Return the full group of tags to match when filtering by $tag:
 * resolves to canonical first, then returns canonical + all its aliases.
 *
 * Example: expandTagGroup($db, 'books') -> ['book', 'books']  (if books->book)
 *          expandTagGroup($db, 'book')  -> ['book', 'books']  (same result)
 *          expandTagGroup($db, 'python')-> ['python']         (no aliases)
 *
 * @param PDO $db
 * @param string $tag
 * @return string[]
 */
function expandTagGroup($db, $tag) {
    $canonical = getCanonical($db, $tag);
    $aliases   = getAliasesFor($db, $canonical);
    return array_unique(array_merge([$canonical], $aliases));
}

/**
 * Suggest likely alias candidates using three heuristics:
 *   1. Plural/singular suffix stripping
 *   2. Levenshtein distance <= 2
 *   3. High co-occurrence ratio (both tags present in >60% of bookmarks that have either)
 *
 * Skips pairs where either tag is already defined as an alias.
 * Returns suggestions sorted by heuristic priority (plural first, then levenshtein, then co-occurrence).
 *
 * @param PDO $db
 * @return array  Each entry: ['alias'=>string, 'canonical'=>string, 'reason'=>string,
 *                             'alias_count'=>int, 'canonical_count'=>int]
 */
function getSuggestedAliases($db) {
    // Load all tags with counts (include private so admin sees the full picture)
    $allTags = getAllTagsWithCounts($db, true);
    if (count($allTags) < 2) return [];

    // Load existing aliases so we can skip already-defined ones
    $existingAliases = $db->query("SELECT alias FROM tag_aliases")->fetchAll(PDO::FETCH_COLUMN);
    $existingSet     = array_flip($existingAliases);

    $tagList   = array_keys($allTags); // lowercase tag names
    $tagCounts = array_map(fn($t) => $t['count'], $allTags);

    $suggestions = [];
    $seen        = []; // deduplicate pairs

    // Helper: record a suggestion (smaller-count tag as alias of larger-count canonical)
    $addSuggestion = function($a, $b, $reason) use (&$suggestions, &$seen, $tagCounts, $existingSet) {
        $pairKey = $a < $b ? "$a|$b" : "$b|$a";
        if (isset($seen[$pairKey])) return;
        if (isset($existingSet[$a]) || isset($existingSet[$b])) return;
        $seen[$pairKey] = true;

        // Canonical = whichever has more bookmarks; alias = the other
        $countA = $tagCounts[$a] ?? 0;
        $countB = $tagCounts[$b] ?? 0;
        [$alias, $canonical] = $countA <= $countB ? [$a, $b] : [$b, $a];

        $suggestions[] = [
            'alias'           => $alias,
            'canonical'       => $canonical,
            'reason'          => $reason,
            'alias_count'     => $tagCounts[$alias] ?? 0,
            'canonical_count' => $tagCounts[$canonical] ?? 0,
        ];
    };

    // --- Heuristic 1: plural/singular ---
    $pluralSuggestions = [];
    foreach ($tagList as $tag) {
        $candidates = [];
        // Strip trailing 's'
        if (strlen($tag) > 2 && substr($tag, -1) === 's') {
            $candidates[] = substr($tag, 0, -1);
        }
        // Strip trailing 'es'
        if (strlen($tag) > 3 && substr($tag, -2) === 'es') {
            $candidates[] = substr($tag, 0, -2);
        }
        // Strip trailing 'ies', replace with 'y'
        if (strlen($tag) > 4 && substr($tag, -3) === 'ies') {
            $candidates[] = substr($tag, 0, -3) . 'y';
        }
        foreach ($candidates as $candidate) {
            if (isset($allTags[$candidate])) {
                $pluralSuggestions[] = [$tag, $candidate, 'plural/singular'];
            }
        }
    }
    foreach ($pluralSuggestions as [$a, $b, $reason]) {
        $addSuggestion($a, $b, $reason);
    }

    // --- Heuristic 2: Levenshtein distance <= 2 (only tags with count >= 2) ---
    $qualifiedTags = array_filter($tagList, fn($t) => ($tagCounts[$t] ?? 0) >= 2);
    $qualifiedTags = array_values($qualifiedTags);
    $n = count($qualifiedTags);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $qualifiedTags[$i];
            $b = $qualifiedTags[$j];
            // Skip if already found via plurals
            $pairKey = $a < $b ? "$a|$b" : "$b|$a";
            if (isset($seen[$pairKey])) continue;
            if (levenshtein($a, $b) <= 2) {
                $addSuggestion($a, $b, 'similar spelling');
            }
        }
    }

    // --- Heuristic 3: high co-occurrence ratio ---
    // For each pair in tag_cooccurrence, check: count(both) / count(either) > 0.6
    // We compute this from the bookmarks table directly (limit to top 50 tags for performance)
    $topTags = array_slice($tagList, 0, 50);
    // Build a lookup: for each top tag, which bookmarks contain it?
    $bookmarkTags = $db->query(
        "SELECT id, tags FROM bookmarks WHERE tags IS NOT NULL AND tags != ''"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Index: tag -> set of bookmark IDs
    $tagBookmarks = [];
    foreach ($bookmarkTags as $row) {
        $tags = array_map('strtolower', array_map('trim', explode(',', $row['tags'])));
        foreach ($tags as $t) {
            if (in_array($t, $topTags)) {
                $tagBookmarks[$t][] = $row['id'];
            }
        }
    }

    $topN = count($topTags);
    for ($i = 0; $i < $topN; $i++) {
        for ($j = $i + 1; $j < $topN; $j++) {
            $a = $topTags[$i];
            $b = $topTags[$j];
            $pairKey = $a < $b ? "$a|$b" : "$b|$a";
            if (isset($seen[$pairKey])) continue;
            if (!isset($tagBookmarks[$a]) || !isset($tagBookmarks[$b])) continue;

            $setA  = array_flip($tagBookmarks[$a]);
            $setB  = array_flip($tagBookmarks[$b]);
            $both  = count(array_intersect_key($setA, $setB));
            $either = count(array_unique(array_merge($tagBookmarks[$a], $tagBookmarks[$b])));

            if ($either > 0 && ($both / $either) > 0.6) {
                $addSuggestion($a, $b, 'high co-occurrence');
            }
        }
    }

    return $suggestions;
}
