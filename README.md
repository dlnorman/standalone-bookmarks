# Standalone Bookmarks

A powerful, self-hosted bookmark manager built for speed, privacy, and simplicity.

**Standalone Bookmarks** gives you full control over your internet memory. Save links, organize with tags, automatically archive content, and visualize your reading habits—all without subscriptions or third-party tracking.

---

## Why Standalone Bookmarks?

*   **🚀 Fast & Lightweight**: Built with pure PHP and SQLite. No heavy frameworks, no complex build steps.
*   **🔒 Privacy-Focused**: Self-hosted means you own your data. Multi-user support allows you to share your instance or keep it private.
*   **📸 Visual**: Automatically generates screenshots for your bookmarks using Google PageSpeed Insights or OpenGraph data.
*   **💾 Forever Safe**: Automatically submits your bookmarks to the Internet Archive (Wayback Machine) so you never lose a link.
*   **📊 Insightful**: Beautiful D3.js visualizations show you what you're reading and how your interests connect. The tag network uses hardware-accelerated Canvas rendering for smooth performance with large graphs.

## Key Features

*   **Smart Capture**:
    *   **Bookmarklet**: Save links from any browser with a single click.
    *   **Auto-Metadata**: Automatically extracts titles, descriptions, and tags.
    *   **Screenshots**: Visual gallery view of your saved pages.
*   **Organization**:
    *   **Full-Text Search**: Instantly find anything across titles, URLs, descriptions, and tags.
    *   **Tagging**: Flexible tagging system with tag prefixes (`person:`, `via:`), a co-occurrence matrix, and an interactive network graph.
    *   **Tag Aliases**: Define synonym/plural mappings (e.g. `books → book`) without rewriting bookmark data. Searches automatically expand to cover all aliases; the tag cloud groups them under their canonical form.
    *   **Tag Management**: Admin tools to rename, merge, delete, and alias tags — with an auto-suggestion engine that detects likely duplicates.
    *   **Collections**: Public and private visibility settings.
*   **Management**:
    *   **Multi-User**: Role-based access (Admin/User). Admins can manage users and system settings.
    *   **Health Checks**: Background jobs automatically check for broken links.
    *   **Import/Export**: seamless migration from Pinboard, Delicious, or browser exports (Netscape HTML format).
*   **Performance**:
    *   **Caching**: HTTP ETag/Cache-Control caching on all public API endpoints and database indexing for high performance.
    *   **Bot Protection**: `robots.txt` and `.htaccess` hard-block known AI crawlers and scrapers. Per-IP rate limiting (20 req/min, 150 req/hour) on the index page throttles bots that spoof legitimate user-agent strings.
    *   **Background Jobs**: Heavy tasks (archiving, screenshots) run in the background to keep the UI snappy.

---

## Getting Started

### Prerequisites

*   **PHP 7.4+** (with `php-sqlite3`, `php-gd`, `php-curl`, `php-json` extensions)
*   **Web Server**: Apache, Nginx, or PHP's built-in server.
*   **Write Permissions**: The web server needs write access to the application directory.

### Installation

1.  **Clone the Repository**
    ```bash
    git clone https://github.com/yourusername/standalone-bookmarks.git
    cd standalone-bookmarks
    ```

