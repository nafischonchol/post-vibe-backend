<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PublishingBatch;
use App\Models\Video;
use App\Services\Publishing\PublishingBatchService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublishingController extends Controller
{
    public function __construct(
        protected PublishingBatchService $batchService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $batches = PublishingBatch::with(['video', 'schedules.platformAccount', 'schedules.metadata'])
            ->latest()
            ->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'resources' => $batches->items(),
            'meta' => [
                'current_page' => $batches->currentPage(),
                'last_page' => $batches->lastPage(),
                'per_page' => $batches->perPage(),
                'total' => $batches->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'video_id' => ['required', 'exists:videos,id'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.platform' => ['required', 'string', 'in:youtube,tiktok,facebook'],
            'targets.*.account_id' => ['nullable', 'exists:tenant_platform_accounts,id'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $video = Video::findOrFail($validated['video_id']);
        $tenant = $request->user()->tenant;
        $scheduledAt = ! empty($validated['scheduled_at']) ? Carbon::parse($validated['scheduled_at']) : now();

        try {
            $batch = $this->batchService->createBatch(
                $tenant,
                $video,
                $validated['targets'],
                $scheduledAt,
                'manual',
                $request->user()
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Publishing batch created and quotas reserved.',
                'resources' => $batch,
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(int $id): JsonResponse
    {
        $batch = PublishingBatch::with(['video', 'schedules.platformAccount', 'schedules.metadata', 'quotaReservation.items'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'resources' => $batch,
        ]);
    }

    public function cancel(int $id): JsonResponse
    {
        $batch = PublishingBatch::findOrFail($id);
        $batch = $this->batchService->cancelBatch($batch);

        return response()->json([
            'status' => 'success',
            'message' => 'Publishing batch cancelled and reserved quotas released.',
            'resources' => $batch,
        ]);
    }
}
