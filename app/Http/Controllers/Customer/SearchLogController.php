<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\TrackSearchClickRequest;
use App\Services\Customer\SearchLogCustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchLogController extends Controller
{
    public function __construct(
        protected SearchLogCustomerService $service
    ) {}

    /**
     * Track click on a search result.
     */
    public function trackClick(TrackSearchClickRequest $request): JsonResponse
    {
        return $this->service->trackClick($request);
    }

    /**
     * Get public trending keywords.
     */
    public function trendingKeywords(Request $request): JsonResponse
    {
        return $this->service->getPublicTrendingKeywords($request);
    }
}
