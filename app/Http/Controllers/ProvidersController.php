<?php

namespace App\Http\Controllers;

use App\Models\PosProvider;
use App\Services\PosProviderService;
use App\Services\ProviderStatisticsService;

class ProvidersController extends Controller
{
    protected $providerService;
    protected $statsService;

    public function __construct(PosProviderService $providerService, ProviderStatisticsService $statsService)
    {
        $this->providerService = $providerService;
        $this->statsService = $statsService;
    }

    public function index()
    {
        $providers = PosProvider::all();
        return view('dashboard.providers.index', compact('providers'));
    }

    public function show($id)
    {
        $provider = PosProvider::findOrFail($id);
        $metrics = $this->providerService->getProviderMetrics($provider);
        $chartData = $this->statsService->getChartData($provider->id);
        $terminalsByStatus = $provider->terminals()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get();
        
        return view('providers.show', compact(
            'provider',
            'metrics',
            'chartData',
            'terminalsByStatus'
        ));
    }
}