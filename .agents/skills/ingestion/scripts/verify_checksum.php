<?php
/**
 * TSMS Ingestion Helper: Checksum Verifier (Fallback Logic)
 * 
 * Usage: php tools/verify_checksum.php '<json_payload>'
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

// Mocking some Laravel helpers if needed, or bootstrapping the app
// For a simple script, we can just use the logic from PayloadChecksumService

function calculateV21(array $data, $secret) {
    // Simplified V2.1 logic (String-based)
    ksort($data);
    $string = "";
    foreach ($data as $k => $v) {
        if (is_array($v)) continue;
        $string .= $k . '=' . (string)$v . '&';
    }
    return hash_hmac('sha256', rtrim($string, '&'), $secret);
}

function calculateV20(array $data, $secret) {
    // Simplified V2.0 logic (Float-based)
    ksort($data);
    $string = "";
    foreach ($data as $k => $v) {
        if (is_array($v)) continue;
        $val = is_numeric($v) ? (float)$v : $v;
        $string .= $k . '=' . $val . '&';
    }
    return hash_hmac('sha256', rtrim($string, '&'), $secret);
}

if ($argc < 2) {
    echo "Usage: php tools/verify_checksum.php '<json_payload>'\n";
    exit(1);
}

$payload = json_decode($argv[1], true);
if (!$payload) {
    echo "Error: Invalid JSON payload.\n";
    exit(1);
}

$secret = "base64:".base64_encode("tsms-secret-key"); // Mock secret
$provided = $payload['checksum'] ?? 'MISSING';

$v21 = calculateV21($payload, $secret);
$v20 = calculateV20($payload, $secret);

echo "\n--- TSMS Checksum Audit ---\n";
echo "Provided: " . $provided . "\n";
echo "V2.1 (String): " . $v21 . " " . ($provided === $v21 ? "✅ MATCH" : "❌") . "\n";
echo "V2.0 (Float):  " . $v20 . " " . ($provided === $v20 ? "✅ MATCH" : "❌") . "\n";
echo "---------------------------\n\n";
