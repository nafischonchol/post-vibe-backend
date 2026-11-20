<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConvertQueryTokenToBearer
{
    /**
     * Handle incoming request: map ?token= query parameter to Authorization header if not already present.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has('token') && ! $request->hasHeader('Authorization')) {
            $token = $request->query('token');
            if (is_string($token) && ! empty($token)) {
                $request->headers->set('Authorization', 'Bearer ' . $token);
            }
        }

        return $next($request);
    }
}
