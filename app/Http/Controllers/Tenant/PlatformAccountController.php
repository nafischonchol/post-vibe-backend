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
