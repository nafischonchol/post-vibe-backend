<?php

namespace App\Services\Publishing;

use App\Models\PublishingBatch;
use App\Models\PublishingSchedule;
use App\Models\QuotaReservation;
use App\Models\QuotaReservationItem;
use App\Models\Tenant;
use App\Models\TenantDailyQuotaUsage;
use App\Models\TenantPlatformDailyUsage;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PublishingQuotaService
{
    /**
     * Determine tenant's local date for quota tracking.
     */
    public function getTenantQuotaDate(Tenant $tenant, ?Carbon $scheduledAt = null): string
    {
        $timezone = $tenant->timezone ?: 'UTC';
        $time = $scheduledAt ?: now();

        return $time->copy()->setTimezone($timezone)->toDateString();
    }

    /**
     * Ensure usage tracking rows exist for the date before locking.
     */
    public function ensureUsageRowsExist(Tenant $tenant, string $quotaDate, array $platforms): void
    {
        // 1. Global Daily Quota Usage row
        $globalLimit = $tenant->quotaOverride?->global_daily_quota
            ?? $tenant->subscription?->plan?->global_daily_quota
            ?? 10;

        TenantDailyQuotaUsage::firstOrCreate(
            ['tenant_id' => $tenant->id, 'quota_date' => $quotaDate],
            ['quota_limit' => $globalLimit, 'reserved_count' => 0, 'published_count' => 0, 'failed_count' => 0]
        );

        // 2. Platform Daily Quota Usage rows
        $planQuotas = $tenant->subscription?->plan?->platformQuotas->keyBy('platform') ?? collect();

        foreach ($platforms as $platform) {
            $platformLimit = $planQuotas->get($platform)?->daily_quota ?? 10;

            TenantPlatformDailyUsage::firstOrCreate(
                ['tenant_id' => $tenant->id, 'platform' => $platform, 'quota_date' => $quotaDate],
                ['quota_limit' => $platformLimit, 'reserved_count' => 0, 'published_count' => 0, 'failed_count' => 0]
            );
        }
    }

    /**
     * Atomically lock and reserve quota for a Publishing Batch (Together Publishing).
     *
     * @throws Exception if quota is exceeded
     */
    public function reserveBatchQuota(Tenant $tenant, array $platforms, ?Carbon $scheduledAt = null): array
    {
        $quotaDate = $this->getTenantQuotaDate($tenant, $scheduledAt);
        $this->ensureUsageRowsExist($tenant, $quotaDate, $platforms);

        // 1. Lock global usage row
        $globalUsage = TenantDailyQuotaUsage::where('tenant_id', $tenant->id)
            ->where('quota_date', $quotaDate)
            ->lockForUpdate()
            ->firstOrFail();

        // 2. Lock selected platform usage rows in consistent order to prevent deadlocks
        $platformUsages = TenantPlatformDailyUsage::where('tenant_id', $tenant->id)
            ->where('quota_date', $quotaDate)
            ->whereIn('platform', $platforms)
            ->orderBy('platform')
            ->lockForUpdate()
            ->get()
            ->keyBy('platform');

        // 3. Validate Global Quota (1 reservation per batch)
        if (($globalUsage->reserved_count + $globalUsage->published_count) >= $globalUsage->quota_limit) {
            throw new Exception("Global daily publishing quota reached ({$globalUsage->quota_limit} batches/day).");
        }

        // 4. Validate All Selected Platforms Quota (All or nothing)
        foreach ($platforms as $platform) {
            $usage = $platformUsages->get($platform);
            if (! $usage || ($usage->reserved_count + $usage->published_count) >= $usage->quota_limit) {
                throw new Exception("Daily publishing quota for {$platform} reached ({$usage?->quota_limit} uploads/day).");
            }
        }

        // 5. Increment reserved counters atomically
        $globalUsage->increment('reserved_count');
        foreach ($platformUsages as $usage) {
            $usage->increment('reserved_count');
        }

        return [
            'quota_date' => $quotaDate,
            'global_usage' => $globalUsage,
            'platform_usages' => $platformUsages,
        ];
    }

    /**
     * Settle an individual platform upload outcome.
     */
    public function settlePlatformUpload(PublishingSchedule $schedule, bool $success): void
    {
        DB::transaction(function () use ($schedule, $success) {
            $batch = $schedule->batch;
            $quotaDate = $batch->quota_date->toDateString();

            $platformUsage = TenantPlatformDailyUsage::where('tenant_id', $schedule->tenant_id)
                ->where('platform', $schedule->platform)
                ->where('quota_date', $quotaDate)
                ->lockForUpdate()
                ->first();

            $item = QuotaReservationItem::where('publishing_schedule_id', $schedule->id)
                ->lockForUpdate()
                ->first();

            if ($success) {
                if ($platformUsage && $platformUsage->reserved_count > 0) {
                    $platformUsage->decrement('reserved_count');
                    $platformUsage->increment('published_count');
                }
                $item?->update(['status' => 'consumed', 'consumed_at' => now()]);
            } else {
                // Permanent Failure: Release platform quota
                if ($platformUsage && $platformUsage->reserved_count > 0) {
                    $platformUsage->decrement('reserved_count');
                    $platformUsage->increment('failed_count');
                }
                $item?->update(['status' => 'released', 'released_at' => now()]);
            }

            // Settle Global Batch Quota based on all sibling platform outcomes
            $this->settleBatchGlobalQuota($batch);
        });
    }

    /**
     * Settle global batch quota: consumed if at least 1 platform succeeds; released if ALL permanently fail.
     */
    protected function settleBatchGlobalQuota(PublishingBatch $batch): void
    {
        $reservation = QuotaReservation::where('publishing_batch_id', $batch->id)
            ->lockForUpdate()
            ->first();

        if (! $reservation || ! in_array($reservation->status, ['reserved', 'consumed'])) {
            return;
        }

        $schedules = $batch->schedules()->get();
        $anySuccess = $schedules->contains('upload_status', 'published');
        $allTerminalFailed = $schedules->every(fn($s) => in_array($s->upload_status, ['failed', 'cancelled']));

        $quotaDate = $batch->quota_date->toDateString();
        $globalUsage = TenantDailyQuotaUsage::where('tenant_id', $batch->tenant_id)
            ->where('quota_date', $quotaDate)
            ->lockForUpdate()
            ->first();

        if ($anySuccess) {
            if ($reservation->status === 'reserved') {
                if ($globalUsage && $globalUsage->reserved_count > 0) {
                    $globalUsage->decrement('reserved_count');
                    $globalUsage->increment('published_count');
                }
                $reservation->update(['status' => 'consumed', 'consumed_at' => now()]);
            }

            $isFullyPublished = $schedules->every(fn($s) => $s->upload_status === 'published');
            $batch->update([
                'batch_status' => $isFullyPublished ? 'published' : 'partially_published',
                'published_at' => $isFullyPublished ? now() : $batch->published_at,
            ]);
        } elseif ($allTerminalFailed) {
            if ($reservation->status === 'reserved') {
                if ($globalUsage && $globalUsage->reserved_count > 0) {
                    $globalUsage->decrement('reserved_count');
                    $globalUsage->increment('failed_count');
                }
                $reservation->update(['status' => 'released', 'released_at' => now()]);
            }
            $batch->update(['batch_status' => 'failed']);
        }
    }
}
