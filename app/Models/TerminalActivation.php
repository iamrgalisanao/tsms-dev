<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerminalActivation extends Model
{
    protected $fillable = [
        'terminal_id',
        'tenant_id',
        'license_id',
        'deployment_id',
        'location_code',
        'activation_status',
        'activated_at',
        'revoked_at',
        'metadata',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
