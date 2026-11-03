<?php

namespace App\Services\Customer;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Support\Facades\Cache;

class CategoryService
{
    public function popularCategories()
    {
        $cacheKey = 'popular_categories';

        if (Cache::has($cacheKey)) {
            $categories = Cache::get($cacheKey);
        } else {
            $categories = Category::where('is_active', true)
                ->inRandomOrder()
                ->select('id', 'name', 'slug', 'icon')
                ->take(20)
                ->get();

            Cache::put($cacheKey, $categories, now()->addHours(10));
        }

        return responseSuccess(CategoryResource::collection($categories));
    }

    public function menuCategories()
    {
        $cacheKey = 'header_menu_categories';

        if (Cache::has($cacheKey)) {
            $categories = Cache::get($cacheKey);
        } else {
            $categories = Category::where('is_active', true)
                ->where('is_header_menu', true)
                ->select('id', 'name', 'slug', 'icon', 'is_header_menu')
                ->get();

            Cache::put($cacheKey, $categories, now()->addHours(24));
        }

        return responseSuccess(CategoryResource::collection($categories));
    }
}
