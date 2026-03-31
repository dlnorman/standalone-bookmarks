<?php
/**
 * Security Helper Functions
 * Provides URL validation, SSRF protection, and rate limiting
 */

/**
 * Validate redirect URL to prevent open redirect attacks
 * Only allows relative URLs within the application
 *
 * @param string $url The redirect URL to validate
 * @param string $basePath The application base path
 * @param string $defaultUrl Fallback URL if validation fails
 * @return string Safe redirect URL
 */
function validate_redirect_url($url, $basePath, $defaultUrl)
{
    // Empty URL - use default
    if (empty($url)) {
        return $defaultUrl;
    }

    // Must be a relative URL (not absolute)
    if (preg_match('#^https?://#i', $url)) {
        // Absolute URL - reject
        return $defaultUrl;
    }

    // Must not contain protocol-relative URLs
    if (strpos($url, '//') === 0) {
        return $defaultUrl;
    }

    // Must start with base path or be a relative path
    if (strpos($url, $basePath) !== 0 && strpos($url, '/') !== 0) {
        return $defaultUrl;
    }

    // Prevent path traversal
    if (strpos($url, '..') !== false) {
        return $defaultUrl;
    }

    return $url;
}

/**
 * Validate URL to prevent SSRF attacks
 * Blocks private IP ranges, localhost, and file:// protocols
 *
 * @param string $url The URL to validate
 * @return bool True if URL is safe, false otherwise
 */
function is_safe_url($url)
{
    // Parse URL
    $parsed = parse_url($url);

    if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
        return false;
    }

    // Only allow HTTP and HTTPS
    $scheme = strtolower($parsed['scheme']);
    if (!in_array($scheme, ['http', 'https'])) {
        return false;
    }

    // Get host
    $host = strtolower($parsed['host']);

    // Block localhost variations
    $localhost_patterns = [
        'localhost',
        '127.0.0.1',
        '0.0.0.0',
        '::1',
        '0:0:0:0:0:0:0:1',
    ];

    if (in_array($host, $localhost_patterns)) {
        return false;
    }

    // Block localhost subdomains
    if (preg_match('/localhost$/i', $host)) {
        return false;
    }

    // Resolve hostname to IP
    $ip = gethostbyname($host);

    // If gethostbyname fails, it returns the hostname unchanged
    // In that case, allow it (might be a valid domain that's currently unreachable)
    if ($ip === $host) {
        // Couldn't resolve - check if it looks like an IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            // It's a hostname we can't resolve - allow it
            // (might be temporarily down, or DNS issue)
            return true;
        }
    }

    // Validate IP is not private/reserved
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false;
    }

    return true;
}

/**
 * Rate limiting for login attempts
 * Tracks failed login attempts per IP address
 *
 * @param string $identifier Usually IP address
 * @param int $maxAttempts Maximum attempts allowed
 * @param int $windowSeconds Time window in seconds
 * @return bool True if rate limit exceeded, false otherwise
 */
function is_rate_limited($identifier, $maxAttempts = 5, $windowSeconds = 300)
{
    // Use session to track attempts (simple approach for single-user app)
    // For multi-user, you'd want to use database or Redis

    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }

    $now = time();
    $key = 'login_' . $identifier;

    // Initialize or get existing attempts
    if (!isset($_SESSION['rate_limit'][$key])) {
        $_SESSION['rate_limit'][$key] = [
            'attempts' => 0,
            'first_attempt' => $now,
            'locked_until' => 0
        ];
    }

    $data = &$_SESSION['rate_limit'][$key];

    // Check if currently locked out
    if ($data['locked_until'] > $now) {
        return true; // Still locked
    }

    // Reset if window has passed
    if ($now - $data['first_attempt'] > $windowSeconds) {
        $data['attempts'] = 0;
        $data['first_attempt'] = $now;
        $data['locked_until'] = 0;
    }

    // Check if limit exceeded
    if ($data['attempts'] >= $maxAttempts) {
        // Lock for the remaining window time
        $data['locked_until'] = $data['first_attempt'] + $windowSeconds;
        return true;
    }

    return false;
}

/**
 * Record a failed login attempt
 *
 * @param string $identifier Usually IP address
 */
function record_failed_login($identifier)
{
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }

    $key = 'login_' . $identifier;
    $now = time();

    if (!isset($_SESSION['rate_limit'][$key])) {
        $_SESSION['rate_limit'][$key] = [
            'attempts' => 0,
            'first_attempt' => $now,
            'locked_until' => 0
        ];
    }

    $_SESSION['rate_limit'][$key]['attempts']++;
}

