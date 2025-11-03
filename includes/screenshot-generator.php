<?php
/**
 * Screenshot Generator using Google PageSpeed Insights API
 *
 * Generates real webpage screenshots using the PageSpeed API
 */

class ScreenshotGenerator
{
    private $apiKey;
    private $config;

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
     * Resize image to specified width, maintaining aspect ratio
     *
     * @param string $imageData Binary image data
     * @param int $maxWidth Maximum width in pixels
     * @return string Resized image data
     */
    private function resizeImage($imageData, $maxWidth = 300)
    {
        // Check if GD is available
        if (!extension_loaded('gd')) {
            return $imageData; // Return original if GD not available
        }

        // Create image from string
        $image = @imagecreatefromstring($imageData);
        if (!$image) {
            return $imageData; // Return original if failed
        }

        // Get dimensions
        $width = imagesx($image);
        $height = imagesy($image);

        // Check if resize is needed
        if ($width <= $maxWidth) {
            imagedestroy($image);
            return $imageData; // No resize needed
        }

        // Calculate new dimensions
        $newWidth = $maxWidth;
        $newHeight = (int)($height * ($maxWidth / $width));

        // Create new image
        $newImage = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);

        // Resize
        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Output to buffer
        ob_start();
        imagepng($newImage, null, 8); // PNG with compression level 8
        $resizedData = ob_get_clean();

        // Clean up
        imagedestroy($image);
        imagedestroy($newImage);

        return $resizedData ?: $imageData;
    }
}
