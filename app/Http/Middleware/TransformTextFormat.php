<?php

namespace App\Http\Middleware;

use App\Services\TransactionValidationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TransformTextFormat
{
    /**
     * The transaction validation service instance.
     */
    protected $validationService;

    /**
     * Create a new middleware instance.
     */
    public function __construct(TransactionValidationService $validationService)
    {
        $this->validationService = $validationService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            // Update endpoint matching to include v1 prefix
            if ($request->isMethod('post') && 
                (str_contains($request->path(), 'transactions') || 
                 str_contains($request->path(), 'v1/test-parser'))) {
                
                Log::info('TransformTextFormat middleware processing request', [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'content_type' => $request->header('Content-Type')
                ]);
                
                $contentType = $request->header('Content-Type');
                
                // Log the content type for debugging
                Log::info('Request content type', ['Content-Type' => $contentType]);
                
                // Check if content is text-based (not JSON)
                if ($contentType === 'text/plain' || 
                    $contentType === 'application/x-www-form-urlencoded' || 
                    !$request->isJson()) {
                    
                    // Get raw content
                    $content = $request->getContent();
                    
                    if (!empty($content)) {
                        // Log the raw content for debugging
                        Log::info('Received text content', [
                            'length' => strlen($content),
                            'sample' => substr($content, 0, 100) // Log first 100 chars
                        ]);
                        
                        // Parse the text content
                        $data = $this->validationService->parseTextFormat($content);
                        
                        if (!empty($data)) {
                            // Replace request input with parsed data
                            $request->replace($data);
                            
                            // Add metadata about the format
                            $request->merge([
                                '_data_format' => 'text'
                            ]);
                            
                            // Log success
                            Log::info('Text format transformation successful', [
                                'parsed_fields' => array_keys($data),
                                'field_count' => count($data)
                            ]);
                        } else {
                            Log::warning('Text format parsing returned empty data');
                        }
                    } else {
                        Log::warning('Empty request content received');
                    }
                }
            }

            if ($request->header('Content-Type') === 'text/plain') {
                $content = $request->getContent();
                
                // Transform Key: Value format
                if (strpos($content, ':') !== false) {
                    $data = $this->parseKeyValueFormat($content);
                }
                // Transform Key=Value format
                else if (strpos($content, '=') !== false) {
                    $data = $this->parseKeyEqualFormat($content);
                }
                
                $request->merge($data);
                $request->headers->set('Content-Type', 'application/json');
            }
        } catch (\Exception $e) {
            Log::error('TransformTextFormat middleware error', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
                'content_type' => $request->header('Content-Type')
            ]);
        }
        
        return $next($request);
    }

    private function parseKeyValueFormat(string $content): array
    {
        $lines = explode("\n", trim($content));
        $data = [];

        foreach ($lines as $line) {
            list($key, $value) = explode(':', $line, 2);
            $data[trim($key)] = trim($value);
        }

        return $data;
    }

    private function parseKeyEqualFormat(string $content): array
    {
        $lines = explode("\n", trim($content));
        $data = [];

        foreach ($lines as $line) {
            list($key, $value) = explode('=', $line, 2);
            $data[trim($key)] = trim($value);
        }

        return $data;
    }
}