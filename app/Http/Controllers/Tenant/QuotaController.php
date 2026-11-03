<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantDailyQuotaUsage;
use App\Models\TenantPlatformDailyUsage;
use App\Services\Publishing\PublishingQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuotaController extends Controller
{
    public function __construct(
        protected PublishingQuotaService $quotaService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $quotaDate = $this->quotaService->getTenantQuotaDate($tenant);

        $this->quotaService->ensureUsageRowsExist($tenant, $quotaDate, ['youtube', 'tiktok', 'facebook']);

        $global = TenantDailyQuotaUsage::where('tenant_id', $tenant->id)
            ->where('quota_date', $quotaDate)
            ->first();

        $platforms = TenantPlatformDailyUsage::where('tenant_id', $tenant->id)
            ->where('quota_date', $quotaDate)
            ->get()
            ->keyBy('platform');

        return response()->json([
            'status' => 'success',
            'resources' => [
                'quota_date' => $quotaDate,
                'timezone' => $tenant->timezone,
                'global' => [
                    'limit' => $global?->quota_limit ?? 10,
                    'reserved' => $global?->reserved_count ?? 0,
                    'published' => $global?->published_count ?? 0,
                    'failed' => $global?->failed_count ?? 0,
                    'available' => $global?->availableQuota() ?? 10,
                ],
                'platforms' => $platforms->map(fn($p) => [
                    'limit' => $p->quota_limit,
                    'reserved' => $p->reserved_count,
                    'published' => $p->published_count,
                    'failed' => $p->failed_count,
                    'available' => $p->availableQuota(),
                ]),
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $history = TenantDailyQuotaUsage::where('tenant_id', $tenant->id)
            ->latest('quota_date')
            ->limit(30)
            ->get();

        return response()->json([
            'status' => 'success',
            'resources' => $history,
        ]);
    }
}
