# Self-Hosted Bookmarks Application

A lightweight, self-hosted bookmarks manager built with PHP and SQLite. Perfect for single-user setups with support for multiple devices.

## Features

- Simple and lightweight (PHP + SQLite, no framework dependencies)
- Full-text search across titles, descriptions, tags, and URLs
- Bookmarklet for quick bookmark addition with automatic metadata extraction
- RSS feed for your bookmarks
- JSON API for embedding recent bookmarks in other sites (e.g., Hugo)
- Responsive design works on desktop, tablet, and mobile
- Session-based authentication for secure single-user access
- Tag support for organizing bookmarks

## Requirements

- PHP 7.4 or higher
- SQLite3 support (usually enabled by default)
- Web server (Apache, Nginx, or PHP built-in server for development)

## Installation

### 1. Clone or download the files

Upload the files to your server at the desired location (e.g., `/var/www/html/links`).

### 2. Configure the application

```bash
# Copy the example config
cp config-example.php config.php

# Edit config.php with your settings
nano config.php
```

Important settings to change:
- `db_path`: Set to production database path (e.g., `__DIR__ . '/bookmarks.db'`)
- `site_url`: Your full site URL (e.g., `https://yourdomain.com/links`)
- `base_path`: URL path where app is installed (e.g., `/links`)
- `username`: Your login username (default: `admin`)
- `password`: **IMPORTANT** - Change this to a strong password
- `session_timeout`: How long to stay logged in (default: 30 days)

### 3. Initialize the database

```bash
# Run the initialization script
php init_db.php
```

### 4. Set permissions

```bash
# Make sure the web server can write to the database
chmod 664 bookmarks.db
chmod 775 .

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

# Optional: Pretty URLs
# RewriteEngine On
# RewriteCond %{REQUEST_FILENAME} !-f
# RewriteCond %{REQUEST_FILENAME} !-d
# RewriteRule ^(.*)$ index.php [L,QSA]
```

#### Nginx

Add to your server block:

```nginx
location /links {
    index index.php;

    # Protect config and database
    location ~ (config\.php|\.db)$ {
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

## Usage

### First Login

Navigate to your installation URL (e.g., `https://yourdomain.com/links`). You'll be redirected to the login page.

Use the credentials you configured in `config.php`:
- Username: (default: `admin`)
- Password: (the password you set)

You'll stay logged in for 30 days (or whatever you configured in `session_timeout`).

### Web Interface

Once logged in:

- **Search**: Use the search box to find bookmarks by title, description, tags, or URL
- **Add Bookmark**: Click "Add Bookmark" button and fill in the form
- **Edit/Delete**: Use the links on each bookmark card
- **Browse Tags**: Click "Tags" to see all your tags in a tag cloud, sized by frequency
- **Filter by Tag**: Click any tag (in the tag cloud or in a bookmark) to see all bookmarks with that tag
- **Logout**: Click "Logout" button when done

### Bookmarklet

1. Make sure you're logged in to your bookmarks app
2. Click "Bookmarklet" in the web interface
3. Follow the instructions to create a bookmark with the provided JavaScript code
4. When browsing any page, click the bookmarklet to automatically capture:
   - Page URL
   - Page title
   - Meta description
   - Meta keywords/tags

The bookmarklet will open a popup where you can review and edit before saving. Note: You need to be logged in to your bookmarks app for the bookmarklet to work.

### RSS Feed

Access your bookmarks RSS feed at:
```
https://yourdomain.com/links/rss.php
```

Add this URL to your RSS reader. The feed includes your most recent bookmarks (configurable in `config.php`).

**Note:** The RSS feed is publicly accessible (no login required), so anyone with the URL can view your bookmarks.

### Embedding in Hugo

You can display recent bookmarks on your Hugo website using the JSON API:

```go
{{ $url := "https://yourdomain.com/links/recent.php?limit=5" }}
{{ with try (resources.GetRemote $url) }}
  {{ with .Err }}
    <p>Error fetching bookmarks: {{ . }}</p>
  {{ else with .Value }}
    {{ $bookmarks := . | transform.Unmarshal }}
    <div class="recent-bookmarks">
      <h3>Recent Bookmarks</h3>
      <ul>
      {{ range $bookmarks }}
        <li>
          <a href="{{ .url }}" target="_blank">{{ .title }}</a>
          {{ if .description }}
            <p>{{ .description }}</p>
          {{ end }}
          <small>{{ .created_at }}</small>
        </li>
      {{ end }}
      </ul>
    </div>
  {{ end }}
{{ end }}
```

The `limit` parameter is optional (defaults to value in config.php).

**Note:** The recent.php endpoint is publicly accessible (no login required) and includes CORS headers for cross-origin requests, making it easy to embed on your Hugo site or any other website.

## API Reference

### Endpoints

All API operations require an active login session (authenticated via cookies).

#### List Bookmarks
```
GET /api.php?action=list&q=search&page=1&limit=50
```

#### Get Single Bookmark
```
GET /api.php?action=get&id=123
```

#### Add Bookmark
```
POST /api.php
action=add
url=https://example.com
title=Example
description=Optional description
tags=tag1, tag2
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
```

#### Delete Bookmark
```
POST /api.php
action=delete
id=123
```

#### Fetch Page Metadata
```
GET /api.php?action=fetch_meta&url=https://example.com
```

## Development

For local development, use different database files to avoid overwriting production data:

```bash
# The config-example.php uses bookmarks-dev.db
cp config-example.php config.php
php init_db.php

# Start PHP development server
php -S localhost:8000
```

Then navigate to `http://localhost:8000`.

## Security Notes

1. **Change the password**: The default password in `config-example.php` must be changed to a strong password
2. **Use HTTPS**: Always use HTTPS in production to protect your login credentials and session cookies
3. **Protect config.php**: Ensure your web server blocks direct access to config.php (see web server configuration above)
4. **Protect database files**: Ensure .db files cannot be downloaded directly (see web server configuration above)
5. **Single-user only**: This app is designed for single-user use; there's no multi-user support
6. **Session security**: Sessions use PHP's default session handling with a configurable timeout

## File Structure

```
/links/
├── config-example.php    # Example configuration (copy to config.php)
├── config.php           # Your configuration (gitignored)
├── auth.php            # Authentication helper functions
├── login.php           # Login page
├── logout.php          # Logout handler
├── init_db.php         # Database initialization script
├── index.php           # Main web interface
├── tags.php           # Tags view with tag cloud
├── api.php            # API endpoint for bookmark operations
├── bookmarklet.php    # Bookmarklet popup interface
├── rss.php           # RSS feed generator
├── recent.php        # JSON API for recent bookmarks
├── .gitignore        # Git ignore rules
└── README.md         # This file
```

## Troubleshooting

### Database errors
- Ensure `init_db.php` was run successfully
- Check file permissions on database file
- Verify SQLite is enabled in PHP (`php -m | grep sqlite3`)

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

### Metadata extraction not working
- Some sites block automated scraping
- Verify PHP `allow_url_fopen` is enabled
- Check that the site has proper meta tags

## License

This is free and unencumbered software released into the public domain.

## Contributing

This is a simple, personal-use application. Feel free to fork and modify for your needs.
