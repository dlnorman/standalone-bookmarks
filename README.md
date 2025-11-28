# Standalone Bookmarks

A powerful, self-hosted bookmark manager built for speed, privacy, and simplicity.

**Standalone Bookmarks** gives you full control over your internet memory. Save links, organize with tags, automatically archive content, and visualize your reading habits—all without subscriptions or third-party tracking.

---

## Why Standalone Bookmarks?

*   **🚀 Fast & Lightweight**: Built with pure PHP and SQLite. No heavy frameworks, no complex build steps.
*   **🔒 Privacy-Focused**: Self-hosted means you own your data. Multi-user support allows you to share your instance or keep it private.
*   **📸 Visual**: Automatically generates screenshots for your bookmarks using Google PageSpeed Insights or OpenGraph data.
*   **💾 Forever Safe**: Automatically submits your bookmarks to the Internet Archive (Wayback Machine) so you never lose a link.
*   **📊 Insightful**: Beautiful D3.js visualizations show you what you're reading and how your interests connect.

## Key Features

*   **Smart Capture**:
    *   **Bookmarklet**: Save links from any browser with a single click.
    *   **Auto-Metadata**: Automatically extracts titles, descriptions, and tags.
    *   **Screenshots**: Visual gallery view of your saved pages.
*   **Organization**:
    *   **Full-Text Search**: Instantly find anything across titles, URLs, descriptions, and tags.
    *   **Tagging**: Flexible tagging system with a co-occurrence network graph.
    *   **Collections**: Public and private visibility settings.
*   **Management**:
    *   **Multi-User**: Role-based access (Admin/User). Admins can manage users and system settings.
    *   **Health Checks**: Background jobs automatically check for broken links.
    *   **Import/Export**: seamless migration from Pinboard, Delicious, or browser exports (Netscape HTML format).
*   **Performance**:
    *   **Caching**: HTTP caching and database indexing for high performance.
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
*   `gallery.php` - Grid view with screenshots.
*   `dashboard.php` - Analytics and visualizations.
*   `api.php` - REST API endpoints.
*   `process_jobs.php` - Worker script for background tasks.
*   `bookmarks.db` - SQLite database (created after init).

### Contributing
Contributions are welcome! Please feel free to submit a Pull Request.

## License

Public Domain. Free to use, modify, and distribute.
