<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * IDEMPOTENCY FIX VERIFICATION SCRIPT
 * 
 * Purpose: Test that the dual-idempotency logic fix correctly handles:
 * 1. New submissions → Process normally
 * 2. Duplicate submissions → Return idempotent response (early check)
 * 3. No orphaned TransactionSubmission records are created
 */

echo "🧪 IDEMPOTENCY FIX VERIFICATION\n";
echo "===============================\n\n";

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Http\Controllers\API\V1\TransactionController;
use App\Models\PosTerminal;

try {
    // Get a test terminal
    $terminal = PosTerminal::with('tenant')->first();
    if (!$terminal) {
        echo "❌ No POS terminals found for testing\n";
        exit(1);
    }
    
    echo "🏪 Using Terminal: {$terminal->id} (Tenant: {$terminal->tenant_id})\n\n";
    
    // Test payload
    $testSubmissionUuid = \Illuminate\Support\Str::uuid()->toString();
    $testTransactionId = \Illuminate\Support\Str::uuid()->toString();
    $testTimestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
    
    $payload = [
        'tenant_id' => $terminal->tenant_id,
        'terminal_id' => $terminal->id,
        'submission_uuid' => $testSubmissionUuid,
        'submission_timestamp' => $testTimestamp,
        'transaction_count' => 1,
        'transaction' => [
            'transaction_id' => $testTransactionId,
            'transaction_timestamp' => $testTimestamp,
            'base_amount' => 15.99,
            'payload_checksum' => '0000000000000000000000000000000000000000000000000000000000000000', // Will be calculated
        ]
    ];
    
    // Calculate correct checksums
    $checksumService = new \App\Services\PayloadChecksumService();
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $checksumResult = $checksumService->validateSubmissionChecksumsFromRaw($payloadJson);
    
    if (!$checksumResult['valid']) {
        echo "❌ Unable to create valid test payload\n";
        var_dump($checksumResult['errors']);
        exit(1);
    }
    
    // Update payload with correct checksums
    $payload['payload_checksum'] = $checksumResult['submission_checksum'];
    $payload['transaction']['payload_checksum'] = $checksumResult['transaction_checksums'][0];
    
    echo "📝 Test Payload Created:\n";
    echo "   • Submission UUID: {$testSubmissionUuid}\n";
    echo "   • Transaction ID: {$testTransactionId}\n";
    echo "   • Submission Checksum: " . substr($payload['payload_checksum'], 0, 16) . "...\n\n";
    
    // Record initial state
    $initialSubmissions = DB::table('transaction_submissions')->count();
    $initialTransactions = DB::table('transactions')->count();
    
    echo "📊 Initial Database State:\n";
    echo "   • TransactionSubmissions: {$initialSubmissions}\n";
    echo "   • Transactions: {$initialTransactions}\n\n";
    
    // TEST 1: First submission (should process normally)
    echo "🧪 TEST 1: First Submission (New Processing)\n";
    echo "---------------------------------------------\n";
    
    $controller = new TransactionController();
    $request = new Request();
    $request->replace($payload);
    $request->headers->set('Content-Type', 'application/json');
    $request->setMethod('POST');
    
    // Mock the raw content for checksum validation
    $rawContent = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $request->initialize(
        $payload, // query
        [], // request
        [], // attributes
        [], // cookies
        [], // files
        ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'], // server
        $rawContent // content
    );
    
    DB::beginTransaction();
    $response1 = $controller->storeOfficial($request);
    DB::commit();
    
    $responseData1 = json_decode($response1->getContent(), true);
    echo "   Response: " . $response1->getStatusCode() . "\n";
    echo "   Success: " . ($responseData1['success'] ? '✅' : '❌') . "\n";
    echo "   Message: " . $responseData1['message'] . "\n\n";
    
    // Check database state after first submission
    $afterFirstSubmissions = DB::table('transaction_submissions')->count();
    $afterFirstTransactions = DB::table('transactions')->count();
    
    echo "📊 After First Submission:\n";
    echo "   • TransactionSubmissions: {$afterFirstSubmissions} (+" . ($afterFirstSubmissions - $initialSubmissions) . ")\n";
    echo "   • Transactions: {$afterFirstTransactions} (+" . ($afterFirstTransactions - $initialTransactions) . ")\n\n";
    
    // TEST 2: Duplicate submission (should return idempotent response)
    echo "🧪 TEST 2: Duplicate Submission (Idempotency Check)\n";
    echo "---------------------------------------------------\n";
    
    DB::beginTransaction();
    $response2 = $controller->storeOfficial($request);
    DB::commit();
    
    $responseData2 = json_decode($response2->getContent(), true);
    echo "   Response: " . $response2->getStatusCode() . "\n";
    echo "   Success: " . ($responseData2['success'] ? '✅' : '❌') . "\n";
    echo "   Message: " . $responseData2['message'] . "\n\n";
    
    // Check database state after duplicate submission
    $afterSecondSubmissions = DB::table('transaction_submissions')->count();
    $afterSecondTransactions = DB::table('transactions')->count();
    
    echo "📊 After Duplicate Submission:\n";
    echo "   • TransactionSubmissions: {$afterSecondSubmissions} (+" . ($afterSecondSubmissions - $afterFirstSubmissions) . ")\n";
    echo "   • Transactions: {$afterSecondTransactions} (+" . ($afterSecondTransactions - $afterFirstTransactions) . ")\n\n";
    
    // VALIDATION
    echo "✅ VALIDATION RESULTS:\n";
    echo "======================\n";
    
    $test1Pass = $response1->getStatusCode() == 200 && $responseData1['success'];
    $test2Pass = $response2->getStatusCode() == 200 && $responseData2['success'] && 
                 str_contains(strtolower($responseData2['message']), 'idempotent');
    $noOrphanSubmissions = ($afterSecondSubmissions - $afterFirstSubmissions) == 0;
    $noExtraTransactions = ($afterSecondTransactions - $afterFirstTransactions) == 0;
    
    echo "   • First submission processed: " . ($test1Pass ? '✅ PASS' : '❌ FAIL') . "\n";
    echo "   • Duplicate handled idempotently: " . ($test2Pass ? '✅ PASS' : '❌ FAIL') . "\n";
    echo "   • No orphaned submissions created: " . ($noOrphanSubmissions ? '✅ PASS' : '❌ FAIL') . "\n";
    echo "   • No duplicate transactions created: " . ($noExtraTransactions ? '✅ PASS' : '❌ FAIL') . "\n\n";
    
    $allTestsPass = $test1Pass && $test2Pass && $noOrphanSubmissions && $noExtraTransactions;
    
    if ($allTestsPass) {
        echo "🎉 ALL TESTS PASSED! Idempotency fix is working correctly.\n";
        echo "   The dual-idempotency bug has been resolved.\n\n";
    } else {
        echo "❌ SOME TESTS FAILED. Review the fix implementation.\n\n";
    }
    
    // Cleanup test data
    echo "🧹 Cleaning up test data...\n";
    DB::table('transactions')->where('transaction_id', $testTransactionId)->delete();
    DB::table('transaction_submissions')->where('submission_uuid', $testSubmissionUuid)->delete();
    echo "   ✓ Test data cleaned up\n\n";
    
    echo "🏁 Test completed!\n";
    
} catch (Exception $e) {
    echo "\n❌ Test error: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
