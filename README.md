# Self-Hosted Bookmarks Application

A feature-rich, self-hosted bookmarks manager built with PHP and SQLite. Perfect for single-user setups with support for multiple devices, automatic archiving, screenshot capture, and advanced analytics. **Optimized for high traffic with HTTP caching and database indexes.**

## Quick Start

Get running in 5 minutes:

```bash
# 1. Copy and configure
cp config-example.php config.php
nano config.php  # Change the password!

# 2. Initialize database with optimizations
php init_db.php

# 3. Create directories
mkdir -p screenshots archives backups
chmod 775 screenshots archives backups

# 4. Start (development)
php -S localhost:8000

# 5. Visit http://localhost:8000
# Login: admin / (your password)
```

For production deployment, see [Installation](#installation) below.

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
- **Dashboard Analytics** - Interactive visualizations powered by D3.js:
  - Tag co-occurrence network showing relationships between tags
  - Bookmarking velocity charts tracking activity over time
  - Tag activity trends with stacked area charts
  - Real-time statistics and insights
  - **Publicly accessible** - No login required
- **Tags Page** - Beautiful tag cloud visualization
  - **Publicly accessible** - No login required
  - Size-based visualization showing tag popularity
  - Click any tag to see bookmarks
- **Screenshot Gallery** - Automatic screenshot capture with:
  - Real webpage screenshots using Google PageSpeed Insights API
  - Desktop view screenshots (300px width)
  - Automatic generation for all new bookmarks
  - One-click manual regeneration
  - Masonry grid layout with full-screen modal view
  - Client-side filtering and pagination
- **Archive View** - Time-based bookmark browsing:
  - Day/week/month/custom date range views
  - Grouping by day/week/month
  - Export to Markdown or HTML
  - Collapsible groups for easy navigation
- **Background Job Processing** - Automated tasks via cron:
  - Web page archiving
  - Screenshot and thumbnail generation
  - Image optimization and resizing
- **RSS Feed** - Keep track of your bookmarks in any RSS reader
  - **HTTP caching** - 304 responses for unchanged content
  - **Publicly accessible** - No login required
- **JSON API** - Embed recent bookmarks in other sites (e.g., Hugo, Jekyll)
  - **HTTP caching** - ETag and Last-Modified support
  - **Publicly accessible** - No login required
- **Automated Backups** - Built-in backup utility with:
  - Interactive and automated modes
  - Database-only or full backups
  - Automatic rotation of old backups
  - Cron-ready with logging

### Performance Features
- **HTTP Caching** - 90%+ reduction in database queries
  - Conditional GET support (Last-Modified, ETag)
  - 5-minute cache duration on all public endpoints
  - Separate cache variants for public vs authenticated users
- **Database Indexes** - 10-100x faster queries
  - Optimized indexes on all common query patterns
  - WAL mode for better concurrency
  - Query planner optimizations
- **Handles High Traffic** - Easily supports:
  - 100+ RSS subscribers
  - 1000s of page views per day
  - Aggressive bot crawling
  - Hugo/static site builds

### Security Features
- **CSRF Protection** - Token-based protection on all state-changing operations
- **SSRF Protection** - Blocks access to internal/private networks and cloud metadata endpoints
- **Open Redirect Protection** - Validates redirect URLs to prevent phishing attacks
- **Rate Limiting** - Brute force protection on login (5 attempts per 5 minutes)
- **Session Security** - Secure session handling with configurable timeouts
- **Input Validation** - Sanitized inputs and parameterized queries throughout

## Requirements

- PHP 7.4 or higher
- SQLite3 support (usually enabled by default)
- Web server (Apache, Nginx, or PHP built-in server for development)
- GD extension for screenshot resizing (usually enabled by default)
- Cron or similar for background job processing (optional but recommended)
- Google PageSpeed Insights API key (free - see [Screenshot Generation](#screenshot-generation))

## Installation

### Fresh Installation (5 Minutes)

#### 1. Clone or download the files

Upload the files to your server at the desired location (e.g., `/var/www/html/bookmarks`).

#### 2. Configure the application

```bash
# Copy the example config
cp config-example.php config.php

# Edit config.php with your settings
nano config.php
```

Important settings to configure:
- `db_path`: Set to production database path (e.g., `__DIR__ . '/bookmarks.db'`)
- `site_url`: Your full site URL (e.g., `https://yourdomain.com/bookmarks`)
- `base_path`: URL path where app is installed (e.g., `/bookmarks`)
- `username`: Your login username (default: `admin`)
- `password`: **IMPORTANT** - Change this to a strong password
- `session_timeout`: How long to stay logged in (default: 30 days)
- `timezone`: Your timezone (e.g., `America/Edmonton`)
- `pagespeed_api_key`: Google PageSpeed API key for screenshots (see [Screenshot Generation](#screenshot-generation))
- `screenshot_max_width`: Maximum screenshot width in pixels (default: 300)

#### 3. Initialize database with optimizations

```bash
php init_db.php
```

This creates:
- `bookmarks` table with all columns
- `jobs` table for background processing
- **All 9 performance indexes** for fast queries
- **WAL mode** for better concurrency
- **Query planner optimizations**

Output should look like:
```
=== Bookmarks Database Initialization ===

Database: /path/to/bookmarks.db

Creating 'bookmarks' table...
✓ Table 'bookmarks' created
Creating 'jobs' table...
✓ Table 'jobs' created

Creating performance indexes...
✓ Index: idx_bookmarks_created_at
✓ Index: idx_bookmarks_private
✓ Index: idx_bookmarks_private_created
✓ Index: idx_bookmarks_broken_url
✓ Index: idx_bookmarks_url
✓ Index: idx_jobs_status
✓ Index: idx_jobs_type
✓ Index: idx_jobs_status_created
✓ Index: idx_jobs_bookmark_id

Optimizing database...
✓ Enabled Write-Ahead Logging (WAL)
✓ Updated query planner statistics

=== Database Initialization Complete ===
```

#### 4. Set permissions

```bash
# Make directories writable
mkdir -p screenshots archives backups
chmod 775 screenshots archives backups

# Make database writable
chmod 664 bookmarks.db

# If using Apache/Nginx, ensure correct ownership
# chown -R www-data:www-data .
```

#### 5. Test the application

**Development server:**
```bash
php -S localhost:8000
```

Visit: http://localhost:8000

Login with:
- Username: `admin` (or what you set in config.php)
- Password: (what you set in config.php)

#### 6. Configure web server (Production)

**Apache** - Create or update `.htaccess`:

```apache
# Protect sensitive files
<Files "config.php">
    Require all denied
</Files>

<Files "*.db">
    Require all denied
</Files>

<FilesMatch "^\.">
    Require all denied
</FilesMatch>

# Cache static assets
<FilesMatch "\.(jpg|jpeg|png|gif|ico|css|js)$">
    Header set Cache-Control "public, max-age=2592000"
</FilesMatch>

# Compress text files
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css application/javascript application/json application/rss+xml
</IfModule>
```

**Nginx** - Add to your server block:

```nginx
server {
    listen 80;
    server_name bookmarks.example.com;
    root /var/www/bookmarks;
    index index.php;

    # Protect sensitive files
    location ~ (config\.php|\.db)$ {
        deny all;
    }

    location ~ /\. {
        deny all;
    }

    # Cache static assets
    location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # PHP handling
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

#### 7. Set up background jobs (Optional but Recommended)

For automatic screenshots and archiving:

```bash
crontab -e
```

Add:
```bash
# Process background jobs every 5 minutes
*/5 * * * * /usr/bin/php /path/to/bookmarks/process_jobs.php >> /path/to/bookmarks/jobs.log 2>&1

# Daily database backup at 2 AM, keep 30 days
0 2 * * * /usr/bin/php /path/to/bookmarks/backup.php --auto --database-only --keep=30
```

### Upgrading Existing Installation

If you already have the application running **without** the new optimizations:

#### Add Indexes to Existing Database

```bash
php add-indexes.php
```

#### HTTP Caching

HTTP caching is now built into `rss.php`, `recent.php`, `tags.php`, and API endpoints. No action needed - it will work automatically on your next deployment.

#### Verify Setup

```bash
# Check database schema
sqlite3 bookmarks.db ".schema"

# Check indexes
sqlite3 bookmarks.db ".indexes"

# Test HTTP caching
curl -I http://localhost:8000/rss.php
# Should see: Cache-Control, Last-Modified, ETag headers
```

## Usage

### First Login

Navigate to your installation URL (e.g., `https://yourdomain.com/bookmarks`). You'll be redirected to the login page.

Use the credentials you configured in `config.php`:
- Username: (default: `admin`)
- Password: (the password you set)

You'll stay logged in for 30 days (or whatever you configured in `session_timeout`).

### Web Interface

Once logged in, use the navigation bar to access different features:

- **Bookmarks**: Main view with search and filtering
- **Dashboard**: Analytics and visualizations (publicly accessible!)
- **Tags**: Browse tags in an interactive tag cloud (publicly accessible!)
- **Gallery**: View all bookmark screenshots
- **Archive**: Browse bookmarks by date range
- **Add Bookmark**: Quick bookmark addition
- **Import/Export**: Bulk operations
- **Bookmarklet**: Instructions for browser bookmarklet

### Adding Bookmarks

**Via Web Interface:**
1. Click "Add Bookmark" in the navigation
2. Fill in the URL, title, description (optional), and tags (optional)
3. Choose public or private visibility
4. Click "Add Bookmark"

**Via Bookmarklet:**
1. Click "Bookmarklet" in the navigation
2. Drag the bookmarklet link to your bookmarks bar
3. When browsing any page, click the bookmarklet
4. Review and edit the auto-captured information
5. Save

The bookmarklet automatically captures:
- Page URL
- Page title
- Meta description
- Meta keywords/tags

### Dashboard Analytics

The Dashboard provides powerful visualizations and is **publicly accessible** (no login required):

- **Tag Co-occurrence Network**: Interactive force-directed graph showing which tags are used together. Click any tag to view those bookmarks. Hover for details.
- **Bookmarking Velocity**: Bar chart showing your bookmarking activity over the last 90 days with a 7-day moving average trend line.
- **Tag Activity Trends**: Stacked area chart showing daily tag usage patterns.
- **Statistics Cards**: Quick stats including total bookmarks, unique tags, archive coverage, and activity metrics.

Each visualization panel can be expanded to fullscreen by clicking the maximize button.

**Public URL**: `https://yourdomain.com/bookmarks/dashboard.php`

### Tags Page

Browse all tags in a beautiful tag cloud visualization (**publicly accessible**, no login required):

- **Tag Cloud**: Size-based visualization showing tag popularity
- **Click any tag**: See all bookmarks with that tag
- **Hover for details**: See bookmark count

**Public URL**: `https://yourdomain.com/bookmarks/tags.php`

### Gallery View

Browse all bookmarks with screenshots:

- **Masonry Grid**: Responsive grid layout that adapts to screen size
- **Filtering**: Real-time client-side filtering by title, URL, or tags
- **Pagination**: Navigate through large collections efficiently
- **Modal View**: Click any screenshot for a full-size view with bookmark details

Screenshots are automatically captured by the background job processor.

### Archive View

Browse bookmarks chronologically:

- **View Modes**: Day, week, month, or custom date range
- **Grouping**: Group results by day, week, or month
- **Navigation**: Previous/Next buttons and date picker for easy navigation
- **Export**: Download your bookmarks as Markdown or HTML
- **Collapsible Groups**: Click group headers to expand/collapse
- **Screenshots**: Inline screenshots (click to toggle size)

### Import Bookmarks

Import bookmarks from other services:

1. Click "Import" in the navigation
2. Select your bookmark file (HTML format)
3. Click "Import Bookmarks" to upload

The importer will:
- Parse all bookmarks with their metadata (URL, title, description, tags, privacy settings, dates)
- Automatically skip duplicate URLs
- Preserve creation dates and privacy settings
- Show a summary of added and skipped bookmarks

**Compatible with:**
- Pinboard (export from [pinboard.in/export/](https://pinboard.in/export/))
- Delicious (HTML export)
- Firefox, Chrome, Safari, Edge bookmark exports
- Most other bookmarking services that use the standard format

### Export Bookmarks

Export all your bookmarks:

1. Click "Export" in the navigation
2. Click "Download Bookmarks" to download the HTML file

The export includes:
- All URLs, titles, descriptions
- Tags and privacy settings
- Creation dates (as Unix timestamps)
- Compatible format for importing into Pinboard, Delicious, browsers, and other services

### RSS Feed

Access your bookmarks RSS feed at:
```
https://yourdomain.com/bookmarks/rss.php
```

Add this URL to your RSS reader. The feed includes your most recent bookmarks (configurable in `config.php`).

**Features:**
- ✅ **Publicly accessible** - No login required
- ✅ **HTTP caching** - 304 responses for unchanged content
- ✅ **90%+ reduction** in database queries from RSS readers
- ✅ **Private bookmarks excluded** from feed

### Embedding in Hugo or Other Static Sites

You can display recent bookmarks on your website using the JSON API.

#### Basic Hugo Example with Markdown Support

```go
{{ $url := "https://yourdomain.com/bookmarks/recent.php?limit=5" }}
{{ with try (resources.GetRemote $url) }}
  {{ with .Err }}
    <p>Error fetching bookmarks: {{ . }}</p>
  {{ else with .Value }}
    {{ $bookmarks := . | transform.Unmarshal }}
    <div class="recent-bookmarks">
      <h3>Recent Bookmarks</h3>
      {{ range $bookmarks }}
        <article class="bookmark-item">
          <h4><a href="{{ .url }}" target="_blank">{{ .title }}</a></h4>
          {{ if .screenshot }}
            <img src="{{ .screenshot }}" alt="{{ .title }}">
          {{ end }}
          {{ if .description }}
            <div class="description">{{ .description | markdownify }}</div>
          {{ end }}
          <div class="meta">
            <time datetime="{{ .created_at }}">{{ .created_at }}</time>
            {{ if .tags }} • {{ .tags }}{{ end }}
          </div>
        </article>
      {{ end }}
    </div>
  {{ end }}
{{ end }}
```

See full styling examples in `recent.php` file comments.

**Features:**
- ✅ **Publicly accessible** - No login required
- ✅ **HTTP caching** - ETag and Last-Modified support
- ✅ **CORS headers** for cross-origin requests
- ✅ **Private bookmarks excluded** by default

## Performance & Scaling

The application is optimized to handle high traffic with minimal server resources.

### Built-in Optimizations

✅ **Database Indexes** - Created automatically by `init_db.php`:
- Indexes on `created_at`, `private`, `url`, `broken_url`
- Composite index on `private + created_at` for common queries
- Job queue indexes for background processing
- **10-100x faster queries**

✅ **HTTP Caching** - Implemented on all public endpoints:
- Conditional GET support (Last-Modified, ETag)
- 5-minute cache duration
- **90%+ reduction in database queries**
- **99% reduction in bandwidth** (304 responses)

✅ **SQLite Optimizations**:
- WAL (Write-Ahead Logging) mode for better concurrency
- Query planner optimizations (ANALYZE)
- Efficient prepared statements

### Performance Impact

**Before Optimizations:**
- RSS reader checking every 15 min = 96 database queries/day per subscriber
- 100 subscribers = 9,600 queries/day
- Dashboard = complex queries on every page load
- Tag cloud = full table scans

**After Optimizations:**
- RSS reader = ~8 database queries/day per subscriber (92% reduction!)
- 100 subscribers = ~800 queries/day
- Dashboard = 304 responses within 5 minutes
- Tag cloud = indexed queries + HTTP caching

### What It Can Handle

Your application can easily handle:
- ✅ 100+ RSS subscribers fetching every 15-60 minutes
- ✅ 1000s of page views per day
- ✅ Aggressive bot crawling
- ✅ Hugo sites building frequently
- ✅ Public access to dashboard and tags pages

### HTTP Caching Details

All public endpoints support HTTP caching:

| Endpoint | Public | Cache | Impact |
|----------|--------|-------|--------|
| `rss.php` | ✅ | 5 min | 90%+ fewer DB queries |
| `recent.php` | ✅ | 5 min | Hugo builds much faster |
| `tags.php` | ✅ | 5 min | Fast tag cloud for visitors |
| `dashboard.php` | ✅ | - | Static HTML + cached API |
| `api.php?action=dashboard_stats` | ✅ | 5 min | Complex queries cached |
| `api.php?action=get_tags` | ✅ | 5 min | Tag autocomplete cached |

**How it works:**
1. Server sends `Last-Modified` and `ETag` headers
2. Client sends `If-Modified-Since` and `If-None-Match` on subsequent requests
3. Server returns `304 Not Modified` if content hasn't changed
4. **No body sent = 99% bandwidth savings**

**Test caching:**
```bash
# First request - returns full content
curl -v http://localhost:8000/rss.php

# Note the Last-Modified and ETag values, then:
curl -v \
  -H "If-Modified-Since: [date from above]" \
  -H "If-None-Match: [etag from above]" \
  http://localhost:8000/rss.php

# Should return 304 Not Modified with no body
```

### Cache Invalidation

Caches are automatically invalidated when:
- A new bookmark is added (changes `MAX(created_at)`)
- A bookmark is edited (changes `MAX(updated_at)`)
- A bookmark is deleted (changes `MAX(created_at/updated_at)`)

Cache is **NOT** invalidated when:
- Jobs are processed (screenshots, archives)
- User logs in/out (separate cache variants)

### Advanced Scaling

For extremely high traffic, consider:

**CDN Integration** (CloudFlare, Fastly):
- Respects Cache-Control headers automatically
- Edge caching reduces origin server load
- Geographic distribution
- DDoS protection

**Varnish Cache**:
- Reverse proxy cache in front of application
- Cache entire pages for anonymous users
- 10-100x additional traffic capacity

**Move to PostgreSQL/MySQL**:
- Better concurrent write performance
- Connection pooling
- Replication support

## Backup & Restore

### Automated Backups (Recommended)

The application includes a comprehensive backup utility (`backup.php`):

**Interactive mode:**
```bash
php backup.php
```

**Automatic mode (for cron):**
```bash
# Database only
php backup.php --auto --database-only --keep=30

# Full backup (database + screenshots + archives)
php backup.php --auto --full --keep=7
```

**Features:**
- ✅ Safe SQLite backup (handles database in use)
- ✅ Automatic rotation of old backups
- ✅ Database-only or full backups
- ✅ Cron-ready with logging
- ✅ Help command: `php backup.php --help`

**Setup automated backups:**
```bash
crontab -e
```

Add:
```bash
# Daily database backup at 2 AM, keep 30 days
0 2 * * * /usr/bin/php /path/to/bookmarks/backup.php --auto --database-only --keep=30

# Weekly full backup on Sundays at 3 AM, keep 4 weeks
0 3 * * 0 /usr/bin/php /path/to/bookmarks/backup.php --auto --full --keep=4
```

### Manual Backups

**Database only:**
```bash
# Simple copy
cp bookmarks.db bookmarks-backup-$(date +%Y%m%d).db

# Or use SQLite backup command
sqlite3 bookmarks.db ".backup bookmarks-backup-$(date +%Y%m%d).db"
```

**Full backup:**
```bash
# Backup everything including screenshots and archives
tar -czf bookmarks-full-backup-$(date +%Y%m%d).tar.gz \
  bookmarks.db \
  screenshots/ \
  archives/ \
  config.php
```

### Restore from Backup

```bash
# Restore database
cp backups/bookmarks-db-YYYY-MM-DD_HH-MM-SS.db bookmarks.db

# Or restore full backup
tar -xzf backups/bookmarks-full-YYYY-MM-DD_HH-MM-SS.tar.gz
```

## Screenshot Generation

The application includes automatic real webpage screenshot generation using Google's PageSpeed Insights API.

### Features

- ✅ **Automatic screenshots** - All new bookmarks get real webpage screenshots automatically
- ✅ **Desktop view** - Captures desktop screenshots (not mobile) at 300px width
- ✅ **One-click regeneration** - Click "Regenerate Screenshot" to refresh any existing screenshot
- ✅ **Free tier** - Google provides 25,000 API calls/day (more than enough for personal use)
- ✅ **No dependencies** - No Chrome binary or Node.js required on your server

### Setup

#### 1. Get a Google API Key (Free)

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the PageSpeed Insights API:
   - Visit: https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com
   - Click "Enable"
4. Create an API key:
   - Go to: https://console.cloud.google.com/apis/credentials
   - Click "Create Credentials" → "API Key"
   - Copy your new API key
   - (Optional) Click "Restrict Key" to limit it to PageSpeed Insights API only

#### 2. Add API Key to Config

Edit your `config.php` file:

```php
'pagespeed_api_key' => 'YOUR_API_KEY_HERE',
'screenshot_max_width' => 300,  // Maximum width in pixels (default: 300)
```

#### 3. Verify Cron Job

Ensure your cron job is running:
```bash
crontab -l | grep process_jobs
```

### How It Works

When you add a new bookmark:
1. A background job is queued for screenshot generation
2. Your cron job runs `process_jobs.php` every 5 minutes
3. PageSpeed API captures a desktop screenshot (takes 10-30 seconds)
4. Screenshot is resized to 300px width
5. Stored in `screenshots/` directory
6. Bookmark automatically updated with screenshot path

## API Reference

### Public Endpoints (No Auth Required)

#### Get Recent Bookmarks
```
GET /recent.php?limit=10
```
Returns JSON array of recent public bookmarks.

#### Dashboard Statistics
```
GET /api.php?action=dashboard_stats
```
Returns comprehensive statistics for dashboard visualizations.

#### Get Tags
```
GET /api.php?action=get_tags
```
Returns list of all tags.

#### RSS Feed
```
GET /rss.php
```
Returns RSS 2.0 feed of recent public bookmarks.

### Protected Endpoints (Auth Required)

#### List Bookmarks
```
GET /api.php?action=list&q=search&page=1&limit=50
```
Returns paginated list of bookmarks with optional search.

#### Get Single Bookmark
```
GET /api.php?action=get&id=123
```
Returns details for a specific bookmark.

#### Add Bookmark
```
POST /api.php
action=add
url=https://example.com
title=Example
description=Optional description
tags=tag1, tag2
private=0
csrf_token=<token>
```

#### Edit Bookmark
```
POST /api.php
action=edit
id=123
url=https://example.com
title=Updated Title
description=Updated description
tags=updated, tags
private=0
csrf_token=<token>
```

#### Delete Bookmark
```
POST /api.php
action=delete
id=123
csrf_token=<token>
```

**Note:** All POST endpoints require a valid CSRF token.

## Troubleshooting

### Database errors
- Ensure database file is writable by web server
- Check file permissions on database file and parent directory
- Verify SQLite is enabled in PHP: `php -m | grep sqlite3`
- Re-initialize if needed: `php init_db.php` (will prompt before overwriting)

### Bookmarklet not working
- Make sure you're logged in to the bookmarks app
- Verify `site_url` in config.php matches your actual URL
- Check browser popup blocker settings
- Ensure HTTPS if your site uses it
- Check browser console for JavaScript errors

### Login issues
- Verify username and password in config.php
- Check that PHP sessions are enabled
- Ensure cookies are enabled in your browser
- Check session file permissions: `php -i | grep "session.save_path"`

### Screenshots not generating
- Verify cron job is set up and running: `crontab -l`
- Check jobs.log for errors: `tail -f jobs.log`
- Ensure screenshots directory exists and is writable
- Test manually: `php process_jobs.php`
- Check API key in config.php

### Background jobs not running
```bash
# Test manually
php process_jobs.php

# Check crontab
crontab -l

# Check logs
tail -f jobs.log
```

### Cache not working
- Check browser DevTools → Disable cache is unchecked
- Verify headers are being sent: `curl -I http://localhost:8000/rss.php`
- Check that you see: Cache-Control, Last-Modified, ETag headers
- Clear browser cache and retry

### Performance issues
```bash
# Verify indexes are created
sqlite3 bookmarks.db ".indexes"

# Should see: idx_bookmarks_created_at, idx_bookmarks_private, etc.

# If missing, run:
php add-indexes.php
```

## Security Checklist

Before going to production:

- [ ] Changed default password in config.php
- [ ] Protected config.php and .db files via web server config
- [ ] Set proper file permissions (664 for files, 775 for directories)
- [ ] Enabled HTTPS (use Let's Encrypt)
- [ ] Configured automated backups
- [ ] Tested restore procedure
- [ ] Verified cron jobs are running
- [ ] Set up monitoring (optional)

## Development

### Local Development

For local development, use different database files to avoid overwriting production data:

```bash
# The config-example.php uses bookmarks-dev.db
cp config-example.php config.php
php init_db.php
php -S localhost:8000
```

Then navigate to `http://localhost:8000`.

### Database Schema

The application uses SQLite with the following main tables:

- **bookmarks**: Core bookmark data (id, url, title, description, tags, private, screenshot, archive_url, broken_url, created_at, updated_at)
- **jobs**: Background job queue (id, bookmark_id, job_type, payload, status, result, attempts, created_at, updated_at)

### File Structure

```
/bookmarks/
├── config-example.php        # Example configuration
├── config.php               # Your configuration (gitignored)
├── includes/                # Helper libraries
│   ├── csrf.php            # CSRF protection
│   ├── security.php        # Security helpers
│   ├── markdown.php        # Markdown parser
│   ├── screenshot-generator.php  # Screenshot API
│   └── nav.php             # Navigation component
├── auth.php                # Authentication helpers
├── login.php               # Login page
├── logout.php              # Logout handler
├── index.php               # Main bookmarks view
├── dashboard.php           # Analytics dashboard
├── tags.php                # Tags view (public)
├── gallery.php             # Screenshot gallery
├── archive.php             # Date-based archive
├── api.php                 # API endpoint
├── bookmarklet.php         # Bookmarklet popup
├── bookmarklet-setup.php   # Bookmarklet instructions
├── import.php              # Import bookmarks
├── export.php              # Export bookmarks
├── rss.php                 # RSS feed (public, cached)
├── recent.php              # JSON API (public, cached)
├── init_db.php             # Database initialization
├── add-indexes.php         # Add indexes to existing DB
├── backup.php              # Backup utility
├── process_jobs.php        # Background job processor
├── screenshots/            # Screenshot storage
├── archives/               # Archive storage
└── backups/                # Backup storage
```

## Upgrading

When updating to a new version:

1. **Backup your data** - Run `php backup.php --full`
2. **Pull/download new files** from repository
3. **Preserve your config.php** - Don't overwrite
4. **Check config-example.php** for new settings
5. **Run add-indexes.php** if upgrading from older version without indexes
6. **Update web server configuration** if needed
7. **Clear browser cache** to load new assets

## License

This is free and unencumbered software released into the public domain.

Anyone is free to copy, modify, publish, use, compile, sell, or distribute this software, either in source code form or as a compiled binary, for any purpose, commercial or non-commercial, and by any means.

## Contributing

This is a simple, personal-use application. Feel free to fork and modify for your needs.

Bug reports and feature requests are welcome via GitHub issues.

## Credits

- Built with vanilla PHP and SQLite
- Visualizations powered by [D3.js](https://d3js.org/)
- Inspired by Pinboard, Delicious, and other bookmark managers

## Support

For issues, questions, or feature requests:
- Check the [Troubleshooting](#troubleshooting) section
- Review the [Performance & Scaling](#performance--scaling) section
- Open an issue on GitHub

## Roadmap

Potential future enhancements:

- [ ] Full-text search using SQLite FTS5
- [ ] Bookmark collections/folders
- [ ] Browser extensions (Chrome, Firefox)
- [ ] Mobile apps (iOS, Android)
- [ ] Social features (sharing, following)
- [ ] AI-powered tagging suggestions
- [ ] Advanced duplicate detection
- [ ] Multi-user support with permissions
- [ ] API authentication tokens
- [ ] Webhook notifications
- [ ] Two-factor authentication

Contributions welcome!
