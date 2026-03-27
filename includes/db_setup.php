<?php
/**
 * Database Setup & Schema Management
 */

class DatabaseSetup
{
    private $db;
    private $dbPath;

    public function __construct($dbPath)
    {
        $this->dbPath = $dbPath;
    }

    /**
     * Connect to database (creates file if missing)
     */
    public function connect()
    {
        try {
            $this->db = new PDO('sqlite:' . $this->dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return true;
        } catch (PDOException $e) {
            throw new Exception("Connection failed: " . $e->getMessage());
        }
    }

    /**
     * Initialize or update database schema
     */
    public function runSetup()
    {
        if (!$this->db) {
            $this->connect();
        }

        $this->createTables();
        $this->updateSchema();
        $this->createIndexes();
        $this->optimize();
    }

    private function createTables()
    {
        // Bookmarks Table
        $this->db->exec("
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

        // Users Table
        $this->db->exec("
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

        // Jobs Table
        $this->db->exec("
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

        // Tag Connections Table (explicit, manually-curated bidirectional links)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tag_connections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tag_from TEXT NOT NULL,
                tag_to TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(tag_from, tag_to)
            )
        ");

        // Tag Aliases Table (alias -> canonical mappings, non-destructive synonym handling)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tag_aliases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                alias TEXT NOT NULL UNIQUE,
                canonical TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    private function updateSchema()
    {
        // Check users.role
        $columns = $this->db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $hasRole = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'role') {
                $hasRole = true;
                break;
            }
        }

        if (!$hasRole) {
            $this->db->exec("ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'user'");
        }
    }

    private function createIndexes()
    {
        $indexes = [
            'idx_bookmarks_created_at' => 'CREATE INDEX IF NOT EXISTS idx_bookmarks_created_at ON bookmarks(created_at DESC)',
            'idx_bookmarks_private' => 'CREATE INDEX IF NOT EXISTS idx_bookmarks_private ON bookmarks(private)',
            'idx_bookmarks_private_created' => 'CREATE INDEX IF NOT EXISTS idx_bookmarks_private_created ON bookmarks(private, created_at DESC)',
            'idx_bookmarks_broken_url' => 'CREATE INDEX IF NOT EXISTS idx_bookmarks_broken_url ON bookmarks(broken_url)',
            'idx_bookmarks_url' => 'CREATE INDEX IF NOT EXISTS idx_bookmarks_url ON bookmarks(url)',
            'idx_jobs_status' => 'CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)',
            'idx_jobs_type' => 'CREATE INDEX IF NOT EXISTS idx_jobs_type ON jobs(job_type)',
            'idx_jobs_status_created' => 'CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON jobs(status, created_at)',
            'idx_jobs_bookmark_id' => 'CREATE INDEX IF NOT EXISTS idx_jobs_bookmark_id ON jobs(bookmark_id)',
            'idx_tag_connections_from' => 'CREATE INDEX IF NOT EXISTS idx_tag_connections_from ON tag_connections(tag_from)',
            'idx_tag_connections_to' => 'CREATE INDEX IF NOT EXISTS idx_tag_connections_to ON tag_connections(tag_to)',
            'idx_tag_aliases_alias' => 'CREATE INDEX IF NOT EXISTS idx_tag_aliases_alias ON tag_aliases(alias)',
            'idx_tag_aliases_canonical' => 'CREATE INDEX IF NOT EXISTS idx_tag_aliases_canonical ON tag_aliases(canonical)'
        ];

        foreach ($indexes as $sql) {
            $this->db->exec($sql);
        }
    }

    private function optimize()
    {
        $this->db->exec("PRAGMA journal_mode=WAL");
        $this->db->exec("ANALYZE");
    }

    /**
     * Create initial admin user
     */
    public function createAdminUser($username, $password, $displayName)
    {
        if (!$this->db) {
            $this->connect();
        }

        // Check if any users exist
        $count = $this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count > 0) {
            throw new Exception("Users already exist. Cannot create initial admin.");
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, display_name, role, created_at, updated_at)
            VALUES (?, ?, ?, 'admin', ?, ?)
        ");

        return $stmt->execute([
            $username,
            $passwordHash,
            $displayName,
            $now,
            $now
        ]);
    }
}
