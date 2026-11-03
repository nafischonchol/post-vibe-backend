<?php

namespace App\Services\Admin;

use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Traits\UploadAble;

class BrandService
{
    use UploadAble;

    /**
     * Get all brands for the authenticated vendor.
     */
    public function index()
    {
        $brands = Brand::latest()->get();

        return responseSuccess(BrandResource::collection($brands));
    }

    /**
     * Show a specific brand.
     */
    public function show(string $id)
    {
        $brand = Brand::findOrFail($id);

        return responseSuccess(BrandResource::make($brand));
    }

    /**
     * Store a new brand.
     */
    public function store($request)
    {
        try {
            $data = $request->validated();

            // Handle file upload using the Trait
            if ($request->hasFile('meta_image')) {
                $path = $this->uploadFile($request->file('meta_image'), 'brands');
                $data['meta_image'] = $path;
            }

            // Handle icon upload using the Trait
            if ($request->hasFile('icon')) {
                $path = $this->uploadFile($request->file('icon'), 'brands/icons');
                $data['icon'] = $path;
            }

            // Ensure is_active is boolean
            $data['is_active'] = $request->boolean('is_active', true);

            $brand = Brand::create($data);

            return responseSuccess(BrandResource::make($brand), 'Brand created successfully');
        } catch (\Exception $e) {
            return responseError('Failed to create brand: '.$e->getMessage(), 500);
        }
    }

    /**
     * Update an existing brand.
     */
    public function update(Brand $brand, $request)
    {
        try {
            $data = $request->validated();

            // Handle file update using the Trait
            if ($request->hasFile('meta_image')) {
                // Delete old image using the Trait
                $this->deleteFile($brand->meta_image);

                $path = $this->uploadFile($request->file('meta_image'), 'brands');
                $data['meta_image'] = $path;
            }

            // Handle icon update using the Trait
            if ($request->hasFile('icon')) {
                // Delete old icon using the Trait
                $this->deleteFile($brand->icon);

                $path = $this->uploadFile($request->file('icon'), 'brands/icons');
                $data['icon'] = $path;
            }

            // Ensure is_active is boolean
            $data['is_active'] = $request->boolean('is_active', true);

            $brand->update($data);

            return responseSuccess(BrandResource::make($brand), 'Brand updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to update brand: '.$e->getMessage(), 500);
        }
    }
}
