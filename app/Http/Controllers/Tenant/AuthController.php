<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\TenantLoginRequest;
use App\Services\Tenant\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    public function login(TenantLoginRequest $request): JsonResponse
    {
        return $this->authService->login($request->validated());
    }

    public function logout(Request $request): JsonResponse
    {
        return $this->authService->logout($request->user());
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        return response()->json([
            'status' => 'success',
            'resources' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $user->avatar,
                    'last_login_at' => $user->last_login_at,
                ],
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'timezone' => $tenant->timezone,
                    'status' => $tenant->status,
                    'plan' => $tenant->subscription?->plan?->name ?? 'Free',
                ],
            ],
        ]);
    }
}
