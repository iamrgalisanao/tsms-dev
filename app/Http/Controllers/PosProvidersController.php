<?php

namespace App\Http\Controllers;

use App\Models\PosProvider;
use App\Services\PosProviderService;
use App\Services\ProviderStatisticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PosProvidersController extends Controller
{
    protected $providerService;
    protected $statsService;

    public function __construct(PosProviderService $providerService, ProviderStatisticsService $statsService)
    {
        $this->providerService = $providerService;
        $this->statsService = $statsService;
    }

    /**
     * Display a listing of providers
     */
    public function index()
    {
        $providers = PosProvider::all();
        return view('providers.index', compact('providers'));
    }

    public function dashboard()
    {
        $providers = PosProvider::all()->map(function($provider) {
            return array_merge(
                $provider->toArray(),
                $this->providerService->getProviderMetrics($provider)
            );
        });

        return view('providers.dashboard', compact('providers'));
    }

    public function show(PosProvider $provider)
    {
        try {
            $metrics = $this->providerService->getProviderMetrics($provider);
            $chartData = $this->statsService->getChartData($provider->id);

            \Log::info('Provider show data', [
                'provider_id' => $provider->id,
                'has_chart_data' => !empty($chartData),
                'metrics' => array_keys($metrics)
            ]);

            return view('providers.show', compact('provider', 'metrics', 'chartData'));
        } catch (\Exception $e) {
            \Log::error('Error in provider show', [
                'error' => $e->getMessage(),
                'provider_id' => $provider->id
            ]);
            return redirect()->route('dashboard')->with('error', 'Error loading provider details');
        }
    }

    /**
     * Display provider statistics
     */
    public function statistics()
    {
        $stats = $this->statsService->getAllProviderStats();
        return view('providers.statistics', compact('stats'));
    }

    /**
     * Generate statistics for providers
     */
    public function generateStats(Request $request)
    {
        try {
            $date = $request->get('date') ? now()->parse($request->date) : now();
            $this->statsService->generateDailyStats($date);
            
            return redirect()->back()->with('success', 'Statistics generated successfully');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to generate statistics');
        }
    }
}