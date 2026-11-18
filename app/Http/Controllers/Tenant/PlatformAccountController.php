<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\PlatformAccount\StorePlatformAccountRequest;
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

        return responseSuccess($accounts, 'Platform accounts retrieved successfully');
    }

    public function store(StorePlatformAccountRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $account = TenantPlatformAccount::updateOrCreate(
            [
                'platform' => $validated['platform'],
                'account_name' => $validated['account_name'],
            ],
            [
                'account_handle' => $validated['account_handle'] ?? ('@' . strtolower(str_replace(' ', '', $validated['account_name']))),
                'avatar_url' => $validated['avatar_url'] ?? null,
                'encrypted_access_token' => $validated['access_token'],
                'encrypted_refresh_token' => $validated['refresh_token'] ?? null,
                'token_expires_at' => now()->addDays(60),
                'status' => 'active',
                'last_validated_at' => now(),
            ]
        );

        return responseSuccess(
            $account,
            ucfirst($account->platform) . ' account connected successfully.',
            201
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $account = TenantPlatformAccount::findOrFail($id);
        $account->delete();

        return responseSuccess(null, 'Social platform account disconnected.');
    }
}
