<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\Storage\MediaStorageService;
use Illuminate\Console\Command;

class CleanupTemporaryVideosCommand extends Command
{
    protected $signature = 'videos:cleanup-temp {--days=10} {--limit=200}';
    protected $description = 'Delete temporary video files after all platform shares are published or after retention window expires';

    public function handle(MediaStorageService $storage): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subDays($days);

        $videos = Video::query()
            ->whereNotNull('temporary_storage_path')
            ->with(['publishingBatches.schedules'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($videos->isEmpty()) {
            $this->info('No temporary videos found for cleanup.');
            return Command::SUCCESS;
        }

        $deletedCount = 0;
        $allPublishedCount = 0;
        $expiredCount = 0;
        $missingFileCount = 0;

        foreach ($videos as $video) {
            $allSchedules = $video->publishingBatches->flatMap(fn ($batch) => $batch->schedules);
            $allPublished = $allSchedules->isNotEmpty()
                && $allSchedules->every(fn ($schedule) => $schedule->upload_status === 'published');
            $isExpired = $video->created_at && $video->created_at->lte($cutoff);

            if (! $allPublished && ! $isExpired) {
                continue;
            }

            $deleted = $storage->deleteTemporaryMedia($video->temporary_storage_path);
            if (! $deleted) {
                $missingFileCount++;
            }

            $video->update(['temporary_storage_path' => null]);
            $deletedCount++;

            if ($allPublished) {
                $allPublishedCount++;
            } else {
                $expiredCount++;
            }
        }

        $this->info("Temporary video cleanup done. Processed={$videos->count()}, cleaned={$deletedCount}, all_published={$allPublishedCount}, expired={$expiredCount}, missing_files={$missingFileCount}");

        return Command::SUCCESS;
    }
}
