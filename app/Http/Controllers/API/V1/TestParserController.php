<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Services\TransactionValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestParserController extends Controller
{
    protected $validationService;

    public function __construct(TransactionValidationService $validationService)
    {
        $this->validationService = $validationService;
        // Middleware registration moved to route definition to avoid IDE error.
    }

    public function testParser(Request $request)
    {
        try {
            $content = $request->getContent();
            
            if (empty($content)) {
                throw new \Exception('Empty content received');
            }

            Log::info('Parser test initiated', [
                'content_type' => $request->header('Content-Type'),
                'content_length' => strlen($content),
                'sample_content' => substr($content, 0, 100)
            ]);

            $parsed = $this->validationService->parseTextFormat($content);
            if (empty($parsed)) {
                throw new \Exception('Parser returned empty result');
            }

            $validated = $this->validationService->validate($parsed);
            
            return response()->json([
                'success' => true,
                'message' => 'Parser test completed',
                'data' => [
                    'parsed' => $parsed,
                    'validation' => $validated,
                    'field_count' => count($parsed)
                ]
            ], 200, [], JSON_PRETTY_PRINT);
            
        } catch (\Exception $e) {
            Log::error('Parser test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Parser test failed',
                'error' => $e->getMessage()
            ], 400, [], JSON_PRETTY_PRINT);
        }
    }
}