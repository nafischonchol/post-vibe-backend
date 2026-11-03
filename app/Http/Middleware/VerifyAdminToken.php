<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAdminToken
{
    /**
     * Ensure the authenticated user is an active Admin.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return responseError('Unauthenticated.', 401);
        }

        if (! ($user instanceof Admin)) {
            return responseError('Unauthorized. Admin access required.', 403);
        }

        if ($user->is_active === false) {
            return responseError('Admin account is inactive.', 403);
        }

        return $next($request);
    }
}
