<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        // Don't apply in console or if recursion is detected
        if (app()->runningInConsole()) {
            return;
        }

        static $resolvingUser = false;
        if ($resolvingUser) {
            return;
        }

        $resolvingUser = true;

        try {
            if (Auth::check()) {
                $user = Auth::user();

                // If the authenticated entity (Terminal or User) has a tenant_id,
                // restrict all queries to that tenant.
                if ($user && isset($user->tenant_id) && $user->tenant_id !== null) {
                    $builder->where($model->getTable() . '.tenant_id', $user->tenant_id);
                }
            }
        } finally {
            $resolvingUser = false;
        }
    }
}
