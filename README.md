# Self-Hosted Bookmarks Application

A feature-rich, self-hosted bookmarks manager built with PHP and SQLite. Perfect for single-user setups with support for multiple devices, automatic archiving, screenshot capture, and advanced analytics. **Optimized for high traffic with HTTP caching and database indexes.**

## Features

### Core Functionality
- **Simple and lightweight** - Pure PHP + SQLite, no framework dependencies
- **Full-text search** - Search across titles, descriptions, tags, and URLs
- **Bookmarklet** - Quick bookmark addition with automatic metadata extraction
- **Import/Export** - Compatible with Pinboard, Delicious, and Netscape Bookmark File Format
- **Tags** - Organize bookmarks with tags and browse via interactive tag cloud
- **Public/Private bookmarks** - Control visibility in RSS feeds and public views
- **Markdown support** - Rich text formatting in bookmark descriptions
- **Responsive design** - Works seamlessly on desktop, tablet, and mobile

### Advanced Features
- **Account Management** - Manage your profile, display name, and password securely.
- **Multi-User Support** - Role-based access control (Admin/User).
  - **Admins**: Manage users, reset passwords, and configure system settings.
  - **Users**: Manage bookmarks (shared collection).
- **Dashboard Analytics** - Interactive visualizations powered by D3.js:
  - **Tag Co-occurrence Network**: Interactive graph showing tag relationships. **New:** Download chart as PNG image (fullscreen mode).
  - **Bookmarking Velocity**: Charts tracking activity over time.
  - **Tag Activity Trends**: Stacked area charts for tag usage.
  - **Publicly accessible** - No login required.
- **Screenshot Gallery** - Automatic screenshot capture:
  - **Click to Open**: Clicking a screenshot opens the bookmark URL directly.
  - **Details View**: "Details" button (ℹ️) to view full-size screenshot and metadata.
  - **Masonry Grid**: Responsive layout with filtering.
- **Archive View** - Time-based bookmark browsing with day/week/month grouping.
- **Background Job Processing** - Automated archiving, screenshot generation, and image optimization.
- **RSS Feed** - Public feed with HTTP caching (304 support).
- **JSON API** - Embed recent bookmarks in other sites (e.g., Hugo, Jekyll).
- **Automated Backups** - Built-in utility for database and full backups.

### Performance & Security
- **HTTP Caching** - 90%+ reduction in database queries via Conditional GET.
- **Database Indexes** - Optimized for fast queries.
- **Security** - CSRF protection, SSRF protection, rate limiting, and secure headers.

## Installation & Setup

### Requirements
- PHP 7.4 or higher
- SQLite3 support
- Web server (Apache, Nginx, or PHP built-in)
- GD extension (for image resizing)
- Cron (recommended for background jobs)

### Quick Install

1.  **Download & Configure**
    ```bash
    # Copy config
    cp config-example.php config.php
    
    # Edit settings (CHANGE THE PASSWORD!)
    nano config.php
    ```

2.  **Initialize Database**
    ```bash
    php init_db.php
    ```
    *Note: This script handles both fresh installs and upgrades.*

3.  **Set Permissions**
    ```bash
    mkdir -p screenshots archives backups sessions
    chmod 775 screenshots archives backups sessions
    chmod 664 bookmarks.db
    # Ensure web server owns these (e.g., chown -R www-data:www-data .)
    ```

4.  **Run (Development)**
    ```bash
    php -S localhost:8000
    ```
    Visit `http://localhost:8000` (Login: `admin` / your password)

### Upgrading

To upgrade an existing installation:

1.  **Backup** your database (`cp bookmarks.db bookmarks.db.bak`).
2.  **Upload** the new files (overwrite existing).
3.  **Run** the initialization script:
    ```bash
    php init_db.php
    ```
    This will automatically update your database schema and migrate your user account if needed.

### Web Server Configuration (Production)

**Apache** (`.htaccess` provided):
Ensure `mod_rewrite` and `mod_headers` are enabled. The included `.htaccess` protects config files and enables caching.

**Nginx**:
```nginx
server {
    listen 80;
    server_name bookmarks.example.com;
    root /var/www/bookmarks;
    index index.php;

    # Protect sensitive files
    location ~ (config\.php|\.db)$ { deny all; }
    location ~ /\. { deny all; }

    # Cache static assets
    location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### Background Jobs (Recommended)

Set up a cron job for screenshots and backups:

```bash
crontab -e
```

```bash
# Process jobs every 5 minutes (screenshots, archives)
*/5 * * * * /usr/bin/php /path/to/bookmarks/process_jobs.php >> /path/to/bookmarks/jobs.log 2>&1

# Daily database backup at 2 AM
0 2 * * * /usr/bin/php /path/to/bookmarks/backup.php --auto --database-only --keep=30
```

## Usage

### Adding Bookmarks
- **Web Interface**: Click "Add Bookmark", enter URL/Title/Tags.
- **Bookmarklet**: Drag the "Bookmarklet" link from the app to your browser bar. Click it on any page to save.

### Viewing Bookmarks
- **Index**: Main list. Click the **image or title** to open the URL.
- **Gallery**: Visual grid. Click **image** to open URL. Click **ℹ️ icon** for details.
- **Dashboard**: View analytics. Toggle fullscreen (⛶) on the Network Chart to see the **Download (⬇)** button.

### Account Management
- **Profile**: Click "Account" in the menu to change your display name or password.
- **User Management** (Admin only): Click "User Management" to add or remove users.

### API & Integrations
- **RSS Feed**: `https://yoursite.com/rss.php`
- **JSON API**: `https://yoursite.com/recent.php?limit=10`
- **Full API**: See `api.php` for authenticated endpoints (`add`, `edit`, `delete`, `get`).

## Troubleshooting

- **Database Errors**: Check permissions on `bookmarks.db` and the directory.
- **Screenshots Missing**: Check `jobs.log` and ensure `pagespeed_api_key` is set in `config.php`.
- **Login Failed**: Verify `session.save_path` is writable.

## License

Public Domain. Free to use, modify, and distribute.
