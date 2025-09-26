<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Session;

class EnsureApiSession
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Start session if not already started
        if (!Session::isStarted()) {
            Session::start();
        }

        // Set session configuration for API routes
        config(['session.driver' => 'file']);
        config(['session.lifetime' => 120]);

        return $next($request);
    }
}
