<?php

namespace App\Services\Publishing;

use App\Models\PublishingAuditLog;
use App\Models\PublishingBatch;
use App\Models\PublishingSchedule;
use App\Models\QuotaReservation;
use App\Models\QuotaReservationItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TransactionalOutbox;
use App\Models\Video;
use App\Models\VideoPlatformMetadata;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublishingBatchService
{
    public function __construct(
        protected PublishingQuotaService $quotaService
    ) {}

    /**
     * Create a Publishing Batch with transactional quota reservation & outbox dispatch.
     */
    public function createBatch(
        Tenant $tenant,
        Video $video,
        array $targets, // [['platform' => 'youtube', 'account_id' => 1], ...]
        ?Carbon $scheduledAt = null,
        string $mode = 'manual',
        ?TenantUser $creator = null
    ): PublishingBatch {
        $platforms = array_unique(array_column($targets, 'platform'));
        $scheduledTime = $scheduledAt ?: now();
        $idempotencyKey = hash('sha256', "{$tenant->id}:{$video->id}:" . json_encode($targets) . ":{$scheduledTime->timestamp}");

        return DB::transaction(function () use (
            $tenant,
            $video,
            $targets,
            $platforms,
            $scheduledTime,
            $mode,
            $creator,
            $idempotencyKey
        ) {
            // 1. Transactionally Reserve Daily Quotas (Atomic Row Locks)
            $quotaData = $this->quotaService->reserveBatchQuota($tenant, $platforms, $scheduledTime);

            // 2. Create Publishing Batch Record
            $batch = PublishingBatch::create([
                'tenant_id' => $tenant->id,
                'video_id' => $video->id,
                'idempotency_key' => $idempotencyKey,
                'publishing_mode' => $mode,
                'batch_status' => $scheduledTime->isFuture() ? 'scheduled' : 'processing',
                'analysis_enabled_snapshot' => $video->analysisResult !== null,
                'metadata_configuration_snapshot' => [
                    'platforms' => $platforms,
                ],
                'intended_publish_at' => $scheduledTime,
                'quota_date' => $quotaData['quota_date'],
                'timezone' => $tenant->timezone ?: 'UTC',
                'created_by' => $creator?->id,
            ]);

            // 3. Create Quota Reservation Record (Source of Truth)
            $reservation = QuotaReservation::create([
                'tenant_id' => $tenant->id,
                'publishing_batch_id' => $batch->id,
                'quota_date' => $quotaData['quota_date'],
                'status' => 'reserved',
                'reserved_at' => now(),
            ]);

            // 4. Create Platform Upload Schedules & Reservation Items
            foreach ($targets as $target) {
                $platform = $target['platform'];
                $accountId = $target['account_id'] ?? null;

                $metadata = VideoPlatformMetadata::withoutGlobalScopes()
                    ->where('video_id', $video->id)
                    ->where('platform', $platform)
                    ->first();

                $schedule = PublishingSchedule::create([
                    'tenant_id' => $tenant->id,
                    'publishing_batch_id' => $batch->id,
                    'platform' => $platform,
                    'tenant_platform_account_id' => $accountId,
                    'video_platform_metadata_id' => $metadata?->id,
                    'scheduled_at' => $scheduledTime,
                    'upload_status' => $scheduledTime->isFuture() ? 'scheduled' : 'queued',
                ]);

                QuotaReservationItem::create([
                    'quota_reservation_id' => $reservation->id,
                    'tenant_id' => $tenant->id,
                    'platform' => $platform,
                    'publishing_schedule_id' => $schedule->id,
                    'status' => 'reserved',
                    'reserved_at' => now(),
                ]);
            }

            // 5. Create Transactional Outbox Event for Guaranteed Queue Dispatch
            TransactionalOutbox::create([
                'event_type' => 'batch_publishing_due',
                'aggregate_type' => 'publishing_batch',
                'aggregate_id' => $batch->id,
                'payload' => [
                    'tenant_id' => $tenant->id,
                    'batch_id' => $batch->id,
                    'video_id' => $video->id,
                ],
                'status' => 'pending',
                'available_at' => $scheduledTime,
            ]);

            // 6. Audit Log
            PublishingAuditLog::create([
                'tenant_id' => $tenant->id,
                'publishing_batch_id' => $batch->id,
                'event' => 'batch_created',
                'details' => [
                    'platforms' => $platforms,
                    'scheduled_at' => $scheduledTime->toIso8601String(),
                ],
            ]);

            return $batch->load(['schedules.platformAccount', 'schedules.metadata']);
        });
    }

    /**
     * Cancel an unsent batch or schedules.
     */
    public function cancelBatch(PublishingBatch $batch): PublishingBatch
    {
        return DB::transaction(function () use ($batch) {
            foreach ($batch->schedules as $schedule) {
                if (in_array($schedule->upload_status, ['pending', 'scheduled', 'queued'])) {
                    $schedule->update(['upload_status' => 'cancelled']);
                    $this->quotaService->settlePlatformUpload($schedule, false);
                }
            }

            $this->quotaService->settleBatchGlobalQuota($batch);
            $batch->update(['batch_status' => 'cancelled']);
            return $batch;
        });
    }
}
