<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Env;

// Bank A and Bank B may later share one Apache server (XAMPP). This keeps each
// request's settings to itself, so A's settings never leak into B's requests.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
    Env::disablePutenv();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',        // screens (ATM, dashboard)
        api: __DIR__.'/../routes/api.php',        // the bank API (JSON)  <-- NEW
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
        ->withMiddleware(function (Middleware $middleware): void {
        // Check for a "simulated crash" before every request
        $middleware->prepend(\App\Http\Middleware\SimulatedCrash::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();