/**
 * Reset rate limiting for an identifier (e.g., after successful login)
 *
 * @param string $identifier Usually IP address
 */
function reset_rate_limit($identifier)
{
    if (isset($_SESSION['rate_limit'])) {
        $key = 'login_' . $identifier;
        unset($_SESSION['rate_limit'][$key]);
    }
}

/**
 * Get remaining lockout time in seconds
 *
 * @param string $identifier Usually IP address
 * @return int Seconds remaining in lockout, 0 if not locked
 */
function get_lockout_time($identifier)
{
    if (!isset($_SESSION['rate_limit'])) {
        return 0;
    }

    $key = 'login_' . $identifier;

    if (!isset($_SESSION['rate_limit'][$key])) {
        return 0;
    }

    $data = $_SESSION['rate_limit'][$key];
    $now = time();

    if ($data['locked_until'] > $now) {
        return $data['locked_until'] - $now;
    }

    return 0;
}

/**
 * Enforce per-IP rate limiting for anonymous page requests.
 * Sends HTTP 429 and exits if the caller has exceeded the threshold.
 * Logged-in users are never rate-limited — call this only when !$isLoggedIn.
 *
 * Thresholds:
 *   - 20 requests per minute
 *   - 150 requests per hour
 *
 * @param PDO $db Active database connection
 * @return void
 */
