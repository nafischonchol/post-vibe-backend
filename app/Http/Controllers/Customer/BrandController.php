<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\BrandService;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function __construct(public BrandService $brand_service) {}

    public function index(Request $request)
    {
        try {
            return $this->brand_service->index($request);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function popularBrands(Request $request)
    {
        try {
            return $this->brand_service->popularBrands($request);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }
}
