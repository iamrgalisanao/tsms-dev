<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderStatistics extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'provider_id',
        'date',
        'terminal_count',
        'active_terminal_count',
        'inactive_terminal_count',
        'new_enrollments',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
    ];
    
    /**
     * Get the provider that owns these statistics
     */
    public function provider()
    {
        return $this->belongsTo(PosProvider::class);
    }
}
