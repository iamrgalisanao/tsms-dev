<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class HorizonServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Allow everyone to see Horizon (for testing only)
        Horizon::auth(function ($request) {
            return true;
        });
    }
}