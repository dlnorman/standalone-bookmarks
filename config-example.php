<?php
/**
 * Configuration file for Bookmarks Application
 *
 * IMPORTANT: Copy this file to config.php and adjust settings for your environment
 * The config.php file is gitignored to prevent overwriting your settings
 */

return [
    // Database settings
    'db_path' => __DIR__ . '/bookmarks-dev.db',

    // Application settings
    'site_title' => 'My Bookmarks',
    'site_url' => 'http://localhost/links',  // Change to your server URL (e.g., https://yourdomain.com/links)
    'base_path' => '/links',  // URL path where app is installed

    // RSS feed settings
    'rss_title' => 'My Bookmarks Feed',
    'rss_description' => 'Recent bookmarks',
    'rss_limit' => 50,  // Number of items in RSS feed

    // Recent bookmarks widget settings
    'recent_limit' => 10,  // Number of recent bookmarks to show by default

    // Security settings
    'username' => 'admin',  // Login username
    'password' => 'change-this-password',  // Login password (use a strong password)
    'session_timeout' => 2592000,  // Session timeout in seconds (default: 30 days)

    // Display settings
    'items_per_page' => 50,
    'date_format' => 'Y-m-d H:i',
    'timezone' => 'America/Edmonton',  // See https://www.php.net/manual/en/timezones.php for list
];
