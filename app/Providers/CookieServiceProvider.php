<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cookie\CookieJar;
use Illuminate\Contracts\Cookie\QueueingFactory;

class CookieServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('cookie', function ($app) {
            $config = $app->make('config')->get('session');

            return new CookieJar(
                $config['path'],
                $config['domain'],
                $config['secure'] ?? false,
                $config['http_only'] ?? true,
                $config['same_site'] ?? 'lax',
                $config['raw'] ?? false
            );
        });

        $this->app->alias('cookie', QueueingFactory::class);
    }

    public function provides()
    {
        return ['cookie', QueueingFactory::class];
    }
}
