<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\MergeWishlistRequest;
use App\Http\Requests\Customer\WishlistToggleRequest;
use App\Services\Customer\WishlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function __construct(
        protected WishlistService $wishlistService
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->wishlistService->getWishlist($request);
    }

    public function toggle(WishlistToggleRequest $request): JsonResponse
    {
        return $this->wishlistService->toggleWishlist($request);
    }

    public function merge(MergeWishlistRequest $request): JsonResponse
    {
        return $this->wishlistService->mergeGuestWishlist($request);
    }
}
