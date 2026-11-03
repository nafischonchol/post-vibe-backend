<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\SearchLogAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchLogController extends Controller
{
    public function __construct(
        protected SearchLogAdminService $service
    ) {}

    /**
     * Get search analytics summary metrics.
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->service->getSummary($request);
    }

    /**
     * Get trending / most frequent searches.
     */
    public function trending(Request $request): JsonResponse
    {
        return $this->service->getTrending($request);
    }

    /**
     * Get zero-result searches (Audit: Search but not found).
     */
    public function notFound(Request $request): JsonResponse
    {
        return $this->service->getNotFound($request);
    }

    /**
     * Detect potential typos & spelling mistakes.
     */
    public function typos(Request $request): JsonResponse
    {
        return $this->service->getTypoSuggestions($request);
    }

    /**
     * Get paginated raw search log stream.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->service->getLogs($request);
    }

    /**
     * Clear search logs older than X days.
     */
    public function clear(Request $request): JsonResponse
    {
        return $this->service->clearOldLogs($request);
    }
}
