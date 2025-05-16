<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\CircuitBreaker;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;

class CircuitBreakerController extends Controller
{
    protected $filesystem;
    
    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }
    
    public function testEndpoint(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function testCircuit(Request $request): JsonResponse
    {
        $circuitBreaker = new CircuitBreaker($request->input('service'));
        if (!$circuitBreaker->isAvailable()) {
            return response()->json(['error' => 'Circuit breaker is open'], 503);
        }

        try {
            if ($request->boolean('should_fail')) {
                $circuitBreaker->recordFailure();
                throw new \Exception('Simulated failure');
            }
            $circuitBreaker->recordSuccess();
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            // Optionally, record failure here if not already done above
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function index()
    {
        $circuitBreakers = $this->getCircuitBreakersData();
        
        return view('dashboard.circuit-breakers', compact('circuitBreakers'));
    }
    
    public function reset($id)
    {
        try {
            // The ID here is actually the service name
            $service = $id;
            $circuitBreaker = new CircuitBreaker($service);
            $circuitBreaker->reset();
            
            return back()->with('success', 'Circuit breaker reset successfully');
        } catch (\Exception $e) {
            Log::error('Error resetting circuit breaker', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to reset circuit breaker');
        }
    }
    
    protected function getCircuitBreakersData()
    {
        $result = [];
        
        try {
            $circuitBreakersPath = storage_path('framework/circuit-breakers');
            
            if (!$this->filesystem->exists($circuitBreakersPath)) {
                // Create default sample data if directory doesn't exist
                return $this->getSampleData();
            }
            
            $files = $this->filesystem->files($circuitBreakersPath);
            $services = [];
            
            // Extract unique service names from filenames
            foreach ($files as $file) {
                $filename = pathinfo($file, PATHINFO_FILENAME);
                $parts = explode('_', $filename);
                if (count($parts) >= 2) {
                    $serviceName = $parts[0];
                    $services[$serviceName] = true;
                }
            }
            
            // Create circuit breaker objects for each service
            foreach (array_keys($services) as $service) {
                $cb = new CircuitBreaker($service);
                $state = $cb->isAvailable() ? ($this->getState($service) === 'half-open' ? 'half-open' : 'closed') : 'open';
                
                $result[] = (object)[
                    'id' => $service,
                    'service' => $service,
                    'state' => $state,
                    'failure_count' => $this->getFailureCount($service),
                    'last_failure_time' => $this->getLastFailureTime($service),
                ];
            }
            
            if (empty($result)) {
                return $this->getSampleData();
            }
            
        } catch (\Exception $e) {
            Log::error('Error loading circuit breakers', ['error' => $e->getMessage()]);
            return $this->getSampleData();
        }
        
        return collect($result);
    }
    
    protected function getState($service)
    {
        $path = storage_path('framework/circuit-breakers/' . $service . '_state.txt');
        if ($this->filesystem->exists($path)) {
            return trim($this->filesystem->get($path));
        }
        return 'closed';
    }
    
    protected function getFailureCount($service)
    {
        $path = storage_path('framework/circuit-breakers/' . $service . '_failure_count.txt');
        if ($this->filesystem->exists($path)) {
            return (int) trim($this->filesystem->get($path));
        }
        return 0;
    }
    
    protected function getLastFailureTime($service)
    {
        $path = storage_path('framework/circuit-breakers/' . $service . '_last_failure_time.txt');
        if ($this->filesystem->exists($path)) {
            $timestamp = (int) trim($this->filesystem->get($path));
            return date('Y-m-d H:i:s', $timestamp);
        }
        return null;
    }
    
    protected function getSampleData()
    {
        return collect([
            (object)[
                'id' => 'payment_gateway',
                'service' => 'payment_gateway',
                'state' => 'closed',
                'failure_count' => 0,
                'last_failure_time' => null,
            ],
            (object)[
                'id' => 'email_service',
                'service' => 'email_service',
                'state' => 'closed',
                'failure_count' => 0,
                'last_failure_time' => null,
            ],
        ]);
    }
}