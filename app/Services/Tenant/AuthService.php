<?php

namespace App\Services\Tenant;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantPublishingSetting;
use App\Models\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    /**
     * Register a new Tenant with Owner User in pending state.
     */
    public function register(array $data): JsonResponse
    {
        $tenant = DB::transaction(function () use ($data) {
            $slug = Str::slug($data['name']);
            if (Tenant::where('slug', $slug)->exists()) {
                $slug .= '-' . Str::lower(Str::random(5));
            }

            // 1. Create Tenant with 'pending' status
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $slug,
                'email' => $data['email'],
                'timezone' => $data['timezone'] ?? 'UTC',
                'status' => 'pending',
                'onboarding_completed_at' => null,
            ]);

            // 2. Create Owner User
            TenantUser::create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => Hash::make($data['owner_password']),
                'role' => 'owner',
                'status' => 'pending',
            ]);

            // 3. Assign Plan (Free or Basic)
            $planId = Plan::where('slug', 'free')->value('id')
                ?? Plan::where('slug', 'basic')->value('id')
                ?? Plan::first()?->id;

            if ($planId) {
                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $planId,
                    'status' => 'pending',
                    'starts_at' => now(),
                    'expires_at' => now()->addYear(),
                    'billing_cycle' => 'monthly',
                ]);
            }

            // 4. Default Publishing Settings
            TenantPublishingSetting::create([
                'tenant_id' => $tenant->id,
                'automatic_publishing_enabled' => false,
                'manual_publishing_enabled' => true,
                'timezone' => $tenant->timezone,
            ]);

            return $tenant;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Registration submitted successfully. Your workspace is pending administrator approval.',
            'resources' => [
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'email' => $tenant->email,
                    'status' => $tenant->status,
                ],
            ],
        ], 201);
    }

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

        $tenant = $user->tenant;
        if (! $tenant || $tenant->status !== 'active') {
            if ($tenant && $tenant->status === 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your workspace registration is currently pending administrator approval.',
                ], 403);
            }

            if ($tenant && $tenant->status === 'rejected') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your workspace registration has been rejected. Please contact support.',
                ], 403);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Your workspace is currently suspended or inactive.',
            ], 403);
        }

        if ($user->status !== 'active') {
            if ($user->status === 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your user account is pending administrator approval.',
                ], 403);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been deactivated.',
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
