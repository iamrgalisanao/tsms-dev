<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProviderStatistic extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'date',
        'terminal_count',
        'active_terminal_count',
        'inactive_terminal_count',
        'new_enrollments'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function provider()
    {
        return $this->belongsTo(PosProvider::class, 'provider_id');
    }
}
