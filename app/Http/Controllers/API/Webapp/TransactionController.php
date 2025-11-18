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

        $count = Cache::remember($key, $ttl, function () use ($query) {
            return (int) $query->count();
        });

        return response()->json(['count' => $count]);
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
