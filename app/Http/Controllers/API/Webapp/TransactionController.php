<?php

namespace App\Http\Controllers\Api\Webapp;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Http\Resources\TransactionResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class TransactionController extends Controller
{
    /**
     * List transactions (paginated).
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 15);
        $maxPer = (int) config('webapp_api.max_per_page', 100);
        if ($perPage < 1) {
            $perPage = 15;
        }
        $perPage = min($perPage, $maxPer);

        $query = Transaction::query()->orderBy('created_at', 'desc');

        // Basic filters (tenant_id, terminal_id, validation_status)
        if ($request->has('tenant_id')) {
            $query->where('tenant_id', $request->query('tenant_id'));
        }
        if ($request->has('terminal_id')) {
            $query->where('terminal_id', $request->query('terminal_id'));
        }
        if ($request->has('validation_status')) {
            $query->where('validation_status', $request->query('validation_status'));
        }

        $results = $query->paginate($perPage);

        return TransactionResource::collection($results);
    }

    /**
     * Return authoritative count for filters.
     */
    public function count(Request $request)
    {
        $query = Transaction::query();
        if ($request->has('tenant_id')) {
            $query->where('tenant_id', $request->query('tenant_id'));
        }
        if ($request->has('terminal_id')) {
            $query->where('terminal_id', $request->query('terminal_id'));
        }
        if ($request->has('validation_status')) {
            $query->where('validation_status', $request->query('validation_status'));
        }

        // Compose cache key using (in order): static bearer token id, Sanctum token id, client IP
        $bearer = $request->bearerToken();
        $tokenId = null;
        if ($bearer) {
            $tokenId = 'static:' . substr(md5($bearer), 0, 16);
        }

        if (! $tokenId && $request->user()?->currentAccessToken()) {
            $tokenId = 'sanctum:' . $request->user()->currentAccessToken()->getKey();
        }

        $tokenId = $tokenId ?? $request->ip();
        $filters = [
            'tenant_id' => $request->query('tenant_id'),
            'terminal_id' => $request->query('terminal_id'),
            'validation_status' => $request->query('validation_status'),
        ];
        $key = 'webapp:count:' . $tokenId . ':' . md5(json_encode($filters));

        $ttl = (int) Config::get('webapp_api.cache_ttl_seconds', 10);

        // Store a small payload with timestamp so clients can detect cached results
        $payload = Cache::get($key);
        $cached = true;
        if (! $payload) {
            $countVal = (int) $query->count();
            $payload = [
                'count' => $countVal,
                'timestamp' => now()->toIso8601String(),
            ];
            Cache::put($key, $payload, $ttl);
            $cached = false;
        }

        $response = response()->json(['count' => (int) $payload['count']]);
        // Add cache metadata headers for client UX
        $response->headers->set('X-Count-Cached', $cached ? 'true' : 'false');
        $response->headers->set('X-Count-TTL', (string) $ttl);
        $response->headers->set('X-Count-Timestamp', $payload['timestamp']);

        return $response;
    }

    /**
     * Show single transaction resource.
     */
    public function show($id)
    {
        $tx = Transaction::find($id);
        if (! $tx) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        return new TransactionResource($tx->load(['terminal', 'tenant', 'jobs', 'adjustments', 'taxes']));
    }
}
