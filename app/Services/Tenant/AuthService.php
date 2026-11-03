<?php

namespace App\Services\Tenant;

use App\Models\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * Authenticate Tenant User and issue scoped Sanctum token.
     */
    public function login(array $credentials): JsonResponse
    {
        $user = TenantUser::withoutGlobalScopes()
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been deactivated.',
            ], 403);
        }

        $tenant = $user->tenant;
        if (! $tenant || $tenant->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your workspace is currently suspended or inactive.',
            ], 403);
        }

        // Update last login timestamp
        $user->update(['last_login_at' => now()]);

        // Issue token with role ability
        $token = $user->createToken('tenant-auth-token', ["role:{$user->role}"])->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful.',
            'resources' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $user->avatar,
                ],
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'timezone' => $tenant->timezone,
                    'status' => $tenant->status,
                ],
            ],
        ]);
    }

    /**
     * Invalidate current access token on logout.
     */
    public function logout(TenantUser $user): JsonResponse
    {
        $user->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully.',
        ]);
    }
}
