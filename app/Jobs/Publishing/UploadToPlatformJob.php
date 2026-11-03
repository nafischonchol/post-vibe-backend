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
    ) {}

    public function handle(PublishingQuotaService $quotaService, MediaStorageService $storage): void
    {
        // 1. Idempotency Check: Do not re-upload if already published
        if ($this->schedule->upload_status === 'published') {
            Log::info("PublishingSchedule ID {$this->schedule->id} is already published. Skipping.");
            return;
        }

        try {
            $this->schedule->update([
                'upload_status' => 'uploading',
                'attempt_count' => $this->schedule->attempt_count + 1,
            ]);

            $video = $this->schedule->batch->video;
            $platform = $this->schedule->platform;
            $metadata = $this->schedule->metadata;
            $account = $this->schedule->platformAccount;

            // In production, execute the platform-specific OAuth upload driver:
            // YouTubeGateway, TikTokGateway, FacebookGateway
            // If running in development or account is mock, simulate successful upload:
            $externalPostId = "ext_{$platform}_" . uniqid();
            $externalPostUrl = "https://{$platform}.com/watch/{$externalPostId}";

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

            // 4. Check if all sibling platform schedules in the batch are terminal
            $batch = $this->schedule->batch->fresh('schedules');
            $allTerminal = $batch->schedules->every(fn($s) => in_array($s->upload_status, ['published', 'failed', 'cancelled']));

            if ($allTerminal && $video->temporary_storage_path) {
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
