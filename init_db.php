#!/usr/bin/env php
<?php
/**
 * Database Initialization Script
 *
 * Creates the database schema with all necessary tables and indexes
 * Run this once when setting up a fresh installation
 *
 * Usage: php init_db.php
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die("Error: config.php not found.\n");
}

$config = require __DIR__ . '/config.php';

try {
    echo "=== Bookmarks Database Initialization ===\n\n";

    // Connect to database (will create file if it doesn't exist)
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Database: " . $config['db_path'] . "\n\n";

    // Check if tables already exist
    $existingTables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('bookmarks', $existingTables)) {
        echo "⚠ Warning: 'bookmarks' table already exists!\n";
        echo "Do you want to continue? This will NOT drop existing data. (y/N): ";
        $confirm = trim(fgets(STDIN));
        if (strtolower($confirm) !== 'y') {
            die("Aborted.\n");
        }
        echo "\n";
    }

    // Create bookmarks table
    echo "Creating 'bookmarks' table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS bookmarks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            url TEXT NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            tags TEXT,
            screenshot TEXT,
            archive_url TEXT,
            private INTEGER DEFAULT 0,
            broken_url INTEGER DEFAULT 0,
            last_checked DATETIME,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )
    ");
    echo "✓ Table 'bookmarks' created\n";

    // Create jobs table for background processing
    echo "Creating 'jobs' table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bookmark_id INTEGER,
            job_type TEXT NOT NULL,
            payload TEXT,
            status TEXT DEFAULT 'pending',
            result TEXT,
            attempts INTEGER DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (bookmark_id) REFERENCES bookmarks(id) ON DELETE CASCADE
        )
    ");
    echo "✓ Table 'jobs' created\n";

    // Create indexes for performance
    echo "\nCreating performance indexes...\n";

    // Bookmarks indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_created_at ON bookmarks(created_at DESC)");
    echo "✓ Index: idx_bookmarks_created_at\n";

    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_private ON bookmarks(private)");
    echo "✓ Index: idx_bookmarks_private\n";

    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_private_created ON bookmarks(private, created_at DESC)");
    echo "✓ Index: idx_bookmarks_private_created\n";

    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_broken_url ON bookmarks(broken_url)");
    echo "✓ Index: idx_bookmarks_broken_url\n";

    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_url ON bookmarks(url)");
    echo "✓ Index: idx_bookmarks_url\n";

    // Jobs indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)");
    echo "✓ Index: idx_jobs_status\n";

    $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_type ON jobs(job_type)");
    echo "✓ Index: idx_jobs_type\n";

    $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON jobs(status, created_at)");
    echo "✓ Index: idx_jobs_status_created\n";

    $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_bookmark_id ON jobs(bookmark_id)");
    echo "✓ Index: idx_jobs_bookmark_id\n";

    // Optimize database
    echo "\nOptimizing database...\n";
    $db->exec("PRAGMA journal_mode=WAL");
    echo "✓ Enabled Write-Ahead Logging (WAL)\n";

    $db->exec("ANALYZE");
    echo "✓ Updated query planner statistics\n";

    // Get final stats
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $indexes = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND sql IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $dbSize = filesize($config['db_path']);

    echo "\n=== Database Initialization Complete ===\n";
    echo "Tables created: " . count($tables) . "\n";
    foreach ($tables as $table) {
        echo "  - $table\n";
    }
    echo "\nIndexes created: " . count($indexes) . "\n";
    foreach ($indexes as $index) {
        echo "  - $index\n";
    }
    echo "\nDatabase size: " . round($dbSize / 1024, 2) . " KB\n";
    echo "\n✓ Database is ready to use!\n";
    echo "\nNext steps:\n";
    echo "1. Visit your application URL to start using it\n";
    echo "2. Set up cron job for background processing:\n";
    echo "   */5 * * * * /usr/bin/php " . __DIR__ . "/process_jobs.php\n";

} catch (PDOException $e) {
    die("\nError: " . $e->getMessage() . "\n");
}