function enforce_page_rate_limit(PDO $db): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $now = time();

    // Ensure the table exists (handles databases created before this feature was added)
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                ip TEXT NOT NULL,
                window_type TEXT NOT NULL,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (ip, window_type, window_start)
            )
        ");
    } catch (PDOException $e) {
        return; // Can't create table — skip rate limiting rather than crash
    }

    // Fixed-window buckets
    $minWindow  = (int)($now / 60)   * 60;
    $hourWindow = (int)($now / 3600) * 3600;

    $limitPerMinute = 20;
    $limitPerHour   = 150;

    try {
        // Upsert both counters atomically
        $upsert = $db->prepare("
            INSERT INTO rate_limits (ip, window_type, window_start, hits)
            VALUES (:ip, :type, :window, 1)
            ON CONFLICT(ip, window_type, window_start) DO UPDATE SET hits = hits + 1
        ");

        $upsert->execute([':ip' => $ip, ':type' => 'min',  ':window' => $minWindow]);
        $upsert->execute([':ip' => $ip, ':type' => 'hour', ':window' => $hourWindow]);

        // Read current counts
        $fetch = $db->prepare("
            SELECT hits FROM rate_limits
            WHERE ip = :ip AND window_type = :type AND window_start = :window
        ");

        $fetch->execute([':ip' => $ip, ':type' => 'min', ':window' => $minWindow]);
        $minHits = (int)($fetch->fetchColumn() ?: 0);

        $fetch->execute([':ip' => $ip, ':type' => 'hour', ':window' => $hourWindow]);
        $hourHits = (int)($fetch->fetchColumn() ?: 0);

        // Prune stale rows ~2% of requests (keep table from growing indefinitely)
        if (mt_rand(1, 50) === 1) {
            $cutoff = $now - 7200;
            $db->prepare("DELETE FROM rate_limits WHERE window_start < :cutoff")
               ->execute([':cutoff' => $cutoff]);
        }
    } catch (PDOException $e) {
        return; // DB error — skip rate limiting rather than crash
    }

    if ($minHits <= $limitPerMinute && $hourHits <= $limitPerHour) {
        return; // Under limit — carry on
    }

    $retryAfter = ($minHits > $limitPerMinute)
        ? (60   - ($now % 60))
        : (3600 - ($now % 3600));

    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>429 — Too Many Requests</title>
  <style>
    body { font-family: monospace; max-width: 680px; margin: 4rem auto; padding: 0 1.5rem;
           color: #222; line-height: 1.7; background: #fafafa; }
    h1   { color: #c0392b; font-size: 1.4rem; margin-bottom: 0.25rem; }
    h2   { font-size: 1rem; font-weight: normal; color: #555; margin-top: 0; }
    .box { background: #fff8e1; border-left: 4px solid #e6a817;
           padding: 1rem 1.25rem; margin: 1.75rem 0; border-radius: 0 4px 4px 0; }
    .box strong { display: block; margin-bottom: 0.5rem; }
    ul   { margin: 0.25rem 0 0 0; padding-left: 1.4rem; }
    li   { margin: 0.35rem 0; }
    code { background: #f0f0f0; padding: 0.1em 0.35em; border-radius: 3px; font-size: 0.92em; }
    .retry { color: #666; font-size: 0.9em; margin-top: 2rem; }
    .human { color: #888; font-size: 0.85em; }
  </style>
</head>
<body>
  <h1>HTTP 429 — Too Many Requests</h1>
  <h2>Your software has been temporarily blocked for sending too many requests.</h2>

  <div class="box">
    <strong>To the developer responsible for this bot:</strong>
    <ul>
      <li>This is a <em>personal bookmarks page</em>. The data here has no commercial value
          whatsoever. Whatever you are trying to accomplish, it is not worth this.</li>
      <li>A <code>robots.txt</code> file specifying <code>Crawl-delay: 10</code> has been
          served alongside every single response. Your software ignored it entirely.</li>
      <li>Responsible crawlers implement exponential backoff, respect
          <code>Retry-After</code> headers, and do not hammer small personal sites into
          the ground. Yours does none of these things.</li>
      <li>This is not a skill issue. This is a values issue. Please reconsider your
          approach to other people\'s infrastructure.</li>
    </ul>
  </div>

  <p class="retry">
    Retry after <strong>' . $retryAfter . ' seconds</strong>. The counter resets automatically.
  </p>
  <p class="human">
    If you are a human who somehow triggered this, please wait a moment and reload.
  </p>
</body>
</html>';

    exit;
}

/**
 * Fetch URL contents safely with SSRF protection
 *
 * @param string $url The URL to fetch
 * @param int $timeout Timeout in seconds
 * @return array ['success' => bool, 'content' => string, 'error' => string]
 */
function safe_fetch_url($url, $timeout = 10, $maxRedirects = 5)
{
    if ($maxRedirects < 0) {
        return [
            'success' => false,
            'content' => '',
            'error' => 'Too many redirects'
        ];
    }

    // Validate URL against SSRF
    if (!is_safe_url($url)) {
        return [
            'success' => false,
            'content' => '',
            'error' => 'URL not allowed: Private/internal addresses are blocked for security'
        ];
    }

    // Create context with timeout and user agent
    // IMPORTANT: Disable automatic redirect following to prevent SSRF via redirection
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'user_agent' => 'Mozilla/5.0 (compatible; BookmarksApp/1.0)',
            'follow_location' => false, // We will handle redirects manually
            'ignore_errors' => true // To read headers even on error codes
        ]
    ]);

    // Fetch headers first (or content + headers)
    // file_get_contents populates $http_response_header variable in the local scope
    $content = @file_get_contents($url, false, $context);

    if ($content === false) {
        return [
            'success' => false,
            'content' => '',
            'error' => 'Failed to fetch URL'
        ];
    }

    // Check for redirects
    if (isset($http_response_header)) {
        // Parse status line
        if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $http_response_header[0], $matches)) {
            $statusCode = intval($matches[1]);

            if ($statusCode >= 300 && $statusCode < 400) {
                // Find location header
                $redirectUrl = null;
                foreach ($http_response_header as $header) {
                    if (stripos($header, 'Location:') === 0) {
                        $redirectUrl = trim(substr($header, 9));
                        break;
                    }
                }

                if ($redirectUrl) {
                    // Handle relative URLs
                    $parts = parse_url($url);
                    if (strpos($redirectUrl, '//') === 0) {
                        $redirectUrl = $parts['scheme'] . ':' . $redirectUrl;
                    } elseif (strpos($redirectUrl, '/') === 0) {
                        $redirectUrl = $parts['scheme'] . '://' . $parts['host'] . $redirectUrl;
                    } elseif (!preg_match('#^https?://#i', $redirectUrl)) {
                        // Relative path
                        $path = $parts['path'] ?? '/';
                        $path = substr($path, 0, strrpos($path, '/') + 1);
                        $redirectUrl = $parts['scheme'] . '://' . $parts['host'] . $path . $redirectUrl;
                    }

                    // Recursively fetch the redirect
                    return safe_fetch_url($redirectUrl, $timeout, $maxRedirects - 1);
                }
            }
        }
    }

    return [
        'success' => true,
        'content' => $content,
        'error' => ''
    ];
}
