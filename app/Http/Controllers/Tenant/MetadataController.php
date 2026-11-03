<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Models\VideoPlatformMetadata;
use App\Services\AI\PlatformMetadataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetadataController extends Controller
{
    public function __construct(
        protected PlatformMetadataService $metadataService
    ) {}

    public function show(int $videoId): JsonResponse
    {
        $video = Video::findOrFail($videoId);
        $metadata = VideoPlatformMetadata::where('video_id', $video->id)->get()->keyBy('platform');

        return response()->json([
            'status' => 'success',
            'resources' => $metadata,
        ]);
    }

    public function update(Request $request, int $videoId, string $platform): JsonResponse
    {
        $video = Video::findOrFail($videoId);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'tags' => ['nullable', 'array'],
            'hashtags' => ['nullable', 'array'],
        ]);

        $metadata = VideoPlatformMetadata::updateOrCreate(
            [
                'video_id' => $video->id,
                'platform' => $platform,
            ],
            [
                'tenant_id' => $video->tenant_id,
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'caption' => $validated['caption'] ?? null,
                'tags' => $validated['tags'] ?? [],
                'hashtags' => $validated['hashtags'] ?? [],
                'is_manually_edited' => true,
                'generation_status' => 'generated',
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => "Metadata for {$platform} saved.",
            'resources' => $metadata,
        ]);
    }

    public function regenerate(int $videoId, ?string $platform = null): JsonResponse
    {
        $video = Video::with('analysisResult')->findOrFail($videoId);

        $results = $this->metadataService->generateBatchedMetadata($video, $video->analysisResult);

        return response()->json([
            'status' => 'success',
            'message' => 'AI metadata regenerated successfully.',
            'resources' => $results,
        ]);
    }
}
