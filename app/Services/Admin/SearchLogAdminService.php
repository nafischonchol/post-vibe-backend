<?php

namespace App\Services\Admin;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SearchLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SearchLogAdminService
{
    /**
     * Get summary metrics for the search dashboard.
     */
    public function getSummary(Request $request): JsonResponse
    {
        $days = (int) ($request->input('days') ?? 7);
        $startDate = now()->subDays($days)->startOfDay();

        $baseQuery = SearchLog::where('created_at', '>=', $startDate)
            ->whereRaw('LENGTH(normalized_query) >= 2');

        $totalSearches = (clone $baseQuery)->count();
        $uniqueQueries = (clone $baseQuery)->distinct('normalized_query')->count('normalized_query');
        $zeroResultSearches = (clone $baseQuery)->where('results_count', 0)->count();
        $totalClicks = (clone $baseQuery)->whereNotNull('clicked_product_id')->count();
        $uniqueVisitors = (clone $baseQuery)->whereNotNull('visitor_id')->distinct('visitor_id')->count('visitor_id');

        $zeroResultsRate = $totalSearches > 0 ? round(($zeroResultSearches / $totalSearches) * 100, 1) : 0;
        $ctr = $totalSearches > 0 ? round(($totalClicks / $totalSearches) * 100, 1) : 0;

        // Daily trend for the chart
        $dailyTrend = SearchLog::where('created_at', '>=', $startDate)
            ->whereRaw('LENGTH(normalized_query) >= 2')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as total_searches'),
                DB::raw('SUM(CASE WHEN results_count = 0 THEN 1 ELSE 0 END) as zero_results'),
                DB::raw('SUM(CASE WHEN clicked_product_id IS NOT NULL THEN 1 ELSE 0 END) as clicks')
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'asc')
            ->get();

