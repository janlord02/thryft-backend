<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browse endpoints that work signed out but get better signed in.
 *
 * auth:sanctum rejects a missing token. This only points the default guard at
 * sanctum, so $request->user() resolves to the account behind a valid token
 * and to null for a guest, and the controller decides what to personalise
 * (favorites, claimed flags) rather than whether to answer at all.
 */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('sanctum');

        return $next($request);
    }
}
