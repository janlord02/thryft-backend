<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Setting;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if maintenance mode is enabled
        $maintenanceMode = Setting::getValue('maintenance_mode', false);

        if ($maintenanceMode) {
            // Allow super-admin users to bypass maintenance mode
            if ($request->user() && $request->user()->role === 'super-admin') {
                return $next($request);
            }

            // Return maintenance response
            return response()->json([
                'status' => 'error',
                'message' => 'Application is currently under maintenance. Please try again later.',
                'maintenance_mode' => true,
            ], 503);
        }

        return $next($request);
    }
}
