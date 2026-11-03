<?php

namespace App\Jobs\AI;

use App\Models\SystemSetting;
use App\Models\Video;
use App\Models\VideoAnalysisResult;
use App\Services\AI\PlatformMetadataService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessVideoAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public Video $video
    ) {}

    public function handle(PlatformMetadataService $metadataService): void
    {
        try {
            $analysis = null;
            $analysisEnabled = (bool) SystemSetting::get('video_content_analysis_enabled', false);

            // 1. Content Analysis (Optional, Admin-controlled)
            if ($analysisEnabled) {
                $this->video->update(['processing_status' => 'analyzing']);

                // Check Cache
                $analysis = VideoAnalysisResult::where('video_id', $this->video->id)->first();
                if (! $analysis) {
                    $analysis = VideoAnalysisResult::create([
                        'tenant_id' => $this->video->tenant_id,
                        'video_id' => $this->video->id,
                        'provider' => 'openrouter',
                        'model' => SystemSetting::get('default_ai_model', 'google/gemini-2.5-flash'),
                        'analysis_version' => 'v1',
                        'summary' => $this->video->original_caption ?: 'Analyzed video content.',
                        'status' => 'completed',
                    ]);
                }
            }

            // 2. Platform-specific Metadata Generation
            $this->video->update(['processing_status' => 'generating_metadata']);
            $metadataService->generateBatchedMetadata($this->video, $analysis);

            // 3. Mark video as Ready for Publishing
            $this->video->update([
                'processing_status' => 'ready',
                'processing_error' => null,
            ]);

        } catch (Exception $e) {
            Log::error("ProcessVideoAiJob failed for video ID {$this->video->id}: " . $e->getMessage());

            $this->video->update([
                'processing_status' => 'processing_failed',
                'processing_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
