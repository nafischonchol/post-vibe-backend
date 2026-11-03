<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\PublishingBatch;
use App\Models\PublishingSchedule;
use App\Models\Tenant;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $totalTenants = Tenant::count();
        $activeTenants = Tenant::where('status', 'active')->count();
        $suspendedTenants = Tenant::where('status', 'suspended')->count();

        $totalVideos = Video::count();
        $readyVideos = Video::where('processing_status', 'ready')->count();
        $processingVideos = Video::whereIn('processing_status', ['discovered', 'downloading', 'stored', 'analyzing', 'generating_metadata'])->count();

        $totalBatches = PublishingBatch::count();
        $publishedBatches = PublishingBatch::where('batch_status', 'published')->count();
        $failedBatches = PublishingBatch::where('batch_status', 'failed')->count();

        $successfulUploads = PublishingSchedule::where('upload_status', 'published')->count();
        $failedUploads = PublishingSchedule::where('upload_status', 'failed')->count();
        $pendingUploads = PublishingSchedule::whereIn('upload_status', ['pending', 'scheduled', 'queued', 'uploading', 'processing'])->count();

        $successRate = ($successfulUploads + $failedUploads) > 0
            ? round(($successfulUploads / ($successfulUploads + $failedUploads)) * 100, 1)
            : 100.0;

        $totalTokens = (int) AiUsageLog::sum('input_tokens') + (int) AiUsageLog::sum('output_tokens');
        $totalAiCost = (float) AiUsageLog::sum('estimated_cost');

        $failedJobs = DB::table('failed_jobs')->count();

        return response()->json([
            'status' => 'success',
            'resources' => [
                'tenants' => [
                    'total' => $totalTenants,
                    'active' => $activeTenants,
                    'suspended' => $suspendedTenants,
                ],
                'videos' => [
                    'total' => $totalVideos,
                    'ready' => $readyVideos,
                    'processing' => $processingVideos,
                ],
                'publishing' => [
                    'total_batches' => $totalBatches,
                    'published_batches' => $publishedBatches,
                    'failed_batches' => $failedBatches,
                    'successful_uploads' => $successfulUploads,
                    'failed_uploads' => $failedUploads,
                    'pending_uploads' => $pendingUploads,
                    'success_rate' => $successRate,
                ],
                'ai' => [
                    'total_tokens' => $totalTokens,
                    'estimated_cost' => $totalAiCost,
                ],
                'system' => [
                    'failed_jobs' => $failedJobs,
                ],
            ],
        ]);
    }
}
