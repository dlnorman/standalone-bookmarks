# CSRF Protection Implementation

## Summary

CSRF (Cross-Site Request Forgery) protection has been implemented across the entire application to prevent unauthorized state-changing operations.

## Changes Made

### 1. New CSRF Helper Library
**File**: `includes/csrf.php`

Created a new helper library with the following functions:
- `csrf_generate_token()` - Generates a new CSRF token (refreshes every hour)
- `csrf_get_token()` - Gets the current CSRF token
- `csrf_validate_token($token)` - Validates a CSRF token (uses timing-attack-safe comparison)
- `csrf_field()` - Outputs a hidden form field with the CSRF token
- `csrf_require_valid_token()` - Validates token and dies if invalid (for form submissions)
- `csrf_token_json()` - Returns token as JSON (for JavaScript)

**Features**:
- Tokens expire after 1 hour for security
- Uses `hash_equals()` to prevent timing attacks
- Generates cryptographically secure tokens using `random_bytes()`
- Supports both regular forms and AJAX requests

### 2. Updated Files

#### login.php
- Added CSRF token to login form
- Validates CSRF token before processing login
- Protected against login CSRF attacks

#### api.php
- Added CSRF validation for all state-changing POST requests
- Read-only endpoints (get, list, fetch_meta, get_tags, dashboard_stats) don't require CSRF tokens
- Write operations (add, edit, delete) require valid CSRF tokens
- Returns JSON error for invalid tokens

#### bookmarklet.php
- Added CSRF token to bookmark submission form
- Validates CSRF token before saving bookmarks
- Protected both new bookmark creation and updates

#### import.php
- Added CSRF token to file upload form
- Validates CSRF token before processing imports
- Protected against malicious import attacks

#### index.php
- Added CSRF token constant to JavaScript
- Updated all AJAX calls (add, edit, delete) to include CSRF token
- Token is included in FormData for all POST requests

## How It Works

### Server-Side (Forms)
```php
// In the form
<form method="post">
    <?php csrf_field(); ?>
    <!-- other form fields -->
</form>

// Processing the form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_token();
    // ... process form
}
```

### Client-Side (JavaScript/AJAX)
```javascript
// Token is available in JavaScript
const CSRF_TOKEN = '...'; // Set in page

// Include in FormData
const formData = new FormData();
formData.append('csrf_token', CSRF_TOKEN);
formData.append('action', 'add');
// ... other fields

fetch('/api.php', {
    method: 'POST',
    body: formData
});
```

## Security Benefits

1. **Prevents Cross-Site Request Forgery**: Attackers cannot craft malicious pages that submit forms on behalf of authenticated users
2. **Token Expiration**: Tokens expire after 1 hour, limiting the window of opportunity
3. **Timing Attack Protection**: Uses `hash_equals()` instead of `===` to prevent timing-based attacks
4. **Cryptographically Secure**: Uses `random_bytes()` for unpredictable tokens
5. **Session-Based**: Tokens are tied to the user's session

## What's Protected

- ✅ Login form
- ✅ Bookmark add/edit/delete operations (API)
- ✅ Bookmarklet submissions
- ✅ Import functionality
- ✅ All state-changing operations

## What's NOT Protected (Read-Only)

These endpoints don't need CSRF protection as they don't change state:
- RSS feed (rss.php)
- Archive view (archive.php)
- Gallery view (gallery.php)
- Dashboard stats (dashboard.php)
- Tag listing (tags.php)
- Recent bookmarks (recent.php)
- API GET operations (fetch_meta, get_tags, etc.)

## Testing

After implementation, test the following scenarios:

1. **Normal Operations** (should work):
   - Login with valid credentials
   - Add a bookmark via the form
   - Edit a bookmark
   - Delete a bookmark
   - Use the bookmarklet
   - Import bookmarks

2. **CSRF Attack Simulation** (should fail):
   - Create an external HTML page with a form that POSTs to your API
   - Try to submit without a valid CSRF token
   - Should receive a 403 error with "Invalid CSRF token" message

3. **Token Expiration**:
   - Wait 1 hour with a form open
   - Try to submit - should fail with token expiration error
   - Refresh the page and try again - should work

## Troubleshooting

### "Invalid CSRF token" errors

1. **Check session configuration**: Ensure sessions are working properly
2. **Check token in form**: View page source and verify the hidden csrf_token field exists
3. **Check JavaScript**: Ensure CSRF_TOKEN constant is defined in JavaScript
4. **Clear browser cache**: Old cached pages might not have the token
5. **Check server time**: If server time is wrong, token expiration might behave unexpectedly

### JavaScript errors

If AJAX requests fail with CSRF errors:
1. Check browser console for JavaScript errors
2. Verify CSRF_TOKEN is defined: `console.log(CSRF_TOKEN)`
3. Verify token is being sent: Check Network tab in browser dev tools

## Future Enhancements

Consider these additional security measures:

1. **Double-Submit Cookie**: Also send token as a cookie for additional verification
2. **Per-Request Tokens**: Generate unique token for each form (more secure but less user-friendly)
3. **SameSite Cookie Attribute**: Already implemented in auth.php with 'Lax' mode
4. **Rate Limiting**: Implement rate limiting on failed CSRF attempts

## Compatibility

- Requires PHP 7.0+ (for `random_bytes()`)
- Works with all modern browsers
- AJAX operations require JavaScript enabled
- No changes needed for RSS readers or API clients (read-only operations)
