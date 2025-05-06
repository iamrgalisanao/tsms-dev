<?php

namespace Tests;

use Illuminate\Support\Facades\Facade;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        // Load the Laravel application
        $app = require __DIR__ . '/../bootstrap/app.php';
        
        // Reset facades
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        
        // Register filesystem provider first
        $provider = new FilesystemServiceProvider($app);
        $provider->register();
        
        // Register base bindings
        $app->singleton('files', function () {
            return new \Illuminate\Filesystem\Filesystem;
        });
        
        // Register the Facade service provider if it exists
        if (class_exists('Illuminate\Foundation\Providers\FoundationServiceProvider')) {
            $app->register(\Illuminate\Foundation\Providers\FoundationServiceProvider::class);
        }
        
        // Boot the application
        $app->boot();
        
        return $app;
    }
}
