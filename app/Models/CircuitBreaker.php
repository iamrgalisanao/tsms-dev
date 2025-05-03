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
        'tenant_id',
        'service_name',
        'state',
        'failure_count',
        'failure_threshold',
        'reset_timeout',
        'last_failure_at',
        'cooldown_until'
    ];
    
    protected $casts = [
        'last_failure_at' => 'datetime',
        'cooldown_until' => 'datetime'
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function recordFailure()
    {
        $this->failure_count++;
        $this->last_failure_at = Carbon::now();
        
        if ($this->failure_count >= $this->failure_threshold) {
            $this->state = self::STATE_OPEN;
            $this->cooldown_until = Carbon::now()->addSeconds($this->reset_timeout);
        }
        
        $this->save();
    }
    
    public function recordSuccess()
    {
        if ($this->state === self::STATE_HALF_OPEN) {
            $this->state = self::STATE_CLOSED;
        }
        
        $this->failure_count = 0;
        $this->save();
    }
    
    public function isAllowed(): bool
    {
        if ($this->state === self::STATE_CLOSED) {
            return true;
        }
        
        if ($this->state === self::STATE_OPEN && $this->cooldown_until->isPast()) {
            $this->state = self::STATE_HALF_OPEN;
            $this->save();
            return true;
        }
        
        if ($this->state === self::STATE_HALF_OPEN) {
            return true;
        }
        
        return false;
    }
    
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