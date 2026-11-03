<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogCuratedLog;
use App\Models\BlogSource;
use App\Services\Admin\BlogCurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogSourceController extends Controller
{
    public function __construct(protected BlogCurationService $curationService)
    {
    }

    /**
     * List all blog sources.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = BlogSource::with(['defaultCategory'])
                ->withCount([
                    'logs as total_curated' => function ($q) {
                        $q->where('status', 'published');
                    },
                    'logs as total_failed' => function ($q) {
                        $q->where('status', 'failed');
                    },
                ]);

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%");
                });
            }

            $sources = $query->latest('id')->paginate($request->integer('per_page', 20));

            return responseSuccess([
                'items' => $sources->items(),
                'pagination' => pagination($sources),
            ]);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch blog sources: ' . $th->getMessage(), 500, $th);
        }
    }

    /**
     * Store a new blog source.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'nullable|string|max:255',
                'url' => 'required|url|max:500',
                'default_category_id' => 'nullable|exists:blog_categories,id',
                'is_active' => 'nullable|boolean',
                'auto_publish' => 'nullable|boolean',
            ]);

            $source = BlogSource::create([
                'name' => $validated['name'] ?? null,
                'url' => rtrim($validated['url'], '/'),
                'default_category_id' => $validated['default_category_id'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'auto_publish' => $validated['auto_publish'] ?? true,
            ]);

            return responseSuccess($source->load('defaultCategory'), 'Blog source added successfully', 201);
        } catch (\Throwable $th) {
            return responseError('Failed to create blog source: ' . $th->getMessage(), 422, $th);
        }
    }

    /**
     * Update an existing blog source.
     */
    public function update(Request $request, BlogSource $blogSource): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'nullable|string|max:255',
                'url' => 'required|url|max:500',
                'default_category_id' => 'nullable|exists:blog_categories,id',
                'is_active' => 'nullable|boolean',
                'auto_publish' => 'nullable|boolean',
            ]);

            $blogSource->update([
                'name' => $validated['name'] ?? $blogSource->name,
                'url' => rtrim($validated['url'], '/'),
                'default_category_id' => $validated['default_category_id'] ?? null,
                'is_active' => $validated['is_active'] ?? $blogSource->is_active,
                'auto_publish' => $validated['auto_publish'] ?? $blogSource->auto_publish,
            ]);

            return responseSuccess($blogSource->load('defaultCategory'), 'Blog source updated successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to update blog source: ' . $th->getMessage(), 422, $th);
        }
    }

    /**
     * Delete a blog source.
     */
    public function destroy(BlogSource $blogSource): JsonResponse
    {
        try {
            $blogSource->delete();
            return responseSuccess(null, 'Blog source deleted successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to delete blog source: ' . $th->getMessage(), 500, $th);
        }
    }

    /**
     * Toggle active status.
     */
    public function toggleStatus(BlogSource $blogSource): JsonResponse
    {
        try {
            $blogSource->is_active = ! $blogSource->is_active;
            $blogSource->save();

            return responseSuccess($blogSource, 'Blog source status updated successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to toggle status: ' . $th->getMessage(), 500, $th);
        }
    }

    /**
     * Get blog curation settings & live stats.
     */
    public function getSettings(): JsonResponse
    {
        try {
            $settings = \App\Models\BlogCurationSetting::getSettings();
            $todayCount = \App\Models\BlogCurationSetting::getTodayPublishedCount();
            $remaining = \App\Models\BlogCurationSetting::getRemainingToday();

            return responseSuccess([
                'daily_limit' => $settings->daily_limit,
                'is_auto_curate_enabled' => $settings->is_auto_curate_enabled,
                'today_published_count' => $todayCount,
                'remaining_today' => $remaining === PHP_INT_MAX ? null : $remaining,
            ], 'Curation settings fetched successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to fetch curation settings: ' . $th->getMessage(), 500, $th);
        }
    }

    /**
     * Update blog curation settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'daily_limit' => 'required|integer|min:0|max:100',
                'is_auto_curate_enabled' => 'nullable|boolean',
            ]);

            $settings = \App\Models\BlogCurationSetting::getSettings();
            $settings->update([
                'daily_limit' => $validated['daily_limit'],
                'is_auto_curate_enabled' => $validated['is_auto_curate_enabled'] ?? $settings->is_auto_curate_enabled,
            ]);

            $todayCount = \App\Models\BlogCurationSetting::getTodayPublishedCount();
            $remaining = \App\Models\BlogCurationSetting::getRemainingToday();

            return responseSuccess([
                'daily_limit' => $settings->daily_limit,
                'is_auto_curate_enabled' => $settings->is_auto_curate_enabled,
                'today_published_count' => $todayCount,
                'remaining_today' => $remaining === PHP_INT_MAX ? null : $remaining,
            ], 'Daily auto-post limit updated successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to update curation settings: ' . $th->getMessage(), 422, $th);
        }
    }

    /**
     * Trigger manual curation immediately.
     */
    public function triggerCuration(Request $request, ?BlogSource $blogSource = null): JsonResponse
    {
        try {
            $sourceId = $blogSource?->id ?: $request->input('source_id');
            $limit = $request->integer('limit', 2);
            $force = $request->boolean('force', false);

            $result = $this->curationService->runCuration($sourceId ? (int) $sourceId : null, $limit, $force);

            return responseSuccess($result, 'Curation process executed successfully');
        } catch (\Throwable $th) {
            return responseError('Curation failed: ' . $th->getMessage(), 500, $th);
        }
    }

    /**
     * List curated logs.
     */
    public function logs(Request $request): JsonResponse
    {
        try {
            $query = BlogCuratedLog::with(['source', 'blogPost:id,title,slug,status,created_at']);

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('source_id')) {
                $query->where('blog_source_id', $request->input('source_id'));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('original_url', 'like', "%{$search}%")
                        ->orWhere('original_title', 'like', "%{$search}%");
                });
            }

            $logs = $query->latest('id')->paginate($request->integer('per_page', 20));

            return responseSuccess([
                'items' => $logs->items(),
                'pagination' => pagination($logs),
            ]);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch curation logs: ' . $th->getMessage(), 500, $th);
        }
    }
}
