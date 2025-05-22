<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosProvider extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name', 
        'code', 
        'api_key', 
        'description', 
        'contact_email', 
        'contact_phone', 
        'status',
    ];

    /**
     * Get all terminals belonging to this provider
     */
    public function terminals()
    {
        return $this->hasMany(PosTerminal::class, 'provider_id');
    }

    /**
     * Get all active terminals belonging to this provider
     */
    public function activeTerminals()
    {
        return $this->terminals()->where('status', 'ACTIVE');
    }

    /**
     * Get all statistics for this provider
     */
    public function statistics()
    {
        return $this->hasMany(ProviderStatistic::class, 'provider_id');
    }

    /**
     * Get active terminals count
     */
    public function getActiveTerminalsCountAttribute()
    {
        return $this->terminals()->where('status', 'active')->count();
    }

    /**
     * Get the enrollment growth rate (terminals added in last 30 days)
     */
    public function getGrowthRateAttribute()
    {
        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30);
        
        $totalTerminals = $this->terminals()->count();
        if ($totalTerminals === 0) return 0;
        
        $newTerminals = $this->terminals()
            ->where('enrolled_at', '>=', $thirtyDaysAgo)
            ->count();
            
        return ($newTerminals / $totalTerminals) * 100;
    }
}