        return responseSuccess([
            'period_days' => $days,
            'total_searches' => $totalSearches,
            'unique_queries' => $uniqueQueries,
            'zero_result_searches' => $zeroResultSearches,
            'zero_results_rate' => $zeroResultsRate,
            'total_clicks' => $totalClicks,
            'ctr' => $ctr,
            'unique_visitors' => $uniqueVisitors,
            'daily_trend' => $dailyTrend,
        ]);
    }

    /**
     * Get trending / most frequent search queries.
     */
    public function getTrending(Request $request): JsonResponse
    {
        $days = (int) ($request->input('days') ?? 7);
        $limit = min((int) ($request->input('limit') ?? 20), 100);
        $startDate = now()->subDays($days)->startOfDay();

        $trending = SearchLog::where('created_at', '>=', $startDate)
            ->whereRaw('LENGTH(normalized_query) >= 2')
            ->select(
                'normalized_query',
                DB::raw('MAX(query) as sample_query'),
                DB::raw('COUNT(*) as search_count'),
                DB::raw('ROUND(AVG(results_count), 1) as avg_results'),
                DB::raw('SUM(CASE WHEN results_count = 0 THEN 1 ELSE 0 END) as zero_results_count'),
                DB::raw('SUM(CASE WHEN clicked_product_id IS NOT NULL THEN 1 ELSE 0 END) as clicks_count'),
                DB::raw('COUNT(DISTINCT visitor_id) as unique_visitors'),
                DB::raw('MAX(created_at) as last_searched_at')
            )
            ->groupBy('normalized_query')
            ->orderByDesc('search_count')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                $item->ctr_percentage = $item->search_count > 0
                    ? round(($item->clicks_count / $item->search_count) * 100, 1)
                    : 0;

                return $item;
            });

        return responseSuccess($trending);
    }

    /**
     * Get queries that returned 0 results (Audit: Search but not found).
     */
    public function getNotFound(Request $request): JsonResponse
    {
        $days = (int) ($request->input('days') ?? 30);
        $limit = min((int) ($request->input('limit') ?? 50), 200);
        $startDate = now()->subDays($days)->startOfDay();

        $notFoundQueries = SearchLog::where('created_at', '>=', $startDate)
            ->whereRaw('LENGTH(normalized_query) >= 2')
            ->where('results_count', 0)
            ->select(
                'normalized_query',
                DB::raw('MAX(query) as sample_query'),
                DB::raw('COUNT(*) as missed_count'),
                DB::raw('COUNT(DISTINCT visitor_id) as unique_visitors'),
                DB::raw('MAX(created_at) as last_searched_at')
            )
            ->groupBy('normalized_query')
            ->orderByDesc('missed_count')
            ->limit($limit)
            ->get();

        return responseSuccess($notFoundQueries);
    }

    /**
     * Analyze zero-result queries to detect potential typos / spelling mistakes.
     */
    public function getTypoSuggestions(Request $request): JsonResponse
    {
        $days = (int) ($request->input('days') ?? 30);
        $startDate = now()->subDays($days)->startOfDay();

        // Get top 100 zero-result queries
        $zeroQueries = SearchLog::where('created_at', '>=', $startDate)
            ->where('results_count', 0)
            ->select('normalized_query', DB::raw('COUNT(*) as count'))
            ->groupBy('normalized_query')
            ->having('count', '>=', 1)
            ->orderByDesc('count')
            ->limit(100)
            ->pluck('count', 'normalized_query');

        if ($zeroQueries->isEmpty()) {
            return responseSuccess([]);
        }

        // Cache dictionary of existing active product names, categories, and brands
        $dictionary = $this->getCatalogDictionary();

        $suggestions = [];

        foreach ($zeroQueries as $query => $count) {
            $queryLen = mb_strlen($query);
            if ($queryLen < 3) {
                continue;
            }

            $bestMatch = null;
            $shortestDist = 999;
            $bestType = null;
            $bestId = null;

            foreach ($dictionary as $target) {
                $targetName = $target['normalized_name'];
                $dist = levenshtein($query, $targetName);

                // Allow max 2 edit distances, or 3 for long words (>= 8 chars)
                $maxDist = $queryLen >= 8 ? 3 : 2;

                if ($dist > 0 && $dist <= $maxDist && $dist < $shortestDist) {
                    $shortestDist = $dist;
                    $bestMatch = $target['name'];
                    $bestType = $target['type'];
                    $bestId = $target['id'];
                }
            }

            if ($bestMatch !== null) {
                $similarity = 0.0;
                similar_text($query, mb_strtolower($bestMatch), $similarity);

                $suggestions[] = [
                    'typo_query' => $query,
                    'search_count' => $count,
                    'suggested_target' => $bestMatch,
                    'target_type' => $bestType,
                    'target_id' => $bestId,
                    'distance' => $shortestDist,
                    'similarity_score' => round($similarity, 1),
                ];
            }
        }

        // Sort by search count desc
        usort($suggestions, fn ($a, $b) => $b['search_count'] <=> $a['search_count']);

        return responseSuccess(array_slice($suggestions, 0, 50));
    }

    /**
     * Get paginated raw search log stream with filtering.
     */
    public function getLogs(Request $request): JsonResponse
    {
        $perPage = min((int) ($request->input('per_page') ?? 20), 100);

        $query = SearchLog::with([
            'client:id,name,phone,email',
            'clickedProduct:id,name,bangla_name,slug,image',
        ]);

        if ($request->filled('search_text')) {
            $term = trim((string) $request->input('search_text'));
            $query->where(function ($q) use ($term) {
                $q->where('query', 'like', "%{$term}%")
                    ->orWhere('normalized_query', 'like', "%{$term}%")
                    ->orWhere('visitor_id', 'like', "%{$term}%")
                    ->orWhere('ip_address', 'like', "%{$term}%");
            });
        }

        if ($request->filled('only_zero_results') && filter_var($request->input('only_zero_results'), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('results_count', 0);
        }

        if ($request->filled('source')) {
            $query->where('source', $request->input('source'));
        }

        if ($request->filled('visitor_id')) {
            $query->where('visitor_id', $request->input('visitor_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('date_from'))->startOfDay());
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('date_to'))->endOfDay());
        }

        $logs = $query->latest('id')->paginate($perPage);

        return responseSuccess([
            'items' => $logs->items(),
            'pagination' => pagination($logs),
        ]);
    }

    /**
     * Clear search logs older than given days.
     */
    public function clearOldLogs(Request $request): JsonResponse
    {
        $days = (int) ($request->input('days') ?? 60);
        $days = max(7, $days); // Minimum 7 days safety buffer

        $deletedCount = SearchLog::where('created_at', '<', now()->subDays($days))->delete();

        return responseSuccess([
            'deleted_count' => $deletedCount,
            'retained_days' => $days,
        ], "Successfully purged {$deletedCount} search logs older than {$days} days.");
    }

    /**
     * Helper to load catalog dictionary for typo comparison.
     */
    private function getCatalogDictionary(): array
    {
        $products = Product::where('status', 'active')
            ->select('id', 'name')
            ->limit(500)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'normalized_name' => mb_strtolower(trim($p->name)),
                'type' => 'product',
            ])
            ->toArray();

        $categories = Category::where('is_active', true)
            ->select('id', 'name')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'normalized_name' => mb_strtolower(trim($c->name)),
                'type' => 'category',
            ])
            ->toArray();

        $brands = Brand::where('is_active', true)
            ->select('id', 'name')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'normalized_name' => mb_strtolower(trim($b->name)),
                'type' => 'brand',
            ])
            ->toArray();

        return array_merge($products, $categories, $brands);
    }
}
