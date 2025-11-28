#!/usr/bin/env php
<?php
/**
 * Database Initialization Script (CLI)
 *
 * Usage: php init_db.php
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die("Error: config.php not found.\n");
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_setup.php';

try {
    echo "=== Bookmarks Database Initialization ===\n\n";
    echo "Database: " . $config['db_path'] . "\n";

    $setup = new DatabaseSetup($config['db_path']);
    $setup->runSetup();

    echo "✓ Database schema initialized/updated successfully.\n";

    // Check if admin user exists
    $db = new PDO('sqlite:' . $config['db_path']);
    $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

    if ($count == 0) {
        echo "\n⚠ No users found in database.\n";
        echo "Please visit the web interface to create your admin account:\n";
        echo $config['site_url'] . "/install.php\n";
    } else {
        echo "✓ Users exist in database.\n";
    }

} catch (Exception $e) {
    die("\nError: " . $e->getMessage() . "\n");
}
