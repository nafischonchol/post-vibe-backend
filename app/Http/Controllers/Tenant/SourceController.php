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
}
