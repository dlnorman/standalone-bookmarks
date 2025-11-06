#!/usr/bin/env php
<?php
/**
 * Add Performance Indexes to Database
 *
 * NOTE: If you're doing a fresh installation, use init_db.php instead.
 * This script is for existing installations that need indexes added.
 *
 * Run this once to add indexes that will significantly improve query performance
 *
 * Usage: php add-indexes.php
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die("Error: config.php not found.\n");
}

$config = require __DIR__ . '/config.php';

try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "=== Adding Database Indexes ===\n\n";

    // Check if database has any tables
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='bookmarks'")->fetchAll();
    if (empty($tables)) {
        die("Error: Database does not appear to be initialized. Please create bookmarks table first.\n");
    }

    // Get existing indexes
    $existingIndexes = $db->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Existing indexes: " . (count($existingIndexes) > 0 ? implode(', ', $existingIndexes) : 'none') . "\n\n";

    // Index for date sorting (most common query)
    echo "Creating index on created_at...\n";
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_created_at ON bookmarks(created_at DESC)");
    echo "✓ Index: idx_bookmarks_created_at\n";

    // Index for private filtering
    echo "Creating index on private...\n";
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_private ON bookmarks(private)");
    echo "✓ Index: idx_bookmarks_private\n";

    // Composite index for common query pattern (private + date)
    echo "Creating composite index on private + created_at...\n";
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_private_created ON bookmarks(private, created_at DESC)");
    echo "✓ Index: idx_bookmarks_private_created\n";

    // Check if broken_url column exists before creating index
    $columns = $db->query("PRAGMA table_info(bookmarks)")->fetchAll(PDO::FETCH_ASSOC);
    $hasBrokenUrl = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'broken_url') {
            $hasBrokenUrl = true;
            break;
        }
    }

    if ($hasBrokenUrl) {
        echo "Creating index on broken_url...\n";
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_broken_url ON bookmarks(broken_url)");
        echo "✓ Index: idx_bookmarks_broken_url\n";
    } else {
        echo "⚠ Skipping broken_url index (column doesn't exist yet)\n";
    }

    // Index for URL lookups (useful for duplicate detection)
    echo "Creating index on url...\n";
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_url ON bookmarks(url)");
    echo "✓ Index: idx_bookmarks_url\n";

    // Check if jobs table exists
    $jobsTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'")->fetchAll();
    if (!empty($jobsTable)) {
        echo "\nCreating indexes on jobs table...\n";

        // Index for job status
        $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)");
        echo "✓ Index: idx_jobs_status\n";

        // Index for job type
        $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_type ON jobs(job_type)");
        echo "✓ Index: idx_jobs_type\n";

        // Composite index for finding pending jobs
        $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON jobs(status, created_at)");
        echo "✓ Index: idx_jobs_status_created\n";

        // Index for bookmark_id lookups
        $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_bookmark_id ON jobs(bookmark_id)");
        echo "✓ Index: idx_jobs_bookmark_id\n";
    }

    // Update query planner statistics
    echo "\nAnalyzing database to update query planner...\n";
    $db->exec("ANALYZE");
    echo "✓ Database analyzed\n";

    // Get updated index list
    $newIndexes = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND sql IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

    echo "\n=== Index Creation Complete ===\n";
    echo "Total indexes: " . count($newIndexes) . "\n";
    echo "\nIndexes created:\n";
    foreach ($newIndexes as $index) {
        echo "  - $index\n";
    }

    // Show database size
    $dbSize = filesize($config['db_path']);
    echo "\nDatabase size: " . round($dbSize / 1024, 2) . " KB\n";

    echo "\n✓ All indexes created successfully!\n";
    echo "\nQueries should now be significantly faster.\n";

} catch (PDOException $e) {
    die("\nError: " . $e->getMessage() . "\n");
}
