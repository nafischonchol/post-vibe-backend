<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\Admin\Brand\UpdateBrandRequest;
use App\Models\Brand;
use App\Services\Admin\BrandService;

class BrandController extends Controller
{
    public function __construct(public BrandService $brandService) {}

    public function index()
    {
        return $this->brandService->index();
    }

    public function store(StoreBrandRequest $request)
    {
        return $this->brandService->store($request);
    }

    public function show(Brand $brand)
    {
        return $this->brandService->show($brand->id);
    }

    public function update(UpdateBrandRequest $request, Brand $brand)
    {
        return $this->brandService->update($brand, $request);
    }
}
