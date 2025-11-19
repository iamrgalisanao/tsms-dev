<?php

namespace App\Jobs\Reporting;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InvalidateCountCacheJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $token; // raw bearer token or null
    public $sanctumId; // sanctum token id or null
    public $ip;
    public $filters;
    public $tenantId;

    public function __construct($token = null, $sanctumId = null, $ip = null, array $filters = [], $tenantId = null)
    {
        $this->token = $token;
        $this->sanctumId = $sanctumId;
        $this->ip = $ip;
        $this->filters = $filters;
        $this->tenantId = $tenantId;
        $this->onQueue('reporting');
    }

    public function handle()
    {
        try {
            $tokenId = null;
            if ($this->token) {
                $tokenId = 'static:' . substr(md5($this->token), 0, 16);
            }
            if (! $tokenId && $this->sanctumId) {
                $tokenId = 'sanctum:' . $this->sanctumId;
            }
            $tokenId = $tokenId ?? ($this->ip ?? 'unknown');

            // Include tenant version to allow O(1) invalidation per tenant
            $tenantVersion = 0;
            if ($this->tenantId) {
                try {
                    $tenantVersion = (int) Cache::get('webapp:tenant_version:' . $this->tenantId, 0);
                } catch (\Throwable $e) {
                    $tenantVersion = 0;
                }
            }

            $key = 'webapp:count:' . $tokenId . ':v' . $tenantVersion . ':' . md5(json_encode($this->filters));
            Cache::forget($key);
            Log::info('Invalidated webapp count cache', ['key' => $key]);
        } catch (\Throwable $e) {
            Log::error('Failed to invalidate webapp count cache', ['error' => $e->getMessage()]);
        }
    }
}
