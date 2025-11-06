# Self-Hosted Bookmarks Application

A feature-rich, self-hosted bookmarks manager built with PHP and SQLite. Perfect for single-user setups with support for multiple devices, automatic archiving, screenshot capture, and advanced analytics.

## Quick Start

Get running in 5 minutes:

```bash
# 1. Copy and configure
cp config-example.php config.php
nano config.php  # Change the password!

# 2. Create directories
mkdir -p screenshots archives
chmod 775 screenshots archives

# 3. Start (development)
php -S localhost:8000

# 4. Visit http://localhost:8000
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
- **JSON API** - Embed recent bookmarks in other sites (e.g., Hugo, Jekyll)

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
- Cron or similar for background job processing (required for screenshots)
- Google PageSpeed Insights API key (free - see [Screenshot Generation](#screenshot-generation))

## Installation

### 1. Clone or download the files

Upload the files to your server at the desired location (e.g., `/var/www/html/bookmarks`).

### 2. Configure the application

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

### 3. Initialize the database

The database will be created automatically on first use. Visit your installation URL and the application will set up the necessary tables.

### 4. Set permissions

```bash
# Make sure the web server can write to the database and screenshots directory
chmod 664 bookmarks.db
chmod 775 .
mkdir -p screenshots archives
chmod 775 screenshots archives

# If using Apache, ensure the directory owner matches the web server user
# chown -R www-data:www-data .
```

### 5. Configure web server

#### Apache

Create or update `.htaccess`:

```apache
# Protect config and database files
<Files "config.php">
    Require all denied
</Files>

<Files "*.db">
    Require all denied
</Files>

<FilesMatch "^\.">
    Require all denied
</FilesMatch>

# Optional: Pretty URLs
# RewriteEngine On
# RewriteCond %{REQUEST_FILENAME} !-f
# RewriteCond %{REQUEST_FILENAME} !-d
# RewriteRule ^(.*)$ index.php [L,QSA]
```

#### Nginx

Add to your server block:

```nginx
location /bookmarks {
    index index.php;

    # Protect config and database
    location ~ (config\.php|\.db)$ {
        deny all;
    }

    # Protect hidden files
    location ~ /\. {
        deny all;
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

### 6. Set up background jobs (Optional but Recommended)

For automatic screenshot capture and archiving, set up a cron job:

```bash
# Edit crontab
crontab -e

# Add this line to run every 5 minutes
*/5 * * * * /usr/bin/php /path/to/bookmarks/process_jobs.php >> /path/to/bookmarks/jobs.log 2>&1
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
- **Dashboard**: Analytics and visualizations
- **Tags**: Browse tags in an interactive tag cloud
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

The Dashboard provides powerful visualizations:

- **Tag Co-occurrence Network**: Interactive force-directed graph showing which tags are used together. Click any tag to view those bookmarks. Hover for details.
- **Bookmarking Velocity**: Bar chart showing your bookmarking activity over the last 90 days with a 7-day moving average trend line.
- **Tag Activity Trends**: Stacked area chart showing daily tag usage patterns.
- **Statistics Cards**: Quick stats including total bookmarks, unique tags, archive coverage, and activity metrics.

Each visualization panel can be expanded to fullscreen by clicking the maximize button.

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

**Note:** The RSS feed is publicly accessible (no login required), so anyone with the URL can view your public bookmarks. Private bookmarks are excluded from the feed.

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
          <h4 class="bookmark-title">
            <a href="{{ .url }}" target="_blank" rel="noopener">{{ .title }}</a>
          </h4>
          <div class="bookmark-content">
            {{ if .screenshot }}
            <div class="bookmark-screenshot">
              <img src="{{ .screenshot }}" alt="{{ .title }}">
            </div>
            {{ end }}
            {{ if .description }}
            <div class="bookmark-description">
              {{ .description | markdownify }}
            </div>
            {{ end }}
            <div class="bookmark-meta">
              <time datetime="{{ .created_at }}">{{ .created_at }}</time>
              {{ if .tags }} • {{ .tags }}{{ end }}
              {{ if .archive_url }} • <a href="{{ .archive_url }}" target="_blank">Archive</a>{{ end }}
            </div>
          </div>
        </article>
      {{ end }}
    </div>
  {{ end }}
{{ end }}
```

#### Styling

Add this CSS to your Hugo theme to properly format and separate bookmarks:

```css
.recent-bookmarks {
  display: flex;
  flex-direction: column;
  gap: 2rem;
}
.bookmark-item {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 1.5rem;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.bookmark-item:hover {
  box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}
.bookmark-title {
  margin: 0 0 1rem 0;
  font-size: 1.25rem;
}
.bookmark-content {
  overflow: auto;
}
.bookmark-screenshot {
  float: right;
  margin: 0 0 1rem 1.5rem;
  max-width: 300px;
}
.bookmark-screenshot img {
  width: 100%;
  height: auto;
  border-radius: 4px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
@media (max-width: 640px) {
  .bookmark-screenshot {
    float: none;
    max-width: 100%;
    margin: 0 0 1rem 0;
  }
}
.bookmark-title a {
  color: #1d4ed8;
  text-decoration: none;
}
.bookmark-title a:hover {
  text-decoration: underline;
}
.bookmark-description {
  margin-bottom: 1rem;
  line-height: 1.6;
  color: #374151;
}
.bookmark-meta {
  font-size: 0.875rem;
  color: #6b7280;
}
.bookmark-meta a {
  color: #6b7280;
}
```

**Key Features:**
- Uses `| markdownify` to render markdown in descriptions properly
- Clear visual separation between bookmarks with borders and spacing
- Hover effects for better interactivity
- Responsive screenshot display
- Metadata footer with tags and archive links

**Note:** The recent.php endpoint is publicly accessible (no login required) and includes CORS headers for cross-origin requests. Private bookmarks are excluded. The `limit` parameter is optional (defaults to value in config.php).

## API Reference

### Authentication

All API operations (except public endpoints) require an active login session (authenticated via cookies).

### Public Endpoints (No Auth Required)

#### Get Recent Bookmarks
```
GET /recent.php?limit=10
```
Returns JSON array of recent public bookmarks.

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

#### Fetch Page Metadata
```
GET /api.php?action=fetch_meta&url=https://example.com
```
Returns metadata (title, description, keywords) from a given URL.

#### Get Dashboard Statistics
```
GET /api.php?action=dashboard_stats
```
Returns comprehensive statistics for dashboard visualizations.

#### Get Tags
```
GET /api.php?action=get_tags
```
Returns list of all tags with usage counts.

**Note:** All POST endpoints require a valid CSRF token. Get the token from the `csrf_field()` function in forms or the `CSRF_TOKEN` JavaScript constant.

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

Ensure your cron job is running (see [Background Jobs](#background-jobs) section below).

### How It Works

#### Automatic Screenshot Generation

When you add a new bookmark:
1. A background job is queued for screenshot generation
2. Your cron job runs `process_jobs.php` every 5 minutes
3. PageSpeed API captures a desktop screenshot (takes 10-30 seconds)
4. Screenshot is resized to 300px width
5. Stored in `screenshots/` directory
6. Bookmark automatically updated with screenshot path

**Processing speed:**
- 3 bookmarks processed per cron run (every 5 minutes)
- Each screenshot takes 10-30 seconds to generate
- Total: ~1-2 minutes per cron run for 3 screenshots

#### Manual Regeneration

Click "Regenerate Screenshot" on any bookmark to:
1. Delete the old screenshot
2. Generate a fresh one via PageSpeed API
3. Update the bookmark immediately
4. Page reloads to show new screenshot

### API Quota & Limits

**Free Tier:**
- 25,000 requests per day
- 1 request per second

**Usage Estimate:**
- With automatic screenshots: ~3 screenshots every 5 minutes = ~864 per day (max)
- Plus manual regenerations
- **Well within the free tier!**

**Monitoring Usage:**
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Select your project
3. Go to "APIs & Services" → "Dashboard"
4. Click on "PageSpeed Insights API"
5. View quota usage and requests per day

### Testing

**Test the background job processor:**

```bash
# SSH into your server
ssh user@server

# Run the job processor manually
cd /path/to/bookmarks
php process_jobs.php
```

You should see output like:
```
Processing 3 jobs...
Job #123: thumbnail for bookmark #45... ✓ Success: screenshots/example.com/1234567890_abc123.png
Done!
```

**Test by adding a new bookmark:**

1. Log in to your bookmarks app
2. Add a new bookmark (any URL)
3. It will appear without a screenshot initially
4. Wait 5 minutes (for cron to run), or manually run: `php process_jobs.php`
5. Refresh the page - screenshot should appear!

### Troubleshooting

**"PageSpeed API key not configured"**

Add your API key to `config.php`:
```php
'pagespeed_api_key' => 'YOUR_API_KEY_HERE',
```

**"API error: 403"**

Your API key is invalid or the PageSpeed Insights API is not enabled:
1. Check your API key is correct in config.php
2. Enable the API: https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com

**"API error: 429"**

Rate limit exceeded. Options:
1. Wait a few seconds and try again
2. Check your API quota in Google Cloud Console

**Screenshots not generating automatically**

1. Check cron is running: `crontab -l | grep process_jobs`
2. Manually run job processor: `php process_jobs.php`
3. Check for errors in output

**Jobs stuck in "pending" status**

Check the `jobs` table:
```bash
sqlite3 bookmarks.db "SELECT * FROM jobs WHERE status='pending' LIMIT 5;"
```

If attempts = 3, job has failed. Check the `result` column for error message.

### Configuration Options

**Change Screenshot Width**

Edit `config.php`:
```php
'screenshot_max_width' => 400,  // Make screenshots 400px wide
```

**Change Processing Rate**

Edit `process_jobs.php` line ~126:
```php
LIMIT 5  // Process 5 screenshots per run instead of 3
```

**Warning:** More screenshots per run = longer cron execution time!
- 3 screenshots = ~30-90 seconds
- 5 screenshots = ~50-150 seconds

## Background Jobs

The `process_jobs.php` script handles asynchronous tasks:

### Features
- **Screenshot Generation**: Captures real webpage screenshots using Google PageSpeed Insights API
- **Archive Capture**: Creates archival snapshots of bookmarked pages via Wayback Machine
- **Image Optimization**: Automatically resizes screenshots to save storage space

### Setup

Add to crontab to run every 5 minutes:
```bash
*/5 * * * * /usr/bin/php /path/to/bookmarks/process_jobs.php
```

Or run manually:
```bash
php process_jobs.php
```

### Job Queue

Jobs are automatically created when:
- A new bookmark is added (creates archive and screenshot jobs)
- A bookmark is updated (refreshes archive and screenshot if URL changed)

Jobs are processed in the background and will retry on failure.

## Security Features

### CSRF Protection

All state-changing operations are protected against Cross-Site Request Forgery attacks:

- Token-based validation on all POST requests
- Tokens expire after 1 hour
- Timing-attack-safe comparison
- Cryptographically secure token generation

### SSRF Protection

Server-Side Request Forgery protection prevents access to:

- Localhost and loopback addresses (127.0.0.1, ::1)
- Private IP ranges (10.x.x.x, 172.16-31.x.x, 192.168.x.x)
- Link-local addresses (169.254.x.x)
- Cloud metadata endpoints (AWS, GCP, Azure)
- File:// protocol and other non-HTTP(S) schemes

### Open Redirect Protection

Login redirects are validated to prevent phishing:

- Only relative URLs allowed
- Must be within application base path
- Blocks absolute URLs and protocol-relative URLs
- Prevents path traversal attacks

### Rate Limiting

Login attempts are rate-limited per IP address:

- Maximum 5 failed attempts per 5-minute window
- Automatic lockout after threshold exceeded
- Session-based tracking
- Resets on successful login

### Additional Security Measures

- **Parameterized queries** - All database queries use prepared statements
- **Input validation** - All user input is validated and sanitized
- **Output escaping** - All output is properly escaped to prevent XSS
- **Session security** - Secure session configuration with SameSite cookies
- **File access control** - Web server blocks access to sensitive files

## Development

### Local Development

For local development, use different database files to avoid overwriting production data:

```bash
# The config-example.php uses bookmarks-dev.db
cp config-example.php config.php
php -S localhost:8000
```

Then navigate to `http://localhost:8000`.

### Database Schema

The application uses SQLite with the following main tables:

- **bookmarks**: Core bookmark data (id, url, title, description, tags, private, created_at, updated_at)
- **jobs**: Background job queue (id, type, bookmark_id, status, data, created_at, processed_at)

Additional fields on bookmarks table:
- **screenshot**: Path to screenshot image
- **thumbnail**: Path to thumbnail image
- **archive_url**: URL to archived version of page

### File Structure

```
/bookmarks/
├── config-example.php        # Example configuration (copy to config.php)
├── config.php               # Your configuration (gitignored)
├── includes/                # Helper libraries
│   ├── csrf.php            # CSRF token generation and validation
│   ├── security.php        # Security helpers (SSRF, rate limiting, redirect validation)
│   ├── markdown.php        # Markdown parser for descriptions
│   ├── screenshot-generator.php  # PageSpeed API screenshot generator
│   └── nav.php             # Navigation component
├── auth.php                # Authentication helper functions
├── login.php               # Login page
├── logout.php              # Logout handler
├── index.php               # Main bookmarks view
├── dashboard.php           # Analytics dashboard with visualizations
├── tags.php                # Tags view with tag cloud
├── gallery.php             # Screenshot gallery view
├── archive.php             # Date-based archive view
├── api.php                 # API endpoint for bookmark operations
├── bookmarklet.php         # Bookmarklet popup interface
├── bookmarklet-setup.php   # Bookmarklet setup instructions
├── import.php              # Import bookmarks from Pinboard/Delicious
├── export.php              # Export bookmarks to Pinboard/Delicious format
├── rss.php                 # RSS feed generator
├── recent.php              # JSON API for recent bookmarks
├── regenerate-screenshot.php  # AJAX endpoint for manual screenshot regeneration
├── process_jobs.php        # Background job processor (run via cron)
├── screenshots/            # Screenshot storage directory
├── archives/               # Archive storage directory
├── .gitignore             # Git ignore rules
└── README.md              # This file
```

## Troubleshooting

### Database errors
- Ensure database file is writable by web server
- Check file permissions on database file and parent directory
- Verify SQLite is enabled in PHP: `php -m | grep sqlite3`

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
- Check session file permissions

### Screenshots not generating
- Verify cron job is set up and running: `crontab -l`
- Check jobs.log for errors: `tail -f jobs.log`
- Ensure screenshots directory exists and is writable
- Verify background job processor has necessary permissions

### Metadata extraction not working
- Some sites block automated scraping
- Verify PHP `allow_url_fopen` is enabled
- Check that the site has proper meta tags
- SSRF protection may block certain URLs (by design)

### "Invalid CSRF token" errors
- Clear browser cache and reload the page
- Ensure JavaScript is enabled
- Check that cookies are enabled
- Verify session is working properly

### Rate limiting lockout
- Wait 5 minutes for the lockout to expire
- Use the correct password to reset the counter
- Check IP address detection if behind proxy/load balancer

## Performance Tips

### Optimize Database
```bash
# Vacuum and optimize database periodically
sqlite3 bookmarks.db "VACUUM;"
sqlite3 bookmarks.db "ANALYZE;"
```

### Image Storage
- Screenshots are automatically resized to 1200px width
- Consider periodically cleaning up old screenshots
- Use web server to cache static assets

### Caching
- Enable browser caching for static assets
- Consider using a reverse proxy (nginx, Varnish) for caching

## Backup

### Database Backup
```bash
# Simple copy
cp bookmarks.db bookmarks-backup-$(date +%Y%m%d).db

# Or use SQLite backup command
sqlite3 bookmarks.db ".backup bookmarks-backup-$(date +%Y%m%d).db"
```

### Full Backup
```bash
# Backup everything including screenshots and archives
tar -czf bookmarks-full-backup-$(date +%Y%m%d).tar.gz \
  bookmarks.db \
  screenshots/ \
  archives/ \
  config.php
```

### Automated Backups
Add to crontab for daily backups:
```bash
0 2 * * * /path/to/backup-script.sh
```

## Upgrading

When updating to a new version:

1. **Backup your data** (database and config)
2. **Pull/download new files** from repository
3. **Preserve your config.php** (don't overwrite)
4. **Check config-example.php** for new settings
5. **Update web server configuration** if needed
6. **Run any migration scripts** (if provided)
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
- Review the [Security Features](#security-features) section
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
- [ ] Wayback Machine integration
- [ ] Multi-user support with permissions
- [ ] API authentication tokens
- [ ] Webhook notifications
- [ ] Two-factor authentication

Contributions welcome!
