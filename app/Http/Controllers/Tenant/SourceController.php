<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Source\StoreVideoSourceRequest;
use App\Models\VideoSource;
use App\Services\Video\VideoIngestionService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    public function index(): JsonResponse
    {
        $sources = VideoSource::withCount('videos')->latest()->get();

        return responseSuccess($sources, 'Video sources retrieved successfully');
    }

    public function store(StoreVideoSourceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $source = VideoSource::create($validated);

        return responseSuccess($source, 'Video source added successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $source = VideoSource::findOrFail($id);
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'in:instagram,facebook,youtube,tiktok,generic'],
            'source_identifier' => ['nullable', 'string'],
            'source_url' => ['nullable', 'url'],
            'status' => ['nullable', 'string', 'in:active,paused'],
            'scan_frequency' => ['nullable', 'integer', 'min:15', 'max:1440'],
        ]);

        $source->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Video source updated.',
            'resources' => $source,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $source = VideoSource::findOrFail($id);
        $source->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Video source deleted.',
        ]);
    }

    /**
     * Scrape a video directly from social URL and queue for syndication pipeline.
     */
    public function scrapeAndIngest(Request $request, VideoIngestionService $ingestionService): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url'],
            'source_id' => ['nullable', 'exists:video_sources,id'],
        ]);

        $tenantId = $request->user()->tenant_id;

        try {
            $video = $ingestionService->ingestFromUrl($tenantId, $validated['url'], $validated['source_id'] ?? null);

            return response()->json([
                'status' => 'success',
                'message' => 'Video scraped and queued for syndication processing.',
                'resources' => $video,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to scrape video: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Instantly scan and scrape videos from a specific video source feed.
     */
    public function scan(int $id, VideoIngestionService $ingestionService): JsonResponse
    {
        $source = VideoSource::findOrFail($id);

        try {
            $result = $ingestionService->scanSource($source, 168);
            $newVideos = is_array($result) && isset($result['ingested']) ? $result['ingested'] : $result;
            $count = count($newVideos);
            $skipped = is_array($result) && isset($result['skipped_count']) ? $result['skipped_count'] : 0;

            $totalCount = $source->fresh()->videos()->count();

            $message = $count > 0
                ? "Successfully scraped {$count} new video(s) from {$source->name}."
                : ($totalCount > 0
                    ? "Scan completed for {$source->name}. All latest videos are already up to date ({$totalCount} video(s) found in your library)."
                    : ($skipped > 0
                        ? "Scan completed for {$source->name}. {$skipped} video(s) older than 7 days were skipped."
                        : ($source->platform === 'facebook' && ! $ingestionService->isDirectVideoUrl($source->source_url)
                            ? "Facebook Page profiles cannot be automatically crawled without a Meta session. Please use 'Scrape Video Link' to instantly ingest reels or set a direct video URL."
                            : "Instant scan completed for {$source->name}. No videos found on this profile.")));

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'resources' => [
                    'source' => $source->fresh()->loadCount('videos'),
                    'new_count' => $count,
                    'skipped_count' => $skipped,
                ],
            ]);
        } catch (Exception $e) {
            $source->update(['last_error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to scrape video source: ' . $e->getMessage(),
            ], 422);
        }
    }
}
