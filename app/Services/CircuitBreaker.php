<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Filesystem\Filesystem;

class CircuitBreaker
{
    protected string $serviceKey;
    protected string $storagePath;
    protected int $failureThreshold = 5;
    protected int $resetTimeout = 60; // seconds
    protected Filesystem $filesystem;
    
    /**
     * Create a new CircuitBreaker instance.
     */
    public function __construct(string $serviceKey)
    {
        $this->serviceKey = $serviceKey;
        $this->filesystem = new Filesystem();
        $this->storagePath = storage_path('framework/circuit-breakers');
        
        // Ensure the storage directory exists
        if (!$this->filesystem->exists($this->storagePath)) {
            $this->filesystem->makeDirectory($this->storagePath, 0755, true);
        }
    }
    
    /**
     * Check if the service is available
     */
    public function isAvailable(): bool
    {
        $state = $this->getState();
        
        if ($state === 'open') {
            $lastFailure = $this->getLastFailureTime();
            
            // Check if reset timeout has passed
            if ($lastFailure && (time() - $lastFailure) > $this->resetTimeout) {
                // Move to half-open state
                $this->setState('half-open');
                return true;
            }
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Record a successful operation
     */
    public function recordSuccess(): void
    {
        $state = $this->getState();
        
        if ($state === 'half-open') {
            // Reset the circuit on success in half-open state
            $this->reset();
        }
    }
    
    /**
     * Record a failed operation
     */
    public function recordFailure(): void
    {
        $failureCount = $this->getFailureCount() + 1;
        $this->setFailureCount($failureCount);
        $this->setLastFailureTime(time());
        
        if ($failureCount >= $this->failureThreshold) {
            $this->setState('open');
        }
    }
    
    /**
     * Reset the circuit breaker
     */
    public function reset(): void
    {
        $this->setState('closed');
        $this->setFailureCount(0);
        $this->setLastFailureTime(null);
    }
    
    /**
     * Get the file path for a specific property
     */
    protected function getFilePath(string $property): string
    {
        return $this->storagePath . '/' . $this->serviceKey . '_' . $property . '.txt';
    }
    
    /**
     * Get the current state
     */
    protected function getState(): string
    {
        try {
            $path = $this->getFilePath('state');
            if ($this->filesystem->exists($path)) {
                return trim($this->filesystem->get($path));
            }
        } catch (\Exception $e) {
            Log::error('Circuit breaker error', ['error' => $e->getMessage()]);
        }
        return 'closed';
    }
    
    /**
     * Set the current state
     */
    protected function setState(string $state): void
    {
        try {
            $path = $this->getFilePath('state');
            $this->filesystem->put($path, $state);
        } catch (\Exception $e) {
            Log::error('Circuit breaker error', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get the failure count
     */
    protected function getFailureCount(): int
    {
        try {
            $path = $this->getFilePath('failure_count');
            if ($this->filesystem->exists($path)) {
                return (int) trim($this->filesystem->get($path));
            }
        } catch (\Exception $e) {
            Log::error('Circuit breaker error', ['error' => $e->getMessage()]);
        }
        return 0;
    }
    
    /**
     * Set the failure count
     */
    protected function setFailureCount(int $count): void
    {
        try {
            $path = $this->getFilePath('failure_count');
            $this->filesystem->put($path, (string) $count);
        } catch (\Exception $e) {
            Log::error('Circuit breaker error', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get the last failure time
     */
    protected function getLastFailureTime(): ?int
    {
        try {
            $path = $this->getFilePath('last_failure_time');
            if ($this->filesystem->exists($path)) {
                return (int) trim($this->filesystem->get($path));
            }
        } catch (\Exception $e) {
            Log::error('Circuit breaker error', ['error' => $e->getMessage()]);
        }
        return null;
    }
    
    /**
     * Set the last failure time
     */
    protected function setLastFailureTime(?int $time): void
    {
        try {
            $path = $this->getFilePath('last_failure_time');
            if ($time === null) {
                if ($this->filesystem->exists($path)) {
                    $this->filesystem->delete($path);
                }
            } else {
                $this->filesystem->put($path, (string) $time);
            }
        } catch (\Exception $e) {
            Log::error('Circuit breaker error', ['error' => $e->getMessage()]);
        }
    }
}