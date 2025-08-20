<?php

// Simple health check test script
$url = 'http://127.0.0.1:8000/api/v1/health';

echo "Testing Health Check Endpoint...\n";
echo "URL: $url\n\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Status Code: $httpCode\n";

if ($error) {
    echo "Error: $error\n";
} else {
    echo "Response:\n";
    echo $response . "\n";
    
    // Parse JSON if possible
    $json = json_decode($response, true);
    if ($json) {
        echo "\nParsed Response:\n";
        print_r($json);
    }
}

echo "\n--- Test Complete ---\n";
