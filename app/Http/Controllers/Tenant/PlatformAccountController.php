<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantPlatformAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAccountController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = TenantPlatformAccount::all()->map(function ($acc) {
            return [
                'id' => $acc->id,
                'platform' => $acc->platform,
                'account_name' => $acc->account_name,
                'account_handle' => $acc->account_handle,
                'avatar_url' => $acc->avatar_url,
                'status' => $acc->status,
                'token_expires_at' => $acc->token_expires_at,
                'is_expiring' => $acc->isTokenExpiringSoon(),
                'last_validated_at' => $acc->last_validated_at,
            ];
        });

        return response()->json([
            'status' => 'success',
            'resources' => $accounts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'in:youtube,tiktok,facebook'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_handle' => ['nullable', 'string', 'max:255'],
            'avatar_url' => ['nullable', 'url'],
            'access_token' => ['nullable', 'string'],
            'refresh_token' => ['nullable', 'string'],
        ]);

        $account = TenantPlatformAccount::updateOrCreate(
            [
                'platform' => $validated['platform'],
                'account_name' => $validated['account_name'],
            ],
            [
                'account_handle' => $validated['account_handle'] ?? ('@' . strtolower(str_replace(' ', '', $validated['account_name']))),
                'avatar_url' => $validated['avatar_url'] ?? null,
                'encrypted_access_token' => $validated['access_token'] ?? 'mock_token_' . bin2hex(random_bytes(16)),
                'encrypted_refresh_token' => $validated['refresh_token'] ?? 'mock_refresh_' . bin2hex(random_bytes(16)),
                'token_expires_at' => now()->addDays(60),
                'status' => 'active',
                'last_validated_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => ucfirst($account->platform) . ' account connected successfully.',
            'resources' => $account,
        ], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $account = TenantPlatformAccount::findOrFail($id);
        $account->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Social platform account disconnected.',
        ]);
    }
}
