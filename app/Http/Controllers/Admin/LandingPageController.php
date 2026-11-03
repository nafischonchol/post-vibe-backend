<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\LandingPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandingPageController extends Controller
{
    public function __construct(public LandingPageService $landingPageService) {}

    /**
     * List all categories with landing page status.
     */
    public function categories(Request $request): JsonResponse
    {
        return $this->landingPageService->getCategoryLandingPages($request);
    }

    /**
     * Get single category landing page config.
     */
    public function categoryDetail(int $id): JsonResponse
    {
        return $this->landingPageService->getCategoryLandingDetail($id);
    }

    /**
     * Update category landing page config.
     */
    public function updateCategory(Request $request, int $id): JsonResponse
    {
        return $this->landingPageService->updateCategoryLanding($id, $request->all());
    }

    /**
     * Reset category landing page to default.
     */
    public function resetCategory(int $id): JsonResponse
    {
        return $this->landingPageService->resetCategoryLanding($id);
    }

    /**
     * List products with landing page status.
     */
    public function products(Request $request): JsonResponse
    {
        return $this->landingPageService->getProductLandingPages($request);
    }

    /**
     * Get single product landing page config.
     */
    public function productDetail(int $id): JsonResponse
    {
        return $this->landingPageService->getProductLandingDetail($id);
    }

    /**
     * Update product landing page config.
     */
    public function updateProduct(Request $request, int $id): JsonResponse
    {
        return $this->landingPageService->updateProductLanding($id, $request->all());
    }

    /**
     * Reset product landing page to default.
     */
    public function resetProduct(int $id): JsonResponse
    {
        return $this->landingPageService->resetProductLanding($id);
    }
}
