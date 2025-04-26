<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class CircuitBreaker extends Model
{
    use HasFactory;
    
    const STATE_CLOSED = 'CLOSED';
    const STATE_OPEN = 'OPEN';
    const STATE_HALF_OPEN = 'HALF_OPEN';
    
    protected $fillable = [
        'service_name',
        'state',
        'failure_count',
        'failure_threshold',
        'reset_timeout',
        'tenant_id',
        'last_failure_at',
        'opened_at',
        'cooldown_until'
    ];
    
    protected $casts = [
        'last_failure_at' => 'datetime',
        'opened_at' => 'datetime',
        'cooldown_until' => 'datetime'
    ];
    
    /**
     * Record a failure and potentially open the circuit
     *
     * @return void
     */
    public function recordFailure()
    {
        $this->failure_count++;
        $this->last_failure_at = Carbon::now();
        
        // If we've reached the threshold, open the circuit
        if ($this->failure_count >= $this->failure_threshold) {
            $this->state = self::STATE_OPEN;
            $this->opened_at = Carbon::now();
            $this->cooldown_until = Carbon::now()->addSeconds($this->reset_timeout);
        }
        
        $this->save();
    }
    
    /**
     * Record a success, potentially resetting the circuit
     *
     * @return void
     */
    public function recordSuccess()
    {
        // If we're in half-open and get a success, close the circuit
        if ($this->state === self::STATE_HALF_OPEN) {
            $this->state = self::STATE_CLOSED;
        }
        
        // Reset the failure count
        $this->failure_count = 0;
        $this->save();
    }
    
    /**
     * Check if request should be allowed through
     *
     * @return bool
     */
    public function isAllowed(): bool
    {
        // Always allow if circuit is closed
        if ($this->state === self::STATE_CLOSED) {
            return true;
        }
        
        // If circuit is open but cooldown period has passed, 
        // set to half-open and allow a test request
        if ($this->state === self::STATE_OPEN && $this->cooldown_until->isPast()) {
            $this->state = self::STATE_HALF_OPEN;
            $this->save();
            return true;
        }
        
        // If circuit is half-open, allow the request to test if system has recovered
        if ($this->state === self::STATE_HALF_OPEN) {
            return true;
        }
        
        // Otherwise circuit is open and requests should be blocked
        return false;
    }
    
    /**
     * Get the tenant that owns this circuit breaker
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    
    /**
     * Create or get circuit breaker for given service
     *
     * @param string $serviceName
     * @param int|null $tenantId
     * @return self
     */
    public static function forService(string $serviceName, ?int $tenantId = null): self
    {
        return static::firstOrCreate(
            [
                'service_name' => $serviceName,
                'tenant_id' => $tenantId
            ],
            [
                'state' => self::STATE_CLOSED,
                'failure_count' => 0,
                'failure_threshold' => 5,
                'reset_timeout' => 300
            ]
        );
    }
}