<?php

namespace App\Services;

use App\Models\PosProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PosProviderService
{
    public function getProviderMetrics(PosProvider $provider)
    {
        return [
            'total_terminals' => $provider->terminals()->count(),
            'active_terminals' => $provider->terminals()->where('status', 'active')->count(),
            'success_rate' => $this->calculateSuccessRate($provider),
            'last_24h_transactions' => $this->getLast24HTransactions($provider)
        ];
    }

    protected function calculateSuccessRate(PosProvider $provider)
    {
        $transactions = $provider->terminals()
            ->join('transactions', 'pos_terminals.id', '=', 'transactions.terminal_id')
            ->where('transactions.created_at', '>=', now()->subDays(7))
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as successful')
            ->first();

        return $transactions->total > 0 
            ? round(($transactions->successful / $transactions->total) * 100, 2) 
            : 0;
    }

    protected function getLast24HTransactions(PosProvider $provider)
    {
        return $provider->terminals()
            ->join('transactions', 'pos_terminals.id', '=', 'transactions.terminal_id')
            ->where('transactions.created_at', '>=', now()->subHours(24))
            ->count();
    }
}