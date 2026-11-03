<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\DistrictService;
use Illuminate\Http\JsonResponse;

class DistrictController extends Controller
{
    public function __construct(
        protected DistrictService $districtService
    ) {}

    public function index(): JsonResponse
    {
        return $this->districtService->getDistricts();
    }
}
