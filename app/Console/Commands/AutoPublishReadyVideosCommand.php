<?php

namespace App\Console\Commands;

use App\Models\PublishingBatch;
use App\Models\TenantPublishingSetting;
use App\Models\Video;
use App\Services\Publishing\PublishingBatchService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoPublishReadyVideosCommand extends Command
{
    protected $signature = 'videos:auto-publish {--tenant_id=} {--limit=5} {--interval= : Spaced interval between publications in minutes} {--force : Auto-publish even if tenant automatic_publishing_enabled is false}';
    protected $description = 'Automatically schedule publishing batches for ready videos with spaced intervals';

    public function handle(PublishingBatchService $batchService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $tenantId = $this->option('tenant_id');
        $force = (bool) $this->option('force');
        $customInterval = $this->option('interval') !== null ? max(15, (int) $this->option('interval')) : null;

        $query = Video::withoutGlobalScopes()
            ->where('processing_status', 'ready')
            ->whereNotNull('temporary_storage_path')
            ->whereNotExists(function ($sub) {
                $sub->select('id')
                    ->from('publishing_batches')
                    ->whereColumn('publishing_batches.video_id', 'videos.id')
                    ->whereIn('publishing_batches.batch_status', [
                        'scheduled',
                        'processing',
                        'completed',
                        'partially_completed',
                        'published',
                        'partially_published',
                    ]);
            })
            ->orderBy('id', 'asc');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $videos = $query->limit($limit)->get();

        if ($videos->isEmpty()) {
            $this->info("No ready unbatched videos found for auto-publishing.");
            return Command::SUCCESS;
        }

        $publishedCount = 0;

        foreach ($videos as $video) {
            if ($force) {
                // Ensure tenant setting is temporarily or permanently true
                TenantPublishingSetting::withoutGlobalScopes()
                    ->updateOrCreate(
                        ['tenant_id' => $video->tenant_id],
                        ['automatic_publishing_enabled' => true]
                    );
            }

            try {
                $batch = $batchService->autoPublishVideo($video, $customInterval);
                if ($batch) {
                    $publishedCount++;
                    $scheduledAtStr = $batch->intended_publish_at ? $batch->intended_publish_at->toDateTimeString() : 'immediately';
                    $this->info("Successfully scheduled auto-publish batch #{$batch->id} for video ID {$video->id} at {$scheduledAtStr} (Tenant {$video->tenant_id}).");
                }
            } catch (Exception $e) {
                $this->error("Failed to auto-publish video ID {$video->id}: " . $e->getMessage());
                Log::error("AutoPublishReadyVideosCommand error on video ID {$video->id}: " . $e->getMessage());
            }
        }

        $this->info("Auto-publishing scan completed. Dispatched: {$publishedCount}/{$videos->count()} videos.");
        return Command::SUCCESS;
    }
}
