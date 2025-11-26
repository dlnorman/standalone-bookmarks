#!/usr/bin/env php
<?php
/**
 * Database Initialization & Upgrade Script
 *
 * Handles:
 * 1. Database creation
 * 2. Schema creation (tables, indexes)
 * 3. Schema upgrades (adding missing columns)
 * 4. Data migration (config user -> db user)
 *
 * Run this when setting up a fresh installation OR upgrading.
 *
 * Usage: php init_db.php
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die("Error: config.php not found.\n");
}

$config = require __DIR__ . '/config.php';

try {
    echo "=== Bookmarks Database Initialization & Upgrade ===\n\n";

    // Connect to database (will create file if it doesn't exist)
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Database: " . $config['db_path'] . "\n\n";

    // 1. Create/Update Tables
    echo "--- Checking Tables ---\n";

    // Bookmarks Table
    echo "Checking 'bookmarks' table... ";
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
    echo "✓ Ready\n";

    // Users Table
    echo "Checking 'users' table... ";
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            display_name TEXT,
            role TEXT DEFAULT 'user',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )
    ");
    echo "✓ Ready\n";

    // Jobs Table
    echo "Checking 'jobs' table... ";
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
    echo "✓ Ready\n";

    // 2. Schema Upgrades (Add missing columns)
    echo "\n--- Checking Schema Updates ---\n";

    // Check users.role
    $columns = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $hasRole = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'role') {
            $hasRole = true;
            break;
        }
    }

    if (!$hasRole) {
        echo "Upgrading 'users' table: Adding 'role' column... ";
        $db->exec("ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'user'");
        echo "✓ Done\n";
    } else {
        echo "Schema is up to date.\n";
    }

    // 3. Data Migration (Config -> DB)
    echo "\n--- Checking Data Migration ---\n";

    $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

    if ($userCount == 0) {
        if (isset($config['username']) && isset($config['password'])) {
            echo "Migrating initial user from config.php... ";

            $passwordHash = password_hash($config['password'], PASSWORD_DEFAULT);
            $now = date('Y-m-d H:i:s');

            $stmt = $db->prepare("
                INSERT INTO users (username, password_hash, display_name, role, created_at, updated_at)
                VALUES (?, ?, ?, 'admin', ?, ?)
            ");

            $stmt->execute([
                $config['username'],
                $passwordHash,
                $config['username'],
                $now,
                $now
            ]);
            echo "✓ Done (Created Admin: {$config['username']})\n";
        } else {
            echo "⚠ No users in DB and no credentials in config.php. Please register a user manually.\n";
        }
    } else {
        // Ensure at least one admin exists
        $adminCount = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($adminCount == 0) {
            echo "No admin found. Promoting user ID 1 to admin... ";
            $db->exec("UPDATE users SET role = 'admin' WHERE id = 1");
            echo "✓ Done\n";
        } else {
            echo "Users already exist. Skipping migration.\n";
        }
    }

    // 4. Indexes
    echo "\n--- Checking Indexes ---\n";

    $indexes = [
        'idx_bookmarks_created_at' => 'CREATE INDEX IF NOT EXISTS idx_bookmarks_created_at ON bookmarks(created_at DESC)',
        'idx_bookmarks_private' => 'CREATE INDEX IF NOT EXISTS idx_bookmarks_private ON bookmarks(private)',
        'idx_bookmarks_private_created' => 'CREATE INDEX IF NOT EXISTS idx_bookmarks_private_created ON bookmarks(private, created_at DESC)',
        'idx_bookmarks_broken_url' => 'CREATE INDEX IF NOT EXISTS idx_bookmarks_broken_url ON bookmarks(broken_url)',
        'idx_bookmarks_url' => 'CREATE INDEX IF NOT EXISTS idx_bookmarks_url ON bookmarks(url)',
        'idx_jobs_status' => 'CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)',
        'idx_jobs_type' => 'CREATE INDEX IF NOT EXISTS idx_jobs_type ON jobs(job_type)',
        'idx_jobs_status_created' => 'CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON jobs(status, created_at)',
        'idx_jobs_bookmark_id' => 'CREATE INDEX IF NOT EXISTS idx_jobs_bookmark_id ON jobs(bookmark_id)'
    ];

    foreach ($indexes as $name => $sql) {
        $db->exec($sql);
    }
    echo "✓ Indexes verified\n";

    // 5. Optimization
    echo "\n--- Optimizing ---\n";
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("ANALYZE");
    echo "✓ Database optimized (WAL enabled)\n";

    echo "\n=== Initialization Complete ===\n";
    echo "Database is ready to use.\n";

} catch (PDOException $e) {
    die("\nError: " . $e->getMessage() . "\n");
}
