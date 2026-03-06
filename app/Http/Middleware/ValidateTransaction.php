<?php

namespace App\Http\Middleware;

use App\Models\SystemLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ValidateTransaction
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // Validate payload
            $payload = $request->json()->all();
            if (!$payload) {
                // Log invalid JSON payload in a privacy-conscious way
                try {
                    $raw = (string) $request->getContent();
                    $length = strlen($raw);
                    $prefix = $length > 0 ? mb_substr($raw, 0, 512) : '';
                    $checksum = $length > 0 ? hash('sha256', $raw) : null;

                    SystemLog::create([
                        'type' => 'transaction',
                        'log_type' => 'TRANSACTION_PAYLOAD_INVALID',
                        'severity' => 'error',
                        'terminal_uid' => null,
                        'transaction_id' => null,
                        'message' => 'Invalid JSON payload received on transaction endpoint',
                        'context' => [
                            'path' => $request->path(),
                            'client_ip' => $request->ip(),
                            'body_length' => $length,
                            'body_checksum' => $checksum,
                            'body_prefix' => $prefix,
                        ],
                    ]);
                } catch (\Throwable $logEx) {
                    Log::warning('Failed to write SystemLog for TRANSACTION_PAYLOAD_INVALID', [
                        'path' => $request->path(),
                        'error' => $logEx->getMessage(),
                    ]);
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid JSON payload',
                    'timestamp' => now()->toISOString()
                ], 400);
            }

            return $next($request);

        } catch (\Exception $e) {
            // Log unexpected middleware exceptions without exposing full payload
            try {
                SystemLog::create([
                    'type' => 'transaction',
                    'log_type' => 'TRANSACTION_MIDDLEWARE_EXCEPTION',
                    'severity' => 'error',
                    'terminal_uid' => null,
                    'transaction_id' => null,
                    'message' => 'Exception in ValidateTransaction middleware',
                    'context' => [
                        'path' => $request->path(),
                        'client_ip' => $request->ip(),
                        'error' => $e->getMessage(),
                    ],
                ]);
            } catch (\Throwable $logEx) {
                Log::warning('Failed to write SystemLog for TRANSACTION_MIDDLEWARE_EXCEPTION', [
                    'path' => $request->path(),
                    'error' => $logEx->getMessage(),
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process transaction',
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }
}