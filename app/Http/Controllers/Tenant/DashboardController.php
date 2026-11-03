<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PublishingBatch;
use App\Models\PublishingSchedule;
use App\Models\TenantDailyQuotaUsage;
use App\Models\TenantPlatformDailyUsage;
use App\Models\Video;
use App\Services\Publishing\PublishingQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected PublishingQuotaService $quotaService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $quotaDate = $this->quotaService->getTenantQuotaDate($tenant);

        // Videos
        $totalVideos = Video::count();
        $readyVideos = Video::where('processing_status', 'ready')->count();
        $processingVideos = Video::whereIn('processing_status', ['discovered', 'downloading', 'stored', 'analyzing', 'generating_metadata'])->count();

        // Publishing
        $scheduledBatches = PublishingBatch::where('batch_status', 'scheduled')->count();
        $publishedBatches = PublishingBatch::where('batch_status', 'published')->count();
        $failedUploads = PublishingSchedule::where('upload_status', 'failed')->count();

        // Quotas
        $this->quotaService->ensureUsageRowsExist($tenant, $quotaDate, ['youtube', 'tiktok', 'facebook']);
        $globalQuota = TenantDailyQuotaUsage::where('tenant_id', $tenant->id)->where('quota_date', $quotaDate)->first();
        $platformQuotas = TenantPlatformDailyUsage::where('tenant_id', $tenant->id)->where('quota_date', $quotaDate)->get()->keyBy('platform');

        // Recent Activity
        $recentBatches = PublishingBatch::with(['video', 'schedules'])
            ->latest()
            ->limit(5)
            ->get();

        return response()->json([
            'status' => 'success',
            'resources' => [
                'videos' => [
                    'total' => $totalVideos,
                    'ready' => $readyVideos,
                    'processing' => $processingVideos,
                ],
                'publishing' => [
                    'scheduled_batches' => $scheduledBatches,
                    'published_batches' => $publishedBatches,
                    'failed_uploads' => $failedUploads,
                ],
                'quotas' => [
                    'date' => $quotaDate,
                    'global' => [
                        'limit' => $globalQuota?->quota_limit ?? 10,
                        'used' => ($globalQuota?->reserved_count ?? 0) + ($globalQuota?->published_count ?? 0),
                        'available' => $globalQuota?->availableQuota() ?? 10,
                    ],
                    'platforms' => $platformQuotas->map(fn($p) => [
                        'limit' => $p->quota_limit,
                        'used' => $p->reserved_count + $p->published_count,
                        'available' => $p->availableQuota(),
                    ]),
                ],
                'recent_activity' => $recentBatches,
            ],
        ]);
    }
}
