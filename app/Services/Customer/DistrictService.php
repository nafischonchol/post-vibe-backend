<?php

namespace App\Services\Customer;

use App\Http\Resources\DistrictResource;
use App\Models\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DistrictService
{
    /**
     * Retrieve all districts ordered alphabetically by name.
     */
    public function getDistricts(): JsonResponse
    {
        try {
            $cacheKey = 'customer_districts';

            if (Cache::has($cacheKey)) {
                $districts = Cache::get($cacheKey);
            } else {
                $districts = District::orderBy('name', 'asc')->get();
                Cache::put($cacheKey, $districts, now()->addDays(7));
            }

            return responseSuccess(DistrictResource::collection($districts));
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500);
        }
    }
}
