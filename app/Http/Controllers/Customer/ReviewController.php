<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreReviewRequest;
use App\Services\Customer\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(
        protected ReviewService $reviewService
    ) {}

    public function index(Request $request, string $slug_url): JsonResponse
    {
        try {
            return $this->reviewService->getProductReviews($request, $slug_url);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function store(StoreReviewRequest $request, string $slug_url): JsonResponse
    {
        try {
            return $this->reviewService->storeReview($request, $slug_url);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function voteHelpful(int $id): JsonResponse
    {
        try {
            return $this->reviewService->voteHelpful($id);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function checkEligibility(string $slug_url): JsonResponse
    {
        try {
            return $this->reviewService->checkEligibility($slug_url);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }
}
