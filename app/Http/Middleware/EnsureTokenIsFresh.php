<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a token whose stored `expires_at` has passed. AuthTokenService saves each token's real,
 * per-role expiry on its row; Passport itself only checks the JWT's expiry, which is the same
 * (longest) for every personal access token.
 */
class EnsureTokenIsFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Only Bearer tokens (AccessToken) have a stored row; anything else is left alone.
        if (! $token instanceof AccessToken || ! $token->expires_at) {
            return $next($request);
        }

        if ($token->expires_at->isPast()) {
            $token->revoke();

            throw new AuthenticationException('Your session has expired. Please sign in again.');
        }

        return $next($request);
    }
}
