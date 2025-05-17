<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TransactionValidationService;
use Illuminate\Support\Facades\Log;

class TransformTextFormat
{
    protected $validator;
    
    public function __construct(TransactionValidationService $validator)
    {
        $this->validator = $validator;
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
        // Only process requests with text/plain content type
        if ($request->header('Content-Type') === 'text/plain' && $request->isMethod('post')) {
            try {
                Log::info('Processing text/plain request', [
                    'content_length' => strlen($request->getContent()),
                    'path' => $request->path()
                ]);
                
                // Parse the text content
                $parsedData = $this->validator->parseTextFormat($request->getContent());
                
                // Replace the request content with the parsed JSON data
                $request->replace($parsedData);
                
                // Update request to indicate it's now JSON
                $request->headers->set('Content-Type', 'application/json');
                
                Log::info('Text content successfully parsed', [
                    'fields' => array_keys($parsedData)
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to parse text format', [
                    'error' => $e->getMessage(),
                    'content' => substr($request->getContent(), 0, 200) . '...' // Log first 200 chars
                ]);
                
                // Continue with the original content - the controller will handle validation failures
            }
        }
        
        return $next($request);
    }
}