#!/usr/bin/env php
<?php
/**
 * Database Backup Utility
 *
 * Backs up the bookmarks database and optionally screenshots/archives
 *
 * Usage:
 *   php backup.php                    # Interactive mode
 *   php backup.php --auto              # Automatic mode (for cron)
 *   php backup.php --database-only     # Database only
 *   php backup.php --full             # Full backup (db + screenshots + archives)
 *   php backup.php --keep=7           # Keep only last 7 backups
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die("Error: config.php not found.\n");
}

$config = require __DIR__ . '/config.php';

// Default backup directory
$backupDir = __DIR__ . '/backups';

// Parse command line arguments
$options = getopt('', ['auto', 'database-only', 'full', 'keep:', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$autoMode = isset($options['auto']);
$databaseOnly = isset($options['database-only']);
$fullBackup = isset($options['full']);
$keepBackups = isset($options['keep']) ? intval($options['keep']) : 30;

// Interactive mode if no options specified
if (!$autoMode && !$databaseOnly && !$fullBackup) {
    echo "=== Bookmarks Backup Utility ===\n\n";
    echo "What would you like to backup?\n";
    echo "1. Database only (quick)\n";
    echo "2. Full backup (database + screenshots + archives)\n";
    echo "\nChoice [1]: ";

    $choice = trim(fgets(STDIN));
    if (empty($choice)) {
        $choice = '1';
    }

    $fullBackup = ($choice === '2');
    $databaseOnly = ($choice === '1');
}

// Create backup directory if it doesn't exist
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0755, true);
    if (!$autoMode) {
        echo "✓ Created backup directory: $backupDir\n";
    }
}

// Generate timestamp
$timestamp = date('Y-m-d_H-i-s');
$datePrefix = date('Ymd');

// Backup database
$dbPath = $config['db_path'];
if (!file_exists($dbPath)) {
    die("Error: Database file not found at $dbPath\n");
}

$dbBackupPath = "$backupDir/bookmarks-db-$timestamp.db";

if (!$autoMode) {
    echo "\nBacking up database...\n";
    echo "Source: $dbPath\n";
    echo "Destination: $dbBackupPath\n";
}

// Use SQLite .backup command for safe backup
try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get database size
    $dbSize = filesize($dbPath);

    // Perform backup using SQLite backup API
    $backupDb = new PDO('sqlite:' . $dbBackupPath);
    $backupDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQLite backup - this is the safe way to backup a database that might be in use
    $sourceDb = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $destDb = new SQLite3($dbBackupPath);

    // Copy the database
    $sourceDb->backup($destDb);

    $sourceDb->close();
    $destDb->close();

    $backupSize = filesize($dbBackupPath);

    if (!$autoMode) {
        echo "✓ Database backed up successfully (" . formatBytes($backupSize) . ")\n";
    }

} catch (Exception $e) {
    // Fallback to simple file copy if SQLite backup fails
    if (!$autoMode) {
        echo "⚠ SQLite backup failed, using file copy method\n";
    }

    if (!copy($dbPath, $dbBackupPath)) {
        die("Error: Failed to backup database\n");
    }

    if (!$autoMode) {
        $backupSize = filesize($dbBackupPath);
        echo "✓ Database backed up successfully (" . formatBytes($backupSize) . ")\n";
    }
}

// Full backup if requested
$fullBackupPath = null;
if ($fullBackup) {
    if (!$autoMode) {
        echo "\nCreating full backup archive...\n";
    }

    $fullBackupPath = "$backupDir/bookmarks-full-$timestamp.tar.gz";

    // Directories to include
    $includeItems = [
        $dbPath,
        'config.php'
    ];

    // Add screenshots if they exist
    $screenshotsDir = __DIR__ . '/screenshots';
    if (is_dir($screenshotsDir)) {
        $includeItems[] = 'screenshots';
    }

    // Add archives if they exist
    $archivesDir = __DIR__ . '/archives';
    if (is_dir($archivesDir)) {
        $includeItems[] = 'archives';
    }

    // Build tar command
    $items = implode(' ', array_map('escapeshellarg', $includeItems));
    $tarCmd = "tar -czf " . escapeshellarg($fullBackupPath) . " -C " . escapeshellarg(__DIR__) . " $items 2>&1";

    exec($tarCmd, $output, $returnCode);

    if ($returnCode === 0 && file_exists($fullBackupPath)) {
        $fullSize = filesize($fullBackupPath);
        if (!$autoMode) {
            echo "✓ Full backup created (" . formatBytes($fullSize) . ")\n";
            echo "  Location: $fullBackupPath\n";
        }
    } else {
        if (!$autoMode) {
            echo "⚠ Warning: Full backup failed\n";
            echo "  Output: " . implode("\n  ", $output) . "\n";
        }
    }
}

// Clean up old backups
if ($keepBackups > 0) {
    if (!$autoMode) {
        echo "\nCleaning up old backups (keeping last $keepBackups)...\n";
    }

    // Get all backup files
    $dbBackups = glob("$backupDir/bookmarks-db-*.db");
    $fullBackups = glob("$backupDir/bookmarks-full-*.tar.gz");

    // Sort by modification time (oldest first)
    usort($dbBackups, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });

    usort($fullBackups, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });

    // Delete old database backups
    $dbDeleted = 0;
    while (count($dbBackups) > $keepBackups) {
        $oldestBackup = array_shift($dbBackups);
        if (unlink($oldestBackup)) {
            $dbDeleted++;
            if (!$autoMode) {
                echo "  Deleted: " . basename($oldestBackup) . "\n";
            }
        }
    }

    // Delete old full backups
    $fullDeleted = 0;
    while (count($fullBackups) > $keepBackups) {
        $oldestBackup = array_shift($fullBackups);
        if (unlink($oldestBackup)) {
            $fullDeleted++;
            if (!$autoMode) {
                echo "  Deleted: " . basename($oldestBackup) . "\n";
            }
        }
    }

    if (!$autoMode && $dbDeleted === 0 && $fullDeleted === 0) {
        echo "  No old backups to delete\n";
    }
}

// Summary
if (!$autoMode) {
    echo "\n=== Backup Complete ===\n";
    echo "Database backup: $dbBackupPath\n";
    if ($fullBackupPath && file_exists($fullBackupPath)) {
        echo "Full backup: $fullBackupPath\n";
    }
    echo "\nBackup directory: $backupDir\n";
    echo "Total backups: " . count(glob("$backupDir/bookmarks-*.*")) . "\n";
    echo "\nTo restore database:\n";
    echo "  cp $dbBackupPath " . $config['db_path'] . "\n";
} else {
    // Log for cron
    $logFile = __DIR__ . '/backup.log';
    $logMessage = date('Y-m-d H:i:s') . " - Backup completed: " . basename($dbBackupPath);
    if ($fullBackupPath && file_exists($fullBackupPath)) {
        $logMessage .= " + " . basename($fullBackupPath);
    }
    $logMessage .= "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

exit(0);

/**
 * Format bytes to human readable format
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Show help message
 */
function showHelp() {
    echo <<<HELP
Bookmarks Database Backup Utility

Usage:
  php backup.php [options]

Options:
  --auto              Run in automatic mode (silent, for cron)
  --database-only     Backup database only (quick)
  --full              Full backup (database + screenshots + archives)
  --keep=N            Keep only last N backups (default: 30)
  --help              Show this help message

Examples:
  php backup.php                    # Interactive mode
  php backup.php --auto             # Automatic backup (for cron)
  php backup.php --database-only    # Database only
  php backup.php --full --keep=7    # Full backup, keep last 7

Cron Examples:
  # Daily database backup at 2 AM, keep 30 days
  0 2 * * * /usr/bin/php /path/to/bookmarks/backup.php --auto --database-only

  # Weekly full backup on Sundays at 3 AM, keep 4 weeks
  0 3 * * 0 /usr/bin/php /path/to/bookmarks/backup.php --auto --full --keep=4

Restore:
  cp backups/bookmarks-db-YYYY-MM-DD_HH-MM-SS.db bookmarks.db

  # Or for full backup:
  tar -xzf backups/bookmarks-full-YYYY-MM-DD_HH-MM-SS.tar.gz

HELP;
}
