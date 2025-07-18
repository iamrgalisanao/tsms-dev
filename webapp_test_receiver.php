<?php
// Simple WebApp receiver for testing TSMS integration
// Save as: webapp_test_receiver.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST requests to /api/transactions/bulk
$requestUri = $_SERVER['REQUEST_URI'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'error_code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Only POST method allowed'
    ]);
    exit;
}

// Check if this is the correct endpoint
if (!str_contains($requestUri, '/api/transactions/bulk')) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'error_code' => 'ENDPOINT_NOT_FOUND',
        'message' => 'Endpoint not found. Use /api/transactions/bulk'
    ]);
    exit;
}

// Check authorization
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

// For testing, we'll accept the default token
$expectedToken = 'Bearer test_bearer_token_12345';
if ($authHeader !== $expectedToken) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'error_code' => 'UNAUTHORIZED',
        'message' => 'Invalid or missing Bearer token',
        'expected' => 'Bearer test_bearer_token_12345',
        'received' => $authHeader
    ]);
    exit;
}

// Get and decode request body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error_code' => 'INVALID_JSON',
        'message' => 'Invalid JSON payload: ' . json_last_error_msg()
    ]);
    exit;
}

// Log received data
$logFile = 'tsms_test_received_' . date('Y-m-d') . '.log';
$timestamp = date('Y-m-d H:i:s');
$logEntry = [
    'timestamp' => $timestamp,
    'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'batch_id' => $data['batch_id'] ?? 'unknown',
    'transaction_count' => $data['transaction_count'] ?? 0,
    'payload_size_bytes' => strlen($input),
    'received_data' => $data
];

file_put_contents($logFile, "=== TSMS Integration Test - {$timestamp} ===\n");
file_put_contents($logFile, json_encode($logEntry, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

// Validate required fields
$requiredFields = ['source', 'batch_id', 'timestamp', 'transaction_count', 'transactions'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'error_code' => 'MISSING_FIELD',
            'message' => "Missing required field: {$field}",
            'batch_id' => $data['batch_id'] ?? null
        ]);
        exit;
    }
}

// Validate source
if ($data['source'] !== 'TSMS') {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error_code' => 'INVALID_SOURCE',
        'message' => 'Invalid source, expected TSMS',
        'batch_id' => $data['batch_id']
    ]);
    exit;
}

// Validate transactions array
if (!is_array($data['transactions'])) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error_code' => 'INVALID_TRANSACTIONS',
        'message' => 'Transactions must be an array',
        'batch_id' => $data['batch_id']
    ]);
    exit;
}

// Validate each transaction has required fields
$requiredTxFields = ['tsms_id', 'transaction_id', 'amount', 'validation_status', 'checksum', 'submission_uuid'];
$processedCount = 0;
$validationErrors = [];

foreach ($data['transactions'] as $index => $transaction) {
    foreach ($requiredTxFields as $field) {
        if (!isset($transaction[$field])) {
            $validationErrors[] = "Transaction {$index}: Missing required field {$field}";
        }
    }
    
    // Additional validation
    if (isset($transaction['amount']) && (!is_numeric($transaction['amount']) || $transaction['amount'] < 0)) {
        $validationErrors[] = "Transaction {$index}: Invalid amount value";
    }
    
    if (isset($transaction['validation_status']) && $transaction['validation_status'] !== 'VALID') {
        $validationErrors[] = "Transaction {$index}: Expected validation_status to be VALID";
    }
    
    if (empty($validationErrors)) {
        $processedCount++;
    }
}

if (!empty($validationErrors)) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'error_code' => 'VALIDATION_ERRORS',
        'message' => 'Transaction validation failed',
        'batch_id' => $data['batch_id'],
        'errors' => $validationErrors
    ]);
    exit;
}

// Simulate successful processing
$processingStartTime = microtime(true);

// Log success details
echo "Processing TSMS batch: {$data['batch_id']} with {$processedCount} transactions\n";

$processingTime = round((microtime(true) - $processingStartTime) * 1000, 2);

// Return success response matching the integration guide
http_response_code(200);
$response = [
    'status' => 'success',
    'received_count' => $processedCount,
    'batch_id' => $data['batch_id'],
    'processed_at' => date('Y-m-d\TH:i:s.v\Z'),
    'processing_time_ms' => $processingTime,
    'message' => "Successfully processed {$processedCount} transactions",
    'test_mode' => true
];

echo json_encode($response, JSON_PRETTY_PRINT);

// Log success to console
error_log("TSMS Test Integration: Processed batch {$data['batch_id']} with {$processedCount} transactions");
?>