2.  **Configure the Application**
    Copy the example config and edit it.
    ```bash
    cp config-example.php config.php
    nano config.php
    ```
    > **Tip**: To enable automatic screenshots, get a free [Google PageSpeed Insights API Key](https://developers.google.com/speed/docs/insights/v5/get-started) and add it to your config.

3.  **Initialize the Database (Optional)**
    You can initialize the database manually, or let the web installer do it.
    ```bash
    php init_db.php
    ```

4.  **Set Permissions**
    Ensure the web server can write to the necessary directories.
    ```bash
    mkdir -p screenshots archives backups sessions
    chmod 775 screenshots archives backups sessions
    chmod 664 bookmarks.db
    # If using Apache/Nginx, set ownership (e.g., www-data)
    # chown -R www-data:www-data .
    ```

5.  **Run & Install**
    Start the server:
    ```bash
    php -S localhost:8000
    ```
    Visit `http://localhost:8000`. You will be automatically redirected to the **Installation Page** where you can create your admin account.

### Setting Up Background Jobs (Recommended)

To enable automatic archiving, screenshot generation, and link checking, set up a cron job.

1.  Open your crontab:
    ```bash
    crontab -e
    ```

2.  Add the following lines:
    ```bash
    # Run background jobs every 5 minutes
    */5 * * * * /usr/bin/php /path/to/standalone-bookmarks/process_jobs.php >> /path/to/standalone-bookmarks/jobs.log 2>&1

    # (Optional) Daily database backup at 2 AM
    0 2 * * * /usr/bin/php /path/to/standalone-bookmarks/backup.php --auto --database-only --keep=30
    ```

---

## Usage Guide

### The Bookmarklet
The fastest way to save links is the **Bookmarklet**.
1.  Log in to your instance.
2.  Look for the "Bookmarklet" link in the footer or settings.
3.  Drag and drop it to your browser's bookmarks bar.
4.  When you're on a page you want to save, just click the bookmarklet!

### API & Integrations
Standalone Bookmarks provides a simple API for your own scripts or static site generators.

*   **RSS Feed**: `https://your-instance.com/rss.php` (Public bookmarks only)
*   **JSON API**: `https://your-instance.com/recent.php?limit=10`
*   **Full API**: See `api.php` for authenticated endpoints to add, edit, or delete bookmarks programmatically.

---

## Development

### Project Structure
*   `index.php` - Main list view.
*   `bookmark.php` - Single-bookmark permalink page (`bookmark.php?id=N`), shareable, with Open Graph preview tags. Private bookmarks return 404 to anonymous visitors.
*   `gallery.php` - Grid view with screenshots.
*   `dashboard.php` - Analytics and visualizations.
*   `tags.php` - Tag cloud with logarithmic sizing.
*   `tag-admin.php` - Tag management (rename, merge, delete, aliases).
*   `tag-network.php` - Interactive tag relationship graph (Canvas-based for performance).
*   `api.php` - REST API endpoints.
*   `process_jobs.php` - Worker script for background tasks.
*   `bookmarks.db` - SQLite database (created after init).

### Contributing
Contributions are welcome! Please feel free to submit a Pull Request.

## License

Public Domain. Free to use, modify, and distribute.

## PrivacyLib GDPR integration

Original GDPR framework: **[PrivacyLib-REGO](https://github.com/CS-UCY-SEIT-lab/PrivacyLib-REGO)**. Its unchanged PHP router and eleven OPA/Rego policies are bundled in `integration/privacy-lib/`.

### Setup

Use PHP 8.2+ with PDO SQLite, cURL and sessions, and [OPA 1.x](https://www.openpolicyagent.org/docs). No Composer or npm package is needed. Follow the original config/installer steps above; create a dummy administrator through the original installer. Keep local config, databases and sessions out of Git.

Add the separate consent table once after installing the original app:

```sh
php integration/migrate.php
```

This CLI-only script preserves existing users/bookmarks. GDPR requests never create tables. Start OPA from the repository directory:

```sh
opa run --server --addr 127.0.0.1:8181 integration/privacy-lib/gdpr
```

`OPA_QUERY_URL` defaults to `http://localhost:8181/v1/query`. Configure it in the PHP server environment for another address. Edit synthetic controller, retention, purpose and service metadata in `integration/privacy-config.json` for the intended use; the retention demonstration protects display_name.

For local testing start:

```sh
php -S 127.0.0.1:8033 server.php
```

Configure `site_url` and `base_path` in ignored `config.php` consistently with this address. Sign in through the original login form and click **Privacy checks** in the top bar. Use the same browser tab/origin. The new built-in-server router allows only public entry points and static assets; config, SQLite, session and helper files are blocked. Apache rules additionally deny the integration directory; Apache behavior was not exercised in the local checks.

### Scope

The existing SQLite bookmarks table has no author/owner user_id. Shared bookmarks, tags, archive content and jobs therefore cannot be attributed to a data subject and are **excluded** from GDPR exports. The mapped data is the authenticated account's stored username and display_name. No email field is invented. Password hashes, role metadata and session tokens are not exported. The original bookmark CRUD and sharing behavior is unchanged.

The adapter uses the original native login/session and CSRF token functions, resolves the current database account on every request, and checks both the existing administrator session role and persisted administrator role for breach assessment. Ordinary users cannot assess breaches. Client IDs/roles/trusted policy checks are rejected. Rectification accepts username/display_name only.

Routes are `GET /gdpr.php`, `GET /gdpr.php?policy=csrf`, `PATCH /gdpr.php?policy=consent`, and eleven POST operations via `?policy=`: consent, lawful, information, access, rectification, erasure, restriction, portability, objection, complaint and breach. These are 14 HTTP method/operation pairs. Mutations require JSON and `X-CSRF-Token`.

Consent writes persist. Other results are decisions and proposed plans; account changes, erasure, transfers, complaints, audit writes and notifications are not executed. Access returns metadata; portability returns selected stored profile values. Synthetic metadata and passing tests do not establish legal compliance.

### Verify independently

```sh
php integration/test.php
```

Optional `PHP_BINARY`, `PHP_INI` and `OPA_BINARY` select runtimes. The native PHP runner copies only source into a temporary fixture, creates an independent SQLite/config/session setup and local PHP/OPA ports, and uses the original installer, native administrator user-management form, original login/logout and bookmark API. It checks all policies, native administrator permissions, two-user isolation, shared-bookmark exclusion, CSRF, strict inputs and OPA outage handling. It never copies or uses your ignored database/config/session files. Original background archive/screenshot jobs are only queued in the fixture and never executed. Fixture files/processes are cleaned afterward. No repository evaluation report files are generated.