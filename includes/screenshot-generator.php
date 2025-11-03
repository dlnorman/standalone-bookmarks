<?php
/**
 * Screenshot Generator using Google PageSpeed Insights API
 *
 * Generates real webpage screenshots using the PageSpeed API
 */

// Load security helpers for SSRF protection
require_once __DIR__ . '/security.php';

class ScreenshotGenerator
{
    private $apiKey;
    private $config;

    // Security constants
    const MAX_IMAGE_SIZE = 10485760; // 10MB max download
    const MAX_IMAGE_MEMORY = 52428800; // 50MB max memory for image processing
    const DOWNLOAD_TIMEOUT = 30; // 30 seconds max for downloads

    public function __construct($config)
    {
        $this->config = $config;
        $this->apiKey = $config['pagespeed_api_key'] ?? null;
    }

    /**
     * Generate a screenshot for a URL using PageSpeed Insights API
     *
     * @param string $url The URL to screenshot
     * @param string $strategy 'mobile' or 'desktop' (default: desktop)
     * @return array ['success' => bool, 'data' => binary|null, 'error' => string|null, 'size' => int]
     */
    public function generateScreenshot($url, $strategy = 'desktop')
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'PageSpeed API key not configured',
                'size' => 0
            ];
        }

        // Build API URL
        $apiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
        $params = [
            'url' => $url,
            'key' => $this->apiKey,
            'category' => 'performance', // Faster response, only need screenshot
            'strategy' => $strategy, // mobile or desktop
        ];

        $apiUrl .= '?' . http_build_query($params);

        // Make API request
        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'user_agent' => 'Mozilla/5.0 (compatible; BookmarksApp/1.0)',
            ]
        ]);

        $response = @file_get_contents($apiUrl, false, $context);

        if ($response === false) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Failed to call PageSpeed API',
                'size' => 0
            ];
        }

        // Parse JSON
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Invalid JSON response from API',
                'size' => 0
            ];
        }

        // Check for API errors
        if (isset($data['error'])) {
            $errorMsg = $data['error']['message'] ?? 'Unknown API error';
            return [
                'success' => false,
                'data' => null,
                'error' => 'API error: ' . $errorMsg,
                'size' => 0
            ];
        }

        // Navigate to screenshot data
        if (!isset($data['lighthouseResult']['audits']['final-screenshot']['details']['data'])) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Screenshot data not found in API response',
                'size' => 0
            ];
        }

        $screenshotData = $data['lighthouseResult']['audits']['final-screenshot']['details']['data'];

        // Check if data has data URI prefix (data:image/jpeg;base64,...)
        if (strpos($screenshotData, 'data:') === 0) {
            // Strip the data URI prefix
            $parts = explode(',', $screenshotData, 2);
            if (count($parts) === 2) {
                $screenshotData = $parts[1];
            }
        }

        // Google's base64 encoding uses URL-safe characters
        // Need to convert: _ to / and - to +
        $screenshotData = str_replace(['_', '-'], ['/', '+'], $screenshotData);

        // Decode base64
        $imageData = base64_decode($screenshotData, true);

        if ($imageData === false || strlen($imageData) === 0) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Failed to decode screenshot data',
                'size' => 0
            ];
        }

        // Verify it's a valid image
        $imageInfo = @getimagesizefromstring($imageData);
        if ($imageInfo === false) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Decoded data is not a valid image',
                'size' => 0
            ];
        }

        // Resize to 300px width (configurable)
        $maxWidth = $this->config['screenshot_max_width'] ?? 300;
        $imageData = $this->resizeImage($imageData, $maxWidth);

        return [
            'success' => true,
            'data' => $imageData,
            'error' => null,
            'size' => strlen($imageData)
        ];
    }

    /**
     * Save screenshot to file and return path
     *
     * @param string $url Original URL being screenshotted
     * @param string $imageData Binary image data
     * @param string $baseDir Base directory for screenshots (default: __DIR__ . '/../screenshots')
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
     */
    public function saveScreenshot($url, $imageData, $baseDir = null)
    {
        if ($baseDir === null) {
            $baseDir = __DIR__ . '/../screenshots';
        }

        // Parse domain for directory
        $urlParts = parse_url($url);
        $domain = $urlParts['host'] ?? 'unknown';
        $domain = preg_replace('/[^a-z0-9\-\.]/', '_', strtolower($domain));

        // Remove www. prefix
        $domain = preg_replace('/^www\./', '', $domain);

        // Create domain directory
        $domainDir = $baseDir . '/' . $domain;
        if (!is_dir($domainDir)) {
            if (!mkdir($domainDir, 0755, true)) {
                return [
                    'success' => false,
                    'path' => null,
                    'error' => 'Failed to create directory: ' . $domainDir
                ];
            }
        }

        // Detect image type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData);

        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $extension = $mimeToExt[$mimeType] ?? 'png';

        // Generate filename
        $timestamp = time();
        $hash = substr(md5($url . $timestamp), 0, 8);
        $filename = "{$timestamp}_{$hash}.{$extension}";
        $filePath = $domainDir . '/' . $filename;

        // Save file
        if (file_put_contents($filePath, $imageData) === false) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Failed to write file: ' . $filePath
            ];
        }

        // Return relative path (for database storage)
        $relativePath = 'screenshots/' . $domain . '/' . $filename;

        return [
            'success' => true,
            'path' => $relativePath,
            'error' => null
        ];
    }

    /**
     * Generate and save screenshot in one call
     *
     * @param string $url URL to screenshot
     * @param string $strategy 'mobile' or 'desktop'
     * @param string|null $baseDir Base directory for screenshots
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
     */
    public function generateAndSave($url, $strategy = 'mobile', $baseDir = null)
    {
        // Generate screenshot
        $result = $this->generateScreenshot($url, $strategy);

        if (!$result['success']) {
            return $result;
        }

        // Save screenshot
        return $this->saveScreenshot($url, $result['data'], $baseDir);
    }

    /**
     * Check if API key is configured
     *
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->apiKey);
    }

    /**
     * Generate screenshot with fallback chain:
     * 1. Try PageSpeed API screenshot
     * 2. Try og:image from HTML meta tags
     * 3. Try first content image from page
     *
     * @param string $url URL to screenshot
     * @param string $strategy 'mobile' or 'desktop' (for PageSpeed API)
     * @param string|null $baseDir Base directory for screenshots
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null, 'method' => string|null]
     */
    public function generateWithFallback($url, $strategy = 'desktop', $baseDir = null)
    {
        $errors = [];

        // Method 1: Try PageSpeed API screenshot
        $result = $this->generateAndSave($url, $strategy, $baseDir);
        if ($result['success']) {
            return array_merge($result, ['method' => 'pagespeed']);
        }
        $errors['pagespeed'] = $result['error'];

        // Method 2: Try og:image from HTML
        $ogImageResult = $this->getOgImage($url);
        if ($ogImageResult['success'] && !empty($ogImageResult['url'])) {
            $downloadResult = $this->downloadAndSaveImage($ogImageResult['url'], $url, $baseDir);
            if ($downloadResult['success']) {
                return array_merge($downloadResult, ['method' => 'og:image']);
            }
            $errors['og:image'] = $downloadResult['error'];
        } else {
            $errors['og:image'] = $ogImageResult['error'] ?? 'No og:image found';
        }

        // Method 3: Try first content image
        $contentImageResult = $this->getFirstContentImage($url);
        if ($contentImageResult['success'] && !empty($contentImageResult['url'])) {
            $downloadResult = $this->downloadAndSaveImage($contentImageResult['url'], $url, $baseDir);
            if ($downloadResult['success']) {
                return array_merge($downloadResult, ['method' => 'content-image']);
            }
            $errors['content-image'] = $downloadResult['error'];
        } else {
            $errors['content-image'] = $contentImageResult['error'] ?? 'No content image found';
        }

        // All methods failed
        return [
            'success' => false,
            'path' => null,
            'error' => 'All methods failed: ' . json_encode($errors),
            'method' => null
        ];
    }

    /**
     * Extract og:image URL from HTML meta tags
     *
     * @param string $url URL to fetch and parse
     * @return array ['success' => bool, 'url' => string|null, 'error' => string|null]
     */
    public function getOgImage($url)
    {
        try {
            // Fetch HTML with timeout
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::DOWNLOAD_TIMEOUT,
                    'user_agent' => 'Mozilla/5.0 (compatible; BookmarksApp/1.0)',
                    'follow_location' => true,
                    'max_redirects' => 3,
                ]
            ]);

            $html = @file_get_contents($url, false, $context);
            if ($html === false) {
                return ['success' => false, 'url' => null, 'error' => 'Failed to fetch HTML'];
            }

            // SECURITY: Limit HTML size to prevent memory issues
            if (strlen($html) > 5242880) { // 5MB limit
                $html = substr($html, 0, 5242880);
            }

            // Parse for og:image meta tag (limit search to first 500KB for performance)
            $searchHtml = substr($html, 0, 512000);
            if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\'](.*?)["\']/i', $searchHtml, $matches)) {
                $imageUrl = $matches[1];
            } elseif (preg_match('/<meta\s+content=["\'](.*?)["\']\s+property=["\']og:image["\']/i', $searchHtml, $matches)) {
                $imageUrl = $matches[1];
            } else {
                return ['success' => false, 'url' => null, 'error' => 'No og:image meta tag found'];
            }

            // Make relative URLs absolute and validate
            $imageUrl = $this->makeAbsoluteUrl($imageUrl, $url);
            if ($imageUrl === null) {
                return ['success' => false, 'url' => null, 'error' => 'Invalid og:image URL'];
            }

            return ['success' => true, 'url' => $imageUrl, 'error' => null];
        } catch (Exception $e) {
            return ['success' => false, 'url' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract first image from page content (excluding navigation)
     *
     * @param string $url URL to fetch and parse
     * @return array ['success' => bool, 'url' => string|null, 'error' => string|null]
     */
    public function getFirstContentImage($url)
    {
        try {
            // Fetch HTML with timeout
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::DOWNLOAD_TIMEOUT,
                    'user_agent' => 'Mozilla/5.0 (compatible; BookmarksApp/1.0)',
                    'follow_location' => true,
                    'max_redirects' => 3,
                ]
            ]);

            $html = @file_get_contents($url, false, $context);
            if ($html === false) {
                return ['success' => false, 'url' => null, 'error' => 'Failed to fetch HTML'];
            }

            // SECURITY: Limit HTML size to prevent memory issues
            if (strlen($html) > 5242880) { // 5MB limit
                $html = substr($html, 0, 5242880);
            }

            // SECURITY: Set PCRE limits to prevent ReDoS attacks
            ini_set('pcre.backtrack_limit', '100000');
            ini_set('pcre.recursion_limit', '100000');

            // Remove common navigation/header/footer elements (with error suppression for malformed HTML)
            $html = @preg_replace('/<nav\b[^>]*>.*?<\/nav>/is', '', $html, 50);
            $html = @preg_replace('/<header\b[^>]*>.*?<\/header>/is', '', $html, 50);
            $html = @preg_replace('/<footer\b[^>]*>.*?<\/footer>/is', '', $html, 50);
            $html = @preg_replace('/<aside\b[^>]*>.*?<\/aside>/is', '', $html, 50);

            // Try to find main content area (limit matches)
            $contentHtml = $html;
            if (@preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $matches)) {
                $contentHtml = $matches[1];
            } elseif (@preg_match('/<article\b[^>]*>(.*?)<\/article>/is', $html, $matches)) {
                $contentHtml = $matches[1];
            } elseif (@preg_match('/<div[^>]*(?:class|id)=["\'][^"\']*(?:content|main|post|article)[^"\']*["\'][^>]*>(.*?)<\/div>/is', $html, $matches)) {
                $contentHtml = $matches[1];
            }

            // Limit content size for image search
            if (strlen($contentHtml) > 1048576) { // 1MB limit for content search
                $contentHtml = substr($contentHtml, 0, 1048576);
            }

            // Find all images in content area
            if (@preg_match_all('/<img\s+[^>]*src=["\'](.*?)["\']/i', $contentHtml, $matches, PREG_SET_ORDER)) {
                $matchCount = 0;
                foreach ($matches as $match) {
                    // Limit to first 100 images for performance
                    if (++$matchCount > 100) break;

                    $imageUrl = $match[1];

                    // Skip small images (likely icons/avatars)
                    // Skip data URIs, tracking pixels, etc.
                    if (strpos($imageUrl, 'data:') === 0) continue;
                    if (preg_match('/\b(icon|logo|avatar|pixel|1x1|tracking|sprite)\b/i', $imageUrl)) continue;

                    // Make relative URLs absolute and validate
                    $imageUrl = $this->makeAbsoluteUrl($imageUrl, $url);
                    if ($imageUrl === null) continue;

                    // Return first valid image
                    return ['success' => true, 'url' => $imageUrl, 'error' => null];
                }
            }

            return ['success' => false, 'url' => null, 'error' => 'No content images found'];
        } catch (Exception $e) {
            return ['success' => false, 'url' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Download image from URL and save it
     *
     * @param string $imageUrl URL of the image to download
     * @param string $originalUrl Original page URL (for directory naming)
     * @param string|null $baseDir Base directory for screenshots
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
     */
    public function downloadAndSaveImage($imageUrl, $originalUrl, $baseDir = null)
    {
        try {
            // SECURITY: Validate image URL to prevent SSRF attacks
            if (!is_safe_url($imageUrl)) {
                return [
                    'success' => false,
                    'path' => null,
                    'error' => 'Image URL blocked: Private/internal addresses not allowed'
                ];
            }

            // SECURITY: Set up context with strict limits
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::DOWNLOAD_TIMEOUT,
                    'user_agent' => 'Mozilla/5.0 (compatible; BookmarksApp/1.0)',
                    'follow_location' => true,
                    'max_redirects' => 3,
                ]
            ]);

            // SECURITY: Download with size limit check
            $imageData = '';
            $handle = @fopen($imageUrl, 'rb', false, $context);
            if ($handle === false) {
                return ['success' => false, 'path' => null, 'error' => 'Failed to open image URL'];
            }

            // Read in chunks with size limit
            while (!feof($handle) && strlen($imageData) < self::MAX_IMAGE_SIZE) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    break;
                }
                $imageData .= $chunk;
            }

            $exceededLimit = !feof($handle);
            fclose($handle);

            if ($exceededLimit) {
                return [
                    'success' => false,
                    'path' => null,
                    'error' => 'Image too large (max ' . round(self::MAX_IMAGE_SIZE / 1024 / 1024) . 'MB)'
                ];
            }

            if (strlen($imageData) === 0) {
                return ['success' => false, 'path' => null, 'error' => 'Downloaded image is empty'];
            }

            // SECURITY: Verify it's a valid image (before decompression)
            $imageInfo = @getimagesizefromstring($imageData);
            if ($imageInfo === false) {
                return ['success' => false, 'path' => null, 'error' => 'Downloaded data is not a valid image'];
            }

            // Check minimum dimensions (avoid tiny images)
            if ($imageInfo[0] < 200 || $imageInfo[1] < 100) {
                return ['success' => false, 'path' => null, 'error' => 'Image too small (min 200x100)'];
            }

            // SECURITY: Check for potential decompression bombs
            $estimatedMemory = $imageInfo[0] * $imageInfo[1] * 4; // RGBA = 4 bytes per pixel
            if ($estimatedMemory > self::MAX_IMAGE_MEMORY) {
                return [
                    'success' => false,
                    'path' => null,
                    'error' => 'Image dimensions too large (potential decompression bomb)'
                ];
            }

            // Resize to 300px width (configurable)
            $maxWidth = $this->config['screenshot_max_width'] ?? 300;
            $imageData = $this->resizeImage($imageData, $maxWidth);

            if ($imageData === false) {
                return ['success' => false, 'path' => null, 'error' => 'Failed to process image'];
            }

            // Save the image
            return $this->saveScreenshot($originalUrl, $imageData, $baseDir);
        } catch (Exception $e) {
            return ['success' => false, 'path' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Convert relative URL to absolute URL
     * SECURITY: Validates the constructed URL is properly formed
     *
     * @param string $url URL to convert
     * @param string $baseUrl Base URL for resolution
     * @return string|null Absolute URL or null if invalid
     */
    private function makeAbsoluteUrl($url, $baseUrl)
    {
        // Sanitize input - remove any whitespace and control characters
        $url = trim($url);
        $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url);

        if (empty($url)) {
            return null;
        }

        // Already absolute
        if (preg_match('/^https?:\/\//i', $url)) {
            // Validate it's a proper URL
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                return null;
            }
            return $url;
        }

        $base = parse_url($baseUrl);
        if (!$base || !isset($base['scheme']) || !isset($base['host'])) {
            return null;
        }

        // Protocol-relative URL
        if (strpos($url, '//') === 0) {
            $absoluteUrl = $base['scheme'] . ':' . $url;
        }
        // Absolute path
        elseif (strpos($url, '/') === 0) {
            $absoluteUrl = $base['scheme'] . '://' . $base['host'] . $url;
        }
        // Relative path
        else {
            $path = $base['path'] ?? '/';
            $path = substr($path, 0, strrpos($path, '/') + 1);
            $absoluteUrl = $base['scheme'] . '://' . $base['host'] . $path . $url;
        }

        // Validate the constructed URL
        if (filter_var($absoluteUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $absoluteUrl;
    }

    /**
     * Resize image to specified width, maintaining aspect ratio
     * SECURITY: Sets memory limits to prevent exhaustion attacks
     *
     * @param string $imageData Binary image data
     * @param int $maxWidth Maximum width in pixels
     * @return string|false Resized image data or false on failure
     */
    private function resizeImage($imageData, $maxWidth = 300)
    {
        // Check if GD is available
        if (!extension_loaded('gd')) {
            return $imageData; // Return original if GD not available
        }

        // SECURITY: Set memory limit for this operation
        $oldMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '128M'); // Reasonable limit for image processing

        try {
            // Create image from string with error handling
            set_error_handler(function() { /* Suppress errors */ });
            $image = imagecreatefromstring($imageData);
            restore_error_handler();

            if (!$image) {
                ini_set('memory_limit', $oldMemoryLimit);
                return $imageData; // Return original if failed
            }

            // Get dimensions
            $width = imagesx($image);
            $height = imagesy($image);

            // Check if resize is needed
            if ($width <= $maxWidth) {
                imagedestroy($image);
                ini_set('memory_limit', $oldMemoryLimit);
                return $imageData; // No resize needed
            }

            // Calculate new dimensions
            $newWidth = $maxWidth;
            $newHeight = (int)($height * ($maxWidth / $width));

            // SECURITY: Validate new dimensions
            if ($newHeight > 10000 || $newHeight < 1) {
                imagedestroy($image);
                ini_set('memory_limit', $oldMemoryLimit);
                return false;
            }

            // Create new image
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            if (!$newImage) {
                imagedestroy($image);
                ini_set('memory_limit', $oldMemoryLimit);
                return false;
            }

            // Preserve transparency for PNG
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
            imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);

            // Resize
            $success = imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            if (!$success) {
                imagedestroy($image);
                imagedestroy($newImage);
                ini_set('memory_limit', $oldMemoryLimit);
                return false;
            }

            // Output to buffer
            ob_start();
            $pngSuccess = imagepng($newImage, null, 8); // PNG with compression level 8
            $resizedData = ob_get_clean();

            // Clean up
            imagedestroy($image);
            imagedestroy($newImage);

            // Restore memory limit
            ini_set('memory_limit', $oldMemoryLimit);

            return ($pngSuccess && $resizedData) ? $resizedData : $imageData;

        } catch (Exception $e) {
            // Restore memory limit on error
            ini_set('memory_limit', $oldMemoryLimit);
            return false;
        }
    }
}
