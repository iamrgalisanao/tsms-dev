<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class CircuitBreaker extends Model
{
    use HasFactory;

    // Define status constants
    public const STATUS_CLOSED = 'CLOSED';
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_HALF_OPEN = 'HALF_OPEN';
    
    const STATE_CLOSED = 'CLOSED';
    const STATE_OPEN = 'OPEN';
    const STATE_HALF_OPEN = 'HALF_OPEN';
    
    protected $fillable = [
        'tenant_id',
        'name',
        'status',
        'failures',
        'trip_count',
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
    
    // Add accessors for backward compatibility with tests
    public function getStateAttribute()
    {
        return $this->status;
    }
    
    public function setStateAttribute($value)
    {
        $this->attributes['status'] = $value;
    }
    
    public function getFailureCountAttribute()
    {
        return $this->failures;
    }
    
    public function setFailureCountAttribute($value)
    {
        $this->attributes['failures'] = $value;
    }
    
    public function getOpenedAtAttribute()
    {
        return $this->last_failure_at;
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function recordFailure()
    {
        $this->failures++;
        $this->last_failure_at = Carbon::now();
        
        if ($this->failures >= $this->failure_threshold) {
            $this->status = self::STATE_OPEN;
            $this->cooldown_until = Carbon::now()->addSeconds((int) $this->reset_timeout);
        }
        
        $this->save();
    }
    
    public function recordSuccess()
    {
        if ($this->status === self::STATE_HALF_OPEN) {
            $this->status = self::STATE_CLOSED;
        }
        
        $this->failures = 0;
        $this->save();
    }
    
    public function isAllowed(): bool
    {
        if ($this->status === self::STATE_CLOSED) {
            return true;
        }
        
        if ($this->status === self::STATE_OPEN && $this->cooldown_until->isPast()) {
            $this->status = self::STATE_HALF_OPEN;
            $this->save();
            return true;
        }
        
        if ($this->status === self::STATE_HALF_OPEN) {
            return true;
        }
        
        return false;
    }
    
    public static function forService(string $serviceName, ?int $tenantId = null): self
    {
        return static::firstOrCreate(
            [
                'name' => $serviceName,
                'tenant_id' => $tenantId
            ],
            [
                'status' => self::STATE_CLOSED,
                'failures' => 0,
                'failure_threshold' => 5,
                'reset_timeout' => 300
            ]
        );
    }
}