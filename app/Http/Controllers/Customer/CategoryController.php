<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\CategoryService;

class CategoryController extends Controller
{
    public function __construct(public CategoryService $category_service) {}

    public function popularCategories()
    {
        try {
            return $this->category_service->popularCategories();
        } catch (\Throwable $th) {
            return responseError('Failed to fetch popular categories: '.$th->getMessage(), 500);
        }
    }

    public function menuCategories()
    {
        try {
            return $this->category_service->menuCategories();
        } catch (\Throwable $th) {
            return responseError('Failed to fetch menu categories: '.$th->getMessage(), 500);
        }
    }

    public function categoryLanding(string $slug, \App\Services\Customer\CategoryLandingService $landingService)
    {
        try {
            return $landingService->getCategoryLandingData($slug);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch category landing data: '.$th->getMessage(), 500);
        }
    }
}
