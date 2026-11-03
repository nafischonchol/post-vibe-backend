<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyClientToken
{
    /**
     * Ensure the authenticated user is an active Client.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return responseError('Unauthenticated.', 401);
        }

        if (! ($user instanceof Client)) {
            return responseError('Unauthorized. Customer access required.', 403);
        }

        if ($user->is_active === false) {
            return responseError('Customer account is inactive.', 403);
        }

        return $next($request);
    }
}
