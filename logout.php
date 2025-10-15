<?php
/**
 * Logout page
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

logout();

header('Location: ' . $config['base_path'] . '/login.php');
exit;
