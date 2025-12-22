<?php
/**
 * CSRF Token Management
 * Provides functions to generate and validate CSRF tokens
 */

/**
 * Generate a CSRF token and store it in the session
 *
 * @return string The generated CSRF token
 */
function csrf_generate_token()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    if (
        !isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) ||
        (time() - $_SESSION['csrf_token_time']) > 3600
    ) {
        // Generate new token if it doesn't exist or is older than 1 hour
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Get the current CSRF token (generates if not exists)
 *
 * @return string The CSRF token
 */
function csrf_get_token()
{
    return csrf_generate_token();
}

/**
 * Validate a CSRF token
 *
 * @param string $token The token to validate
 * @return bool True if valid, false otherwise
 */
function csrf_validate_token($token)
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['csrf_token'])) {
        return false;
    }

    // Check if token has expired (1 hour)
    if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time']) > 3600) {
        return false;
    }

    // Use hash_equals to prevent timing attacks
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Output a hidden CSRF token field for forms
 */
function csrf_field()
{
    $token = csrf_get_token();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Validate CSRF token from POST request and die if invalid
 *
 * @param string $error_message Custom error message (optional)
 */
function csrf_require_valid_token($error_message = 'Invalid CSRF token. Please refresh the page and try again.')
{
    $token = $_POST['csrf_token'] ?? '';

    if (!csrf_validate_token($token)) {
        http_response_code(403);
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            // AJAX request
            header('Content-Type: application/json');
            echo json_encode(['error' => $error_message]);
        } else {
            // Regular form submission
            die($error_message);
        }
        exit;
    }
}

/**
 * Get CSRF token as JSON (for JavaScript)
 *
 * @return string JSON with token
 */
function csrf_token_json()
{
    $token = csrf_get_token();
    return json_encode(['csrf_token' => $token]);
}
