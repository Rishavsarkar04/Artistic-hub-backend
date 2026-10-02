<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks signed-in users whose account is no longer active, even with a token issued earlier. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isActive()) {
            abort(Response::HTTP_FORBIDDEN, 'This account is not active.');
        }

        return $next($request);
    }
}
