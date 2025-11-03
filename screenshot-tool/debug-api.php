#!/usr/bin/env php
<?php
/**
 * Debug PageSpeed API response to see exactly what we're getting
 */

$url = $argv[1] ?? 'https://example.com';
$apiKey = $argv[2] ?? null;

echo "Debugging PageSpeed API for: $url\n\n";

$apiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
$params = [
    'url' => $url,
    'category' => 'performance',
    'strategy' => 'mobile',
];

if ($apiKey) {
    $params['key'] = $apiKey;
}

$apiUrl .= '?' . http_build_query($params);

echo "Calling API...\n";
$response = @file_get_contents($apiUrl);

if ($response === false) {
    die("Failed to call API\n");
}

$data = json_decode($response, true);

if (!isset($data['lighthouseResult']['audits']['final-screenshot']['details']['data'])) {
    die("Screenshot data not found\n");
}

$screenshotData = $data['lighthouseResult']['audits']['final-screenshot']['details']['data'];

echo "Screenshot data preview (first 100 chars):\n";
echo substr($screenshotData, 0, 100) . "...\n\n";

echo "Length: " . strlen($screenshotData) . " characters\n";
echo "First char: " . $screenshotData[0] . "\n";
echo "Starts with 'data:': " . (strpos($screenshotData, 'data:') === 0 ? 'YES' : 'NO') . "\n";
echo "Contains 'base64,': " . (strpos($screenshotData, 'base64,') !== false ? 'YES' : 'NO') . "\n\n";

// Try decoding with URL-safe replacement
$decoded1 = str_replace(['_', '-'], ['/', '+'], $screenshotData);
$imageData1 = base64_decode($decoded1);
echo "Method 1 (URL-safe replacement): " . ($imageData1 !== false ? strlen($imageData1) . " bytes" : "FAILED") . "\n";

// Try decoding without replacement
$imageData2 = base64_decode($screenshotData);
echo "Method 2 (no replacement): " . ($imageData2 !== false ? strlen($imageData2) . " bytes" : "FAILED") . "\n";

// Try stripping data URI prefix if present
if (strpos($screenshotData, 'data:') === 0) {
    $parts = explode(',', $screenshotData, 2);
    if (count($parts) === 2) {
        $base64only = $parts[1];
        $imageData3 = base64_decode($base64only);
        echo "Method 3 (strip data URI): " . ($imageData3 !== false ? strlen($imageData3) . " bytes" : "FAILED") . "\n";

        // Save this one
        if ($imageData3 !== false) {
            file_put_contents('debug-screenshot.png', $imageData3);
            echo "\nSaved as debug-screenshot.png\n";
        }
    }
}

// Check if any worked and verify PNG header
foreach ([$imageData1, $imageData2, isset($imageData3) ? $imageData3 : null] as $i => $img) {
    if ($img !== false && $img !== null) {
        $header = substr($img, 0, 8);
        $hex = bin2hex($header);
        echo "\nMethod " . ($i + 1) . " header (hex): $hex\n";
        echo "Is PNG: " . ($hex === '89504e470d0a1a0a' ? 'YES' : 'NO') . "\n";

        // Try to get image info
        $info = @getimagesizefromstring($img);
        if ($info) {
            echo "Image dimensions: {$info[0]}x{$info[1]}\n";
            echo "MIME type: {$info['mime']}\n";
        } else {
            echo "getimagesizefromstring FAILED - image is corrupt\n";
        }
    }
}
