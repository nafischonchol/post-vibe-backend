<?php

namespace App\Services\Customer;

use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BrandService
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $cacheKey = 'brands_index_'.md5($search ?? '');

        if (Cache::has($cacheKey)) {
            $brands = Cache::get($cacheKey);
        } else {
            $query = Brand::where('is_active', true);

            if ($request->filled('search')) {
                $query->where('name', 'like', '%'.$request->search.'%');
            }

            $brands = $query->orderBy('name')->get();

            Cache::put($cacheKey, $brands, now()->addHours(10));
        }

        return responseSuccess(BrandResource::collection($brands));
    }

    public function popularBrands(Request $request)
    {
        $perPage = (int) $request->input('per_page', 8);
        $cacheKey = "popular_brands_{$perPage}";

        if (Cache::has($cacheKey)) {
            $brands = Cache::get($cacheKey);
        } else {
            $brands = Brand::where('is_active', true)
                ->inRandomOrder()
                ->take($perPage)
                ->get();

            Cache::put($cacheKey, $brands, now()->addHours(10));
        }

        return responseSuccess(BrandResource::collection($brands));
    }
}
