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
    }

    /**
     * Test the text format parser
     */
    public function testParser(Request $request)
    {
        try {
            $content = $request->getContent();
            
            // Log the received content
            Log::info('Test parser received content', [
                'content_type' => $request->header('Content-Type'),
                'content_length' => strlen($content),
                'sample' => substr($content, 0, 100)
            ]);
            
            // Parse the content
            $parsed = $this->validationService->parseTextFormat($content);
            
            // Return the parsed data
            return response()->json([
                'success' => true,
                'original_content' => $content,
                'parsed_data' => $parsed,
                'field_count' => count($parsed)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => explode("\n", $e->getTraceAsString())
            ], 500);
        }
    }
}