<?php
/**
 * Queue jobs for bookmarks that don't have them
 * Run this once to retroactively create jobs for imported bookmarks
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die("Error: config.php not found.\n");
}

$config = require __DIR__ . '/config.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if jobs table exists
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'")->fetchAll();
    if (empty($tables)) {
        die("Error: jobs table does not exist. Run init_db.php first.\n");
    }

    $now = date('Y-m-d H:i:s');

    // Find bookmarks that don't have any jobs
    $stmt = $db->query("
        SELECT b.id, b.url
        FROM bookmarks b
        LEFT JOIN jobs j ON b.id = j.bookmark_id
        WHERE j.id IS NULL
        ORDER BY b.id ASC
    ");
    $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($bookmarks) . " bookmarks without jobs.\n";

    if (count($bookmarks) === 0) {
        echo "Nothing to do!\n";
        exit(0);
    }

    $added = 0;
    foreach ($bookmarks as $bookmark) {
        echo "Creating jobs for bookmark #{$bookmark['id']}: {$bookmark['url']}... ";

        // Queue archive job
        $stmt = $db->prepare("
            INSERT INTO jobs (bookmark_id, job_type, payload, created_at, updated_at)
            VALUES (?, 'archive', ?, ?, ?)
        ");
        $stmt->execute([$bookmark['id'], $bookmark['url'], $now, $now]);

        // Queue thumbnail job
        $stmt = $db->prepare("
            INSERT INTO jobs (bookmark_id, job_type, payload, created_at, updated_at)
            VALUES (?, 'thumbnail', ?, ?, ?)
        ");
        $stmt->execute([$bookmark['id'], $bookmark['url'], $now, $now]);

        $added++;
        echo "✓\n";
    }

    echo "\nDone! Created jobs for {$added} bookmarks.\n";
    echo "Run process_jobs.php to process them.\n";

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}
