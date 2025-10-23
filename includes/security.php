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
function validate_redirect_url($url, $basePath, $defaultUrl) {
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
function is_safe_url($url) {
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
function is_rate_limited($identifier, $maxAttempts = 5, $windowSeconds = 300) {
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
function record_failed_login($identifier) {
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
function reset_rate_limit($identifier) {
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
function get_lockout_time($identifier) {
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
 * Fetch URL contents safely with SSRF protection
 *
 * @param string $url The URL to fetch
 * @param int $timeout Timeout in seconds
 * @return array ['success' => bool, 'content' => string, 'error' => string]
 */
function safe_fetch_url($url, $timeout = 10) {
    // Validate URL against SSRF
    if (!is_safe_url($url)) {
        return [
            'success' => false,
            'content' => '',
            'error' => 'URL not allowed: Private/internal addresses are blocked for security'
        ];
    }

    // Create context with timeout and user agent
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'user_agent' => 'Mozilla/5.0 (compatible; BookmarksApp/1.0)',
            'follow_location' => true,
            'max_redirects' => 5,
        ]
    ]);

    // Fetch content
    $content = @file_get_contents($url, false, $context);

    if ($content === false) {
        return [
            'success' => false,
            'content' => '',
            'error' => 'Failed to fetch URL'
        ];
    }

    return [
        'success' => true,
        'content' => $content,
        'error' => ''
    ];
}
