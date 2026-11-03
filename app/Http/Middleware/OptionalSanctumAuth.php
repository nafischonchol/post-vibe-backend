<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class OptionalSanctumAuth
{
    /**
     * Handle an incoming request and authenticate user if a valid bearer token is present.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($bearerToken = $request->bearerToken()) {
            $token = PersonalAccessToken::findToken($bearerToken);

            if ($token && (! $token->expires_at || ! $token->expires_at->isPast())) {
                $user = $token->tokenable;
                if ($user) {
                    Auth::guard('sanctum')->setUser($user);
                    Auth::setUser($user);
                    $request->setUserResolver(fn () => $user);
                }
            }
        } elseif ($user = Auth::guard('sanctum')->user()) {
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);
        }

        return $next($request);
    }
}
