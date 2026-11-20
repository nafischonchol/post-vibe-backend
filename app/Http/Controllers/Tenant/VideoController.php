<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\Video\DownloadAndStoreVideoJob;
use App\Models\Video;
use App\Services\Storage\MediaStorageService;
use App\Services\Video\VideoIngestionService;
use App\Services\Video\VideoScraperService;
use App\Services\Video\YtDlpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Stream or download the video media file.
     */
    public function stream(Request $request, int $id, MediaStorageService $storage)
    {
        $video = Video::findOrFail($id);

        if (! $video->temporary_storage_path) {
            return response()->json([
                'status' => 'error',
                'message' => 'Video media file has not been downloaded yet.',
            ], 404);
        }

        $disk = $storage->getDiskForPath($video->temporary_storage_path);

        if ($disk === 'r2' || $disk === 's3') {
            try {
                $url = $storage->getUrl($video->temporary_storage_path);
                return redirect()->away($url);
            } catch (\Exception $e) {
                // fall through to local stream if redirect is not possible
            }
        }

        if (Storage::disk($disk)->exists($video->temporary_storage_path)) {
            $disposition = $request->query('download') ? 'attachment' : 'inline';
            return Storage::disk($disk)->response(
                $video->temporary_storage_path,
                "{$video->video_uid}.mp4",
                [
                    'Content-Type' => 'video/mp4',
                    'Accept-Ranges' => 'bytes',
                ],
                $disposition
            );
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Media file not found in storage.',
        ], 404);
    }

    /**
     * Download and process the video media file immediately.
     */
    public function downloadNow(
        Request $request,
        int $id,
        MediaStorageService $storage,
        VideoScraperService $scraper,
        YtDlpService $ytDlp
    ): JsonResponse {
        $video = Video::findOrFail($id);
        $url = $request->input('media_url') ?: $video->source_post_url;

        if (! $url) {
            return response()->json([
                'status' => 'error',
                'message' => 'No media URL or source URL available for this video.',
            ], 422);
        }

        try {
            $job = new DownloadAndStoreVideoJob($video, $url);
            $job->handle($storage, $scraper, $ytDlp);
            $video->refresh();

            return response()->json([
                'status' => 'success',
                'message' => 'Video downloaded successfully and is ready to play.',
                'resources' => $video,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to download video: ' . $e->getMessage(),
            ], 422);
        }
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

    public function destroy(int $id, MediaStorageService $storage): JsonResponse
    {
        $video = Video::findOrFail($id);

        if ($video->temporary_storage_path) {
            $storage->deleteTemporaryMedia($video->temporary_storage_path);
        }

        $video->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Video deleted successfully.',
        ]);
    }
}
