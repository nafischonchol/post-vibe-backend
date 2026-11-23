<?php

namespace App\Console\Commands;

use App\Models\VideoSource;
use App\Services\Video\VideoIngestionService;
use App\Services\Video\VideoScraperService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScanVideoSourcesCommand extends Command
{
    protected $signature = 'sources:scan {--source_id=} {--limit=10}';
    protected $description = 'Scan active video sources and ingest newly discovered videos';

    public function handle(VideoIngestionService $ingestionService, VideoScraperService $scraper): int
    {
        $query = VideoSource::where('status', 'active');

        if ($sourceId = $this->option('source_id')) {
            $query->where('id', $sourceId);
        } else {
            // Find sources due for scanning based on scan_frequency
            $query->where(function ($q) {
                $q->whereNull('last_scanned_at')
                  ->orWhereRaw('TIMESTAMPDIFF(MINUTE, last_scanned_at, NOW()) >= scan_frequency');
            });
        }

        $sources = $query->limit((int) $this->option('limit'))->get();

        if ($sources->isEmpty()) {
            $this->info("No video sources due for scanning.");
            return Command::SUCCESS;
        }

        $this->info("Scanning {$sources->count()} video sources for new media...");

        foreach ($sources as $source) {
            try {
                $this->info("Scanning [{$source->platform}] {$source->name} ({$source->source_identifier})...");

                // Scan source feed and ingest discovered posts (skips posts older than 48 hours)
                $result = $ingestionService->scanSource($source);
                $newVideos = is_array($result) && isset($result['ingested']) ? $result['ingested'] : $result;
                $ingestedCount = count($newVideos);
                $skippedCount = is_array($result) && isset($result['skipped_count']) ? $result['skipped_count'] : 0;

                $this->info("Ingested {$ingestedCount} new video(s) for {$source->name} (Skipped {$skippedCount} older than 7 days).");

            } catch (Exception $e) {
                Log::error("Failed to scan source ID {$source->id}: " . $e->getMessage());
                $source->update(['last_error' => $e->getMessage()]);
                $this->error("Error scanning source ID {$source->id}: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
