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
    'site_url' => 'http://localhost/bookmarks',  // Change to your server URL (e.g., https://yourdomain.com/bookmarks)
    'base_path' => '/bookmarks',  // URL path where app is installed

    // RSS feed settings
    'rss_title' => 'My Bookmarks Feed',
    'rss_description' => 'Recent bookmarks',
    'rss_limit' => 50,  // Number of items in RSS feed

    // Recent bookmarks widget settings
    'recent_limit' => 10,  // Number of recent bookmarks to show by default

    // Session settings
    'session_timeout' => 2592000,  // Session timeout in seconds (default: 30 days)

    // Display settings
    'items_per_page' => 50,
    'date_format' => 'Y-m-d H:i',
    'timezone' => 'America/Edmonton',  // See https://www.php.net/manual/en/timezones.php for list

    // Screenshot generation using Google PageSpeed Insights API
    // Get a free API key at: https://console.cloud.google.com/apis/credentials
    // Enable PageSpeed Insights API at: https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com
    //
    // IMPORTANT: Screenshots are now generated AUTOMATICALLY for all new bookmarks!
    // - Each screenshot takes 10-30 seconds via PageSpeed API
    // - process_jobs.php generates 3 screenshots per run (every 5 minutes via cron)
    // - Free tier: 25,000 requests/day (more than enough for personal use)
    // - Generates desktop screenshots resized to 300px width
    //
    'pagespeed_api_key' => '',  // REQUIRED - Add your API key here to enable screenshots
    'screenshot_max_width' => 300,  // Maximum width for screenshots in pixels (default: 300)
];
