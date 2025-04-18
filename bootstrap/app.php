<?php
namespace App\Console\Commands;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Console\Commands\RetryFailedTransactions;
use Illuminate\Console\Command;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php', // ✅ Include API route file
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../routes/console.php',
        RetryFailedTransactions::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
