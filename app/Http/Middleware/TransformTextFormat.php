<?php

namespace App\Http\Middleware;

use App\Services\TransactionValidationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\App;

class TransformTextFormat
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Only process POST requests to the transactions endpoint
        if ($request->isMethod('post') && str_contains($request->path(), 'transactions')) {
            $contentType = $request->header('Content-Type');
            
            // Check if content is text-based (not JSON)
            if ($contentType === 'text/plain' || 
                $contentType === 'application/x-www-form-urlencoded' || 
                !$request->isJson()) {
                
                try {
                    // Get raw content
                    $content = $request->getContent();
                    
                    if (!empty($content)) {
                        // Get the validation service from the container to avoid the facade issue
                        $validationService = App::make(TransactionValidationService::class);
                        
                        // Parse text format into structured data
                        $data = $validationService->parseTextFormat($content);
                        
                        if (!empty($data)) {
                            // Replace request input with parsed data
                            $request->replace($data);
                            
                            // Add metadata about the format
                            $request->merge([
                                '_data_format' => 'text'
                            ]);
                            
                            Log::info('Text format transformation successful', [
                                'parsed_fields' => array_keys($data)
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Text format parsing failed', [
                        'error' => $e->getMessage(),
                        'content' => substr($content ?? '', 0, 100) . '...'
                    ]);
                }
            }
        }
        
        return $next($request);
    }
}