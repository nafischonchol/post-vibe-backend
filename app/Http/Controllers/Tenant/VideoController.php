<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\Video\VideoIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoController extends Controller
{
    public function __construct(
        protected VideoIngestionService $ingestionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Video::with(['source', 'platformMetadata']);

        if ($request->filled('status')) {
            $query->where('processing_status', $request->query('status'));
        }

        if ($request->filled('source_id')) {
            $query->where('source_id', $request->query('source_id'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where('original_caption', 'like', "%{$search}%");
        }

        $videos = $query->latest()->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'resources' => $videos->items(),
            'meta' => [
                'current_page' => $videos->currentPage(),
                'last_page' => $videos->lastPage(),
                'per_page' => $videos->perPage(),
                'total' => $videos->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $video = Video::with(['source', 'analysisResult', 'platformMetadata'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'resources' => $video,
        ]);
    }

    public function reprocess(Request $request, int $id): JsonResponse
    {
        $video = Video::findOrFail($id);
        $video = $this->ingestionService->reprocessVideo($video, $request->input('media_url'));

        return response()->json([
            'status' => 'success',
            'message' => 'Video re-queued for processing.',
            'resources' => $video,
        ]);
    }
}
