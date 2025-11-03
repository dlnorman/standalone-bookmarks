#!/usr/bin/env php
<?php
/**
 * Test Google PageSpeed Insights API for Screenshots
 *
 * Usage:
 *   php test-pagespeed-api.php "https://example.com"
 *   php test-pagespeed-api.php "https://example.com" YOUR_API_KEY
 */

function getScreenshotFromPageSpeed($url, $apiKey = null)
{
    echo "Testing PageSpeed Insights API...\n";
    echo "URL: $url\n";
    echo "API Key: " . ($apiKey ? "provided" : "none (rate limited)") . "\n\n";

    // Build API URL
    $apiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    $params = [
        'url' => $url,
        'category' => 'performance', // Faster response
        'strategy' => 'desktop', // Desktop viewport (wider screenshots)
    ];

    if ($apiKey) {
        $params['key'] = $apiKey;
    }

    $apiUrl .= '?' . http_build_query($params);

    echo "Calling API...\n";
    $startTime = microtime(true);

    // Make API request
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'user_agent' => 'PHP Screenshot Test',
        ]
    ]);

    $response = @file_get_contents($apiUrl, false, $context);

    $duration = round(microtime(true) - $startTime, 2);
    echo "Response received in {$duration}s\n\n";

    if ($response === false) {
        $error = error_get_last();
        echo "ERROR: Failed to call API\n";
        echo "Error: " . ($error['message'] ?? 'Unknown error') . "\n";
        return false;
    }

    // Parse JSON
    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "ERROR: Invalid JSON response\n";
        echo "JSON Error: " . json_last_error_msg() . "\n";
        return false;
    }

    // Check for API errors
    if (isset($data['error'])) {
        echo "ERROR: API returned an error\n";
        echo "Code: " . ($data['error']['code'] ?? 'unknown') . "\n";
        echo "Message: " . ($data['error']['message'] ?? 'unknown') . "\n";
        return false;
    }

    echo "✓ API call successful\n\n";

    // Navigate to screenshot data
    if (!isset($data['lighthouseResult']['audits']['final-screenshot']['details']['data'])) {
        echo "ERROR: Screenshot data not found in response\n";
        echo "Response keys: " . implode(', ', array_keys($data)) . "\n";

        // Try to show what's available
        if (isset($data['lighthouseResult']['audits'])) {
            echo "Available audits: " . implode(', ', array_keys($data['lighthouseResult']['audits'])) . "\n";
        }

        return false;
    }

    $screenshotData = $data['lighthouseResult']['audits']['final-screenshot']['details']['data'];

    echo "Checking base64 format...\n";
    echo "First 50 chars: " . substr($screenshotData, 0, 50) . "...\n";

    // Check if data has data URI prefix (data:image/jpeg;base64,...)
    if (strpos($screenshotData, 'data:') === 0) {
        echo "Data URI prefix detected, stripping...\n";
        // Strip the data URI prefix
        $parts = explode(',', $screenshotData, 2);
        if (count($parts) === 2) {
            $screenshotData = $parts[1];
        }
    }

    // Google's base64 encoding uses URL-safe characters
    // Need to convert: _ to / and - to +
    $screenshotData = str_replace(['_', '-'], ['/', '+'], $screenshotData);

    // Decode base64 (strict mode)
    $imageData = base64_decode($screenshotData, true);

    if ($imageData === false || strlen($imageData) === 0) {
        echo "ERROR: Failed to decode base64 data\n";
        return false;
    }

    // Verify PNG header
    $header = bin2hex(substr($imageData, 0, 8));
    echo "Image header (hex): $header\n";
    if ($header !== '89504e470d0a1a0a') {
        echo "WARNING: Not a valid PNG header (expected: 89504e470d0a1a0a)\n";
    }

    $imageSize = strlen($imageData);
    echo "✓ Screenshot decoded successfully\n";
    echo "Size: " . formatBytes($imageSize) . "\n";

    // Detect image type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($imageData);
    echo "Type: $mimeType\n\n";

    // Save to file
    $outputFile = 'test-screenshot-' . time() . '.png';
    if (file_put_contents($outputFile, $imageData) === false) {
        echo "ERROR: Failed to save screenshot\n";
        return false;
    }

    echo "✓ Screenshot saved: $outputFile\n";

    // Display image info
    $imageInfo = @getimagesizefromstring($imageData);
    if ($imageInfo) {
        echo "Dimensions: {$imageInfo[0]}x{$imageInfo[1]}px\n";
        echo "✓ Image is valid and can be read by PHP\n";
    } else {
        echo "ERROR: Image data is corrupt - getimagesizefromstring() failed\n";
        return false;
    }

    echo "\n=== Test Successful! ===\n";
    return [
        'data' => $imageData,
        'file' => $outputFile,
        'size' => $imageSize,
        'mime' => $mimeType,
    ];
}

function formatBytes($bytes)
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}

// Command line execution
if (php_sapi_name() === 'cli') {
    $url = $argv[1] ?? null;
    $apiKey = $argv[2] ?? null;

    if (!$url) {
        echo "Usage: php test-pagespeed-api.php URL [API_KEY]\n";
        echo "\n";
        echo "Examples:\n";
        echo "  php test-pagespeed-api.php \"https://example.com\"\n";
        echo "  php test-pagespeed-api.php \"https://example.com\" YOUR_API_KEY\n";
        echo "\n";
        echo "Note: API key is optional but recommended to avoid rate limits\n";
        echo "Get an API key at: https://console.cloud.google.com/apis/credentials\n";
        exit(1);
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        echo "ERROR: Invalid URL\n";
        exit(1);
    }

    $result = getScreenshotFromPageSpeed($url, $apiKey);
    exit($result ? 0 : 1);
}
