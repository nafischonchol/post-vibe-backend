<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ProductFilterRequest;
use App\Services\Customer\ProductService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(public ProductService $product_service) {}

    public function filterProducts(ProductFilterRequest $request)
    {
        try {
            return $this->product_service->filterProducts($request);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function searchSuggestions(Request $request)
    {
        try {
            return $this->product_service->searchSuggestions($request);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function getFilterableData()
    {
        try {
            return $this->product_service->getFilterableData();
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function popularProducts(Request $request)
    {
        try {
            return $this->product_service->popularProducts($request);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function bestSellingProducts(Request $request)
    {
        try {
            return $this->product_service->popularProducts($request);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function newArrivalProducts(Request $request)
    {
        try {
            return $this->product_service->newArrivalProducts($request);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function show($slug_url)
    {
        try {
            return $this->product_service->show($slug_url);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function brandProducts(Request $request, $brand_id)
    {
        try {
            return $this->product_service->brandProducts($request, $brand_id);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function categoryProducts(Request $request, $category_id)
    {
        try {
            return $this->product_service->categoryProducts($request, $category_id);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }
}
