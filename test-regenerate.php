<?php
/**
 * Test the regenerate screenshot endpoint
 * This helps debug what's being output
 */

echo "Testing regenerate-screenshot.php endpoint...\n\n";

// Simulate a POST request
$_POST['bookmark_id'] = $argv[1] ?? 1;
$_SERVER['REQUEST_METHOD'] = 'POST';

// Capture output
ob_start();
include __DIR__ . '/regenerate-screenshot.php';
$output = ob_get_clean();

echo "=== RAW OUTPUT ===\n";
echo $output;
echo "\n\n";

echo "=== OUTPUT LENGTH ===\n";
echo strlen($output) . " bytes\n\n";

echo "=== FIRST 100 CHARS (hex) ===\n";
echo bin2hex(substr($output, 0, 100)) . "\n\n";

echo "=== FIRST 100 CHARS (visible) ===\n";
echo substr($output, 0, 100) . "\n\n";

// Try to parse as JSON
echo "=== JSON VALIDATION ===\n";
$json = @json_decode($output, true);
if ($json === null) {
    echo "INVALID JSON\n";
    echo "JSON Error: " . json_last_error_msg() . "\n";

    // Check for common issues
    if (substr($output, 0, 5) === '<?php') {
        echo "ERROR: PHP code in output!\n";
    } elseif (strpos($output, 'Warning:') !== false) {
        echo "ERROR: PHP Warning in output!\n";
    } elseif (strpos($output, 'Notice:') !== false) {
        echo "ERROR: PHP Notice in output!\n";
    } elseif (ord($output[0]) === 0xEF && ord($output[1]) === 0xBB && ord($output[2]) === 0xBF) {
        echo "ERROR: BOM (Byte Order Mark) at start of file!\n";
    } else {
        echo "First char: '" . $output[0] . "' (ASCII " . ord($output[0]) . ")\n";
    }
} else {
    echo "VALID JSON\n";
    echo "Decoded: " . print_r($json, true) . "\n";
}
