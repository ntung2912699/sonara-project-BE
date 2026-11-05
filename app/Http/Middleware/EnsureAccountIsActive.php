<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()->is_active) {
            $request->user()->tokens()->delete();

            return ApiResponse::error('auth.account_locked', 403);
        }

        return $next($request);
    }
}
