<?php

namespace App\Jobs\Publishing;

use App\Models\PublishingSchedule;
use App\Services\Publishing\PublishingQuotaService;
use App\Services\Storage\MediaStorageService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UploadToPlatformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 minutes for video upload

    public function __construct(
        public PublishingSchedule $schedule
    ) {
        $this->onQueue('publishing');
    }

    public function handle(
        PublishingQuotaService $quotaService,
        MediaStorageService $storage,
        \App\Services\Platform\PlatformGatewayManager $gatewayManager
    ): void {
        $this->schedule->refresh();

        // 1. Idempotency Check: Do not re-upload if already published or post ID exists
        if ($this->schedule->upload_status === 'published' || ! empty($this->schedule->external_post_id)) {
            Log::info("PublishingSchedule ID {$this->schedule->id} is already published. Skipping duplicate upload.");
            return;
        }

        $video = $this->schedule->batch?->video;
        $platform = $this->schedule->platform;

        if (! $video) {
            throw new Exception("Video not found for schedule ID {$this->schedule->id}");
        }

        // Check if this video has already been published on this platform
        $alreadyPublishedSchedule = PublishingSchedule::where('platform', $platform)
            ->where('upload_status', 'published')
            ->whereNotNull('external_post_id')
            ->where('id', '!=', $this->schedule->id)
            ->whereHas('batch', fn($q) => $q->where('video_id', $video->id))
            ->first();

        if ($alreadyPublishedSchedule) {
            Log::info("Video ID {$video->id} is already published on {$platform} via schedule #{$alreadyPublishedSchedule->id}. Marking schedule #{$this->schedule->id} as published to avoid duplication.");
            $this->schedule->update([
                'upload_status' => 'published',
                'external_post_id' => $alreadyPublishedSchedule->external_post_id,
                'external_post_url' => $alreadyPublishedSchedule->external_post_url,
                'published_at' => now(),
            ]);
            return;
        }

        try {
            $this->schedule->update([
                'upload_status' => 'uploading',
                'attempt_count' => $this->schedule->attempt_count + 1,
            ]);

            $metadata = $this->schedule->metadata;
            $account = $this->schedule->platformAccount;

            if (!$account) {
                throw new Exception("No linked {$platform} platform account attached to schedule.");
            }

            // Execute platform-specific OAuth gateway driver
            $gateway = $gatewayManager->gateway($platform);
            $result = $gateway->upload($account, $video, $metadata, $this->schedule);

            $externalPostId = $result['external_post_id'];
            $externalPostUrl = $result['external_post_url'];

            // 2. Mark Platform Schedule as Published
            $this->schedule->update([
                'upload_status' => 'published',
                'external_post_id' => $externalPostId,
                'external_post_url' => $externalPostUrl,
                'published_at' => now(),
                'last_error' => null,
            ]);

            // 3. Consume platform quota atomically
            $quotaService->settlePlatformUpload($this->schedule, true);

            // 4. Delete temporary media only after all platform schedules are published.
            // Non-published outcomes are handled by scheduled retention cleanup.
            $batch = $this->schedule->batch->fresh('schedules');
            $allPublished = $batch->schedules->isNotEmpty()
                && $batch->schedules->every(fn($s) => $s->upload_status === 'published');

            if ($allPublished && $video->temporary_storage_path) {
                // Delete temporary video according to Section 17 retention policy
                $storage->deleteTemporaryMedia($video->temporary_storage_path);
                $video->update(['temporary_storage_path' => null]);
            }

        } catch (Exception $e) {
            Log::error("UploadToPlatformJob failed for schedule ID {$this->schedule->id}: " . $e->getMessage());

            $isPermanent = $this->attempts() >= $this->tries;

            if ($isPermanent) {
                $this->schedule->update([
                    'upload_status' => 'failed',
                    'last_error' => $e->getMessage(),
                ]);

                // Release platform quota on permanent failure
                $quotaService->settlePlatformUpload($this->schedule, false);
            } else {
                $this->schedule->update([
                    'upload_status' => 'retrying',
                    'next_retry_at' => now()->addMinutes(2 * $this->attempts()),
                    'last_error' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}
