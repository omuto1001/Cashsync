<?php

namespace App\Http\Middleware;

use App\Console\Commands\Crash;
use Closure;
use Illuminate\Http\Request;

/**
 * Middleware = code that runs BEFORE every request reaches a controller.
 * While "php artisan cashsync:crash" is in effect for this bank server, every request
 * gets an error (HTTP 503), exactly as if the server had crashed.
 */
class SimulatedCrash
{
    public function handle(Request $request, Closure $next)
    {
        if (config('cashsync.mode') === 'bank' && Crash::isCrashed()) {
            return response()->json([
                'status' => 'error',
                'error' => 'crashed',
                'message' => 'Server '.config('cashsync.node_name').' is down (simulated crash).',
            ], 503);
        }

        return $next($request);   // not crashed: carry on to the controller as normal
    }
}