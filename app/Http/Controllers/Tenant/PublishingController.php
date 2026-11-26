<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Publishing\StorePublishingBatchRequest;
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

        return responseSuccess(
            $batches->items(),
            'Publishing batches retrieved successfully',
            200,
            pagination($batches)
        );
    }

    public function store(StorePublishingBatchRequest $request): JsonResponse
    {
        $validated = $request->validated();

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

            return responseSuccess(
                $batch,
                'Publishing batch created and quotas reserved.',
                201
            );

        } catch (Exception $e) {
            return responseError($e->getMessage(), 422, $e);
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

    public function getSettings(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $setting = \App\Models\TenantPublishingSetting::firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'automatic_publishing_enabled' => false,
                'publishing_interval_minutes' => 60,
                'manual_publishing_enabled' => true,
                'timezone' => $tenant->timezone ?: 'UTC',
            ]
        );

        return responseSuccess($setting, 'Publishing settings retrieved successfully');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'automatic_publishing_enabled' => ['sometimes', 'boolean'],
            'publishing_interval_minutes' => ['sometimes', 'integer', 'min:15', 'max:1440'],
            'manual_publishing_enabled' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'string', 'max:50'],
            'preferred_days' => ['nullable', 'array'],
            'preferred_time_windows' => ['nullable', 'array'],
        ]);

        $tenant = $request->user()->tenant;
        $setting = \App\Models\TenantPublishingSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            $validated
        );

        return responseSuccess($setting, 'Publishing settings updated successfully');
    }
}
