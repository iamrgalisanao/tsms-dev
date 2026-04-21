<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CircuitBreaker
{
    protected string $serviceKey;
    protected int $failureThreshold = 5;
    protected int $resetTimeout = 60; // seconds
    protected string $cachePrefix = 'circuit_breaker:';
    
    /**
     * Create a new CircuitBreaker instance.
     */
    public function __construct(string $serviceKey)
    {
        $this->serviceKey = $serviceKey;
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
            Log::warning("CircuitBreaker [{$this->serviceKey}]: Circuit opened due to multiple failures.");
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
     * Get the cache key for a specific property
     */
    protected function getCacheKey(string $property): string
    {
        return $this->cachePrefix . $this->serviceKey . ':' . $property;
    }
    
    /**
     * Get the current state
     */
    protected function getState(): string
    {
        return Cache::get($this->getCacheKey('state'), 'closed');
    }
    
    /**
     * Set the current state
     */
    protected function setState(string $state): void
    {
        Cache::put($this->getCacheKey('state'), $state, now()->addDays(1));
    }
    
    /**
     * Get the failure count
     */
    protected function getFailureCount(): int
    {
        return (int) Cache::get($this->getCacheKey('failure_count'), 0);
    }
    
    /**
     * Set the failure count
     */
    protected function setFailureCount(int $count): void
    {
        Cache::put($this->getCacheKey('failure_count'), $count, now()->addDays(1));
    }
    
    /**
     * Get the last failure time
     */
    protected function getLastFailureTime(): ?int
    {
        $time = Cache::get($this->getCacheKey('last_failure_time'));
        return $time ? (int) $time : null;
    }
    
    /**
     * Set the last failure time
     */
    protected function setLastFailureTime(?int $time): void
    {
        if ($time === null) {
            Cache::forget($this->getCacheKey('last_failure_time'));
        } else {
            Cache::put($this->getCacheKey('last_failure_time'), $time, now()->addDays(1));
        }
    }
}