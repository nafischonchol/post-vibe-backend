<?php

namespace App\Http\Middleware;

use App\Models\TenantUser;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTenantToken
{
    /**
     * Handle an incoming request.
     * Ensure the authenticated user is an active TenantUser and set TenantContext.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! ($user instanceof TenantUser)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Tenant authentication required.',
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your tenant user account has been suspended or is inactive.',
            ], 403);
        }

        $tenant = $user->tenant;
        if (! $tenant || $tenant->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your tenant workspace is currently suspended or inactive.',
            ], 403);
        }

        // Initialize tenant context for this request cycle
        TenantContext::setTenant($tenant);

        return $next($request);
    }
}
