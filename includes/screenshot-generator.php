<?php
/**
 * Screenshot Generator using Google PageSpeed Insights API with robust fallbacks
 *
 * Generates real webpage screenshots using the PageSpeed API
 * Fallbacks: OG Image -> Content Images -> Favicon -> Placeholder
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
    const DOWNLOAD_TIMEOUT = 15; // Reduced 15 seconds max for faster failure on fallbacks

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
     * 3. Try content images from page (finding first large one)
     * 4. Try Favicon from Google service
     * 5. Generate text placeholder
     *
     * @param string $url URL to screenshot
     * @param string $strategy 'mobile' or 'desktop' (for PageSpeed API)
     * @param string|null $baseDir Base directory for screenshots
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null, 'method' => string|null]
     */
    public function generateWithFallback($url, $strategy = 'desktop', $baseDir = null)
    {
        $errors = [];

        // Check for specific file extensions that shouldn't be screenshotted
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $fileTypes = [
            'pdf' => ['color' => [200, 50, 50], 'label' => 'PDF Doc'],
            'zip' => ['color' => [200, 150, 50], 'label' => 'Archive'],
            'rar' => ['color' => [200, 150, 50], 'label' => 'Archive'],
            'gz' => ['color' => [200, 150, 50], 'label' => 'Archive'],
            'mp3' => ['color' => [50, 100, 200], 'label' => 'Audio'],
            'mp4' => ['color' => [50, 150, 200], 'label' => 'Video'],
            'doc' => ['color' => [50, 50, 200], 'label' => 'Document'],
            'docx' => ['color' => [50, 50, 200], 'label' => 'Document'],
            'xls' => ['color' => [50, 150, 50], 'label' => 'Spreadsheet'],
            'xlsx' => ['color' => [50, 150, 50], 'label' => 'Spreadsheet'],
        ];

        if (array_key_exists($extension, $fileTypes)) {
            $typeInfo = $fileTypes[$extension];
            $iconResult = $this->generateTypeIcon($url, $typeInfo['label'], $typeInfo['color'], $baseDir);
            if ($iconResult['success']) {
                return array_merge($iconResult, ['method' => 'file-icon']);
            }
        }

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

        // Method 3: Try content images
        // We act on the "First Large" image requirement by trying candidates until one passes the size check
        $contentImagesResult = $this->getContentImages($url);
        if ($contentImagesResult['success'] && !empty($contentImagesResult['urls'])) {
            $attempts = 0;
            foreach ($contentImagesResult['urls'] as $imageUrl) {
                // Limit attempts to prevent timeout
                if (++$attempts > 5)
                    break;

                // Try to download and save (enforcing 200x100 min size)
                $downloadResult = $this->downloadAndSaveImage($imageUrl, $url, $baseDir, 200, 100);
                if ($downloadResult['success']) {
                    return array_merge($downloadResult, ['method' => 'content-image']);
                }
            }
            $errors['content-image'] = 'No suitable content images found (checked ' . $attempts . ' candidates)';
        } else {
            $errors['content-image'] = $contentImagesResult['error'] ?? 'No content images found';
        }

        // Method 4: Try Favicon
        $domain = parse_url($url, PHP_URL_HOST);
        if ($domain) {
            // Use Google's favicon service which is reliable
            // Request 128px size, but allow smaller (min 16x16)
            $faviconUrl = "https://www.google.com/s2/favicons?domain=" . urlencode($domain) . "&sz=128";
            $downloadResult = $this->downloadAndSaveImage($faviconUrl, $url, $baseDir, 16, 16);
            if ($downloadResult['success']) {
                return array_merge($downloadResult, ['method' => 'favicon']);
            }
            $errors['favicon'] = $downloadResult['error'];
        }

        // Method 5: Generate Placeholder (Last Resort)
        // This should always succeed unless permissions are broken
        $placeholderResult = $this->generatePlaceholder($url, $baseDir);
        if ($placeholderResult['success']) {
            return array_merge($placeholderResult, ['method' => 'placeholder']);
        }
        $errors['placeholder'] = $placeholderResult['error'];

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
     * Extract candidate images from page content (excluding navigation)
     * Returns a list of potential image URLs
     *
     * @param string $url URL to fetch and parse
     * @return array ['success' => bool, 'urls' => array, 'error' => string|null]
     */
    public function getContentImages($url)
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
            $candidates = [];
            if (@preg_match_all('/<img\s+[^>]*src=["\'](.*?)["\']/i', $contentHtml, $matches, PREG_SET_ORDER)) {
                $matchCount = 0;
                foreach ($matches as $match) {
                    // Limit to first 10 candidates to save time
                    if (++$matchCount > 10)
                        break;

                    $imageUrl = $match[1];

                    // Skip common junk
                    if (strpos($imageUrl, 'data:') === 0)
                        continue;
                    if (preg_match('/\b(icon|logo|avatar|pixel|1x1|tracking|sprite)\b/i', $imageUrl))
                        continue;
                    if (strpos($imageUrl, '.svg') !== false)
                        continue; // Skip SVGs for now (GD limitations)

                    // Make relative URLs absolute and validate
                    $imageUrl = $this->makeAbsoluteUrl($imageUrl, $url);
                    if ($imageUrl === null)
                        continue;

                    if (!in_array($imageUrl, $candidates)) {
                        $candidates[] = $imageUrl;
                    }
                }
            }

            if (!empty($candidates)) {
                return ['success' => true, 'urls' => $candidates, 'error' => null];
            }

            return ['success' => false, 'urls' => [], 'error' => 'No content images found'];
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
     * @param int $minWidth Minimum width required (default 200)
     * @param int $minHeight Minimum height required (default 100)
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
     */
    public function downloadAndSaveImage($imageUrl, $originalUrl, $baseDir = null, $minWidth = 200, $minHeight = 100)
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
            if ($imageInfo[0] < $minWidth || $imageInfo[1] < $minHeight) {
                return ['success' => false, 'path' => null, 'error' => "Image too small ({$imageInfo[0]}x{$imageInfo[1]}, min {$minWidth}x{$minHeight})"];
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
            set_error_handler(function () { /* Suppress errors */});
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
            $newHeight = (int) ($height * ($maxWidth / $width));

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

    /**
     * Generate a placeholder image with the domain name
     *
     * @param string $url The URL for the bookmark
     * @param string|null $baseDir Base directory
     * @return array
     */
    public function generatePlaceholder($url, $baseDir = null)
    {
        $domain = parse_url($url, PHP_URL_HOST);
        if (!$domain)
            $domain = 'Unknown';
        $domain = str_ireplace('www.', '', $domain);

        // Colors derived from domain name to be consistent but varied
        $hash = md5($domain);
        $r = hexdec(substr($hash, 0, 2));
        $g = hexdec(substr($hash, 2, 2));
        $b = hexdec(substr($hash, 4, 2));

        // Ensure color isn't too light or too dark
        $r = max(50, min(200, $r));
        $g = max(50, min(200, $g));
        $b = max(50, min(200, $b));

        $width = 256;
        $height = 256;

        $image = imagecreatetruecolor($width, $height);
        $bgColor = imagecolorallocate($image, $r, $g, $b);
        imagefilledrectangle($image, 0, 0, $width, $height, $bgColor);

        // Add text
        $textColor = imagecolorallocate($image, 255, 255, 255);

        // Use built-in font 5 (approx 9px width, 15px height)
        $font = 5;
        $charWidth = 9;
        $lineHeight = 20;

        // Split domain into parts for multi-line display
        $parts = explode('.', $domain);

        // Filter out empty parts
        $parts = array_filter($parts);
        if (empty($parts))
            $parts = [$domain];

        // Limit to 3 parts to avoid overflow
        if (count($parts) > 4) {
            // If too many parts, just use the main domain logic
            $parts = [$domain];
        }

        // Calculate total height to center the block
        $totalTextHeight = count($parts) * $lineHeight;
        $startY = ($height - $totalTextHeight) / 2;

        foreach ($parts as $i => $part) {
            // Truncate part if too long for width
            if (strlen($part) * $charWidth > $width - 20) {
                $maxChars = floor(($width - 20) / $charWidth);
                $part = substr($part, 0, $maxChars - 3) . '...';
            }

            $currentTextWidth = strlen($part) * $charWidth;
            $x = ($width - $currentTextWidth) / 2;
            $y = $startY + ($i * $lineHeight);

            imagestring($image, $font, (int) $x, (int) $y, $part, $textColor);
        }

        // Capture output
        ob_start();
        imagepng($image);
        $imageData = ob_get_clean();
        imagedestroy($image);

        return $this->saveScreenshot($url, $imageData, $baseDir);
    }

    /**
     * Generate a specific file type icon
     */
    public function generateTypeIcon($url, $label, $color, $baseDir = null)
    {
        $width = 256;
        $height = 256;

        $image = imagecreatetruecolor($width, $height);

        // Background color
        $bg = imagecolorallocate($image, $color[0], $color[1], $color[2]);
        imagefilledrectangle($image, 0, 0, $width, $height, $bg);

        // Text color
        $textColor = imagecolorallocate($image, 255, 255, 255);

        // Add "FILE" label or extension
        $font = 5;
        $charWidth = 9;

        // Center text
        $textWidth = strlen($label) * $charWidth;
        $x = ($width - $textWidth) / 2;
        $y = ($height - 15) / 2;

        imagestring($image, $font, (int) $x, (int) $y, $label, $textColor);

        // Capture
        ob_start();
        imagepng($image);
        $imageData = ob_get_clean();
        imagedestroy($image);

        return $this->saveScreenshot($url, $imageData, $baseDir);
    }
}
