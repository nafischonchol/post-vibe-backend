<?php

namespace App\Services\Customer;

use App\Models\Product;
use App\Models\SearchLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SearchLogCustomerService
{
    /**
     * Track when a customer clicks a product from search results.
     */
    public function trackClick(Request $request): JsonResponse
    {
        $productId = (int) $request->input('product_id');
        $searchLogId = $request->input('search_log_id');
        $rawQuery = trim((string) $request->input('query'));
        $visitorId = $request->header('X-Visitor-Id') ?: ($request->input('visitor_id') ?: $request->input('guest_token') ?: $request->cookie('visitor_id'));
        $clientId = auth('sanctum')->id();

        // 1. Direct update by ID if available
        if ($searchLogId) {
            $log = SearchLog::find($searchLogId);
            if ($log) {
                $log->update([
                    'clicked_product_id' => $productId,
                    'clicked_at' => now(),
                ]);

                return responseSuccess(['tracked' => true, 'log_id' => $log->id]);
            }
        }

        // 2. Fallback: match most recent log for this visitor / query
        $normalized = $rawQuery !== '' ? mb_strtolower(preg_replace('/\s+/u', ' ', $rawQuery)) : null;

        $log = null;
        if ($normalized) {
            $queryBuilder = SearchLog::where('normalized_query', $normalized)
                ->whereNull('clicked_product_id')
                ->where('created_at', '>=', now()->subMinutes(30));

            if ($visitorId) {
                $queryBuilder->where('visitor_id', $visitorId);
            }

            $log = $queryBuilder->latest('id')->first();
        }

        if ($log) {
            $log->update([
                'clicked_product_id' => $productId,
                'clicked_at' => now(),
            ]);

            return responseSuccess(['tracked' => true, 'log_id' => $log->id]);
        }

        // 3. If no prior log exists but query was passed, create one with click
        if ($rawQuery !== '') {
            $newLog = SearchLog::create([
                'query' => mb_substr($rawQuery, 0, 255),
                'normalized_query' => mb_substr($normalized, 0, 255),
                'results_count' => 1,
                'source' => 'autocomplete',
                'visitor_id' => $visitorId,
                'client_id' => $clientId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'clicked_product_id' => $productId,
                'clicked_at' => now(),
            ]);

            return responseSuccess(['tracked' => true, 'log_id' => $newLog->id]);
        }

        return responseSuccess(['tracked' => false]);
    }

    /**
     * Get top trending searches for frontend chips/pills. Cached for 1 hour.
     */
    public function getPublicTrendingKeywords(Request $request): JsonResponse
    {
        $limit = min((int) ($request->input('limit') ?? 8), 20);

        $trending = Cache::remember('customer_trending_searches_'.$limit, now()->addHour(), function () use ($limit) {
            return SearchLog::where('created_at', '>=', now()->subDays(7))
                ->where('results_count', '>', 0)
                ->select('normalized_query', DB::raw('COUNT(*) as total'))
                ->groupBy('normalized_query')
                ->orderByDesc('total')
                ->limit($limit)
                ->pluck('normalized_query');
        });

        return responseSuccess($trending);
    }
}
