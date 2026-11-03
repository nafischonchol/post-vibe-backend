<?php

namespace App\Jobs\Video;

use App\Jobs\AI\ProcessVideoAiJob;
use App\Models\Video;
use App\Services\Storage\MediaStorageService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DownloadAndStoreVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes

    public function __construct(
        public Video $video,
        public string $downloadUrl
    ) {}

    public function handle(MediaStorageService $storage): void
    {
        try {
            // 1. Mark as downloading
            $this->video->update(['processing_status' => 'downloading']);

            // 2. Fetch media contents with timeout
            $response = Http::timeout(180)->get($this->downloadUrl);

            if (! $response->successful()) {
                throw new Exception("Failed to download video from {$this->downloadUrl}. HTTP Status: {$response->status()}");
            }

            $binary = $response->body();
            $hash = hash('sha256', $binary);
            $size = strlen($binary);

            // 3. Store into temporary S3 / disk storage
            $filename = "{$this->video->video_uid}.mp4";
            $storagePath = $storage->storeVideo($this->video->tenant_id, $filename, $binary);

            // 4. Update Video Record
            $this->video->update([
                'video_hash' => $hash,
                'temporary_storage_path' => $storagePath,
                'file_size_bytes' => $size,
                'processing_status' => 'stored',
                'processing_error' => null,
            ]);

            // 5. Chain next step in the pipeline: Trigger AI analysis & metadata generation
            ProcessVideoAiJob::dispatch($this->video);

        } catch (Exception $e) {
            Log::error("DownloadAndStoreVideoJob failed for video ID {$this->video->id}: " . $e->getMessage());

            $this->video->update([
                'processing_status' => 'processing_failed',
                'processing_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
