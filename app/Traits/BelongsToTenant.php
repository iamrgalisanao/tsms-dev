<?php

namespace App\Traits;

use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootBelongsToTenant()
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (!$model->tenant_id && Auth::check()) {
                $user = Auth::user();
                // Both PosTerminal and User models in TSMS carry tenant_id
                if (isset($user->tenant_id)) {
                    $model->tenant_id = $user->tenant_id;
                }
            }
        });
    }

    /**
     * Relationship with the Tenant.
     */
    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class, 'tenant_id');
    }
}
