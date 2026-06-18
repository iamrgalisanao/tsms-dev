<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel application
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\PosTerminal;
use App\Models\SubmissionEvent;

echo "=== CHECKSUM VALIDATION EVENT TEST ===\n\n";

// Get terminal and create token
$terminal = PosTerminal::first();
if (!$terminal) {
    echo "❌ No terminals found\n";
    exit(1);
}

$token = $terminal->createToken('checksum-test');
echo "✅ Using terminal: {$terminal->id}, tenant: {$terminal->tenant_id}\n";
echo "✅ Token created: " . substr($token->plainTextToken, 0, 20) . "...\n\n";

// Count events before test
$eventsBefore = SubmissionEvent::count();
echo "📊 Submission events before: {$eventsBefore}\n";

// Create payload with invalid checksum
$payload = [
    'submission_uuid' => '12345678-1234-5678-9012-123456789012',
    'tenant_id' => $terminal->tenant_id,
    'terminal_id' => $terminal->id,
    'submission_timestamp' => '2025-10-22T20:00:00Z',
    'transaction_count' => 1,
    'payload_checksum' => 'invalid-checksum-that-should-fail-validation-exactly-64-chars',
    'transaction' => [
        'transaction_id' => 'test-checksum-fail-001',
        'transaction_timestamp' => '2025-10-22T20:00:00Z',
        'gross_sales' => 100.00,
        'net_sales' => 88.00,
        'payload_checksum' => '1234567890123456789012345678901234567890123456789012345678901234',
        'adjustments' => [],
        'taxes' => []
    ]
];

echo "🧪 Making API request with invalid checksum...\n";

// Make HTTP request using Guzzle
try {
    $client = new \GuzzleHttp\Client();
    $response = $client->post('http://127.0.0.1:8001/api/v1/transactions/official', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'http_errors' => false
    ]);
    
    $statusCode = $response->getStatusCode();
    $responseBody = json_decode($response->getBody(), true);
    
    echo "📤 Response Status: {$statusCode}\n";
    echo "📄 Response: " . json_encode($responseBody, JSON_PRETTY_PRINT) . "\n\n";
    
    // Check events after test
    $eventsAfter = SubmissionEvent::count();
    echo "📊 Submission events after: {$eventsAfter}\n";
    
    if ($eventsAfter > $eventsBefore) {
        echo "✅ SUCCESS: SubmissionEvent was created for checksum failure!\n\n";
        
        $latestEvent = SubmissionEvent::latest()->first();
        echo "📋 Latest Event Details:\n";
        echo "   • Status: {$latestEvent->status}\n";
        echo "   • Reason Code: {$latestEvent->reason_code}\n";
        echo "   • Submission UUID: {$latestEvent->submission_uuid}\n";
        echo "   • Tenant ID: {$latestEvent->tenant_id}\n";
        echo "   • Terminal ID: {$latestEvent->terminal_id}\n";
        echo "   • Transaction Count: {$latestEvent->transaction_count}\n";
        echo "   • Reason Details: " . json_encode($latestEvent->reason_details) . "\n";
    } else {
        echo "❌ FAILURE: No SubmissionEvent was created for checksum failure\n";
    }
    
} catch (Exception $e) {
    echo "❌ HTTP Request failed: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
