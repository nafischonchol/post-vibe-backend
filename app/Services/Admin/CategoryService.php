<?php

namespace App\Services\Admin;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Traits\UploadAble;
use Illuminate\Support\Str;

class CategoryService
{
    use UploadAble;

    public function index()
    {
        $categories = Category::with('parent')->latest()->get();

        return responseSuccess(CategoryResource::collection($categories));
    }

    public function show(string $id)
    {
        $category = Category::findOrFail($id);

        return responseSuccess(CategoryResource::make($category));
    }

    public function store($request)
    {
        try {
            $data = $request->validated();
            $data['slug'] = Str::slug($data['name']);

            // Handle file upload using the Trait
            if ($request->hasFile('meta_image')) {
                $path = $this->uploadFile($request->file('meta_image'), 'categories');
                $data['meta_image'] = $path;
            }

            // Handle icon upload using the Trait
            if ($request->hasFile('icon')) {
                $path = $this->uploadFile($request->file('icon'), 'categories/icons');
                $data['icon'] = $path;
            }

            // Ensure is_active and is_header_menu are booleans
            $data['is_active'] = $request->boolean('is_active', true);
            $data['is_header_menu'] = $request->boolean('is_header_menu', false);

            $category = Category::create($data);

            return responseSuccess(CategoryResource::make($category), 'Category created successfully');
        } catch (\Exception $e) {
            return responseError('Failed to create category: '.$e->getMessage(), 500);
        }
    }

    public function update(Category $category, $request)
    {
        try {
            $data = $request->validated();
            $data['slug'] = Str::slug($data['name']);

            // Handle file update using the Trait
            if ($request->hasFile('meta_image')) {
                // Delete old image using the Trait
                $this->deleteFile($category->meta_image);

                $path = $this->uploadFile($request->file('meta_image'), 'categories');
                $data['meta_image'] = $path;
            }

            // Handle icon update using the Trait
            if ($request->hasFile('icon')) {
                // Delete old icon using the Trait
                $this->deleteFile($category->icon);

                $path = $this->uploadFile($request->file('icon'), 'categories/icons');
                $data['icon'] = $path;
            }

            // Ensure is_active and is_header_menu are booleans
            if ($request->has('is_active')) {
                $data['is_active'] = $request->boolean('is_active');
            }
            if ($request->has('is_header_menu')) {
                $data['is_header_menu'] = $request->boolean('is_header_menu');
            }

            $category->update($data);

            return responseSuccess(CategoryResource::make($category), 'Category updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to update category: '.$e->getMessage(), 500);
        }
    }

    public function toggleHeaderMenu(Category $category, $request)
    {
        try {
            $isHeaderMenu = $request->has('is_header_menu')
                ? $request->boolean('is_header_menu')
                : ! $category->is_header_menu;

            $category->update([
                'is_header_menu' => $isHeaderMenu,
            ]);

            return responseSuccess(
                CategoryResource::make($category),
                $category->is_header_menu ? 'Category added to Header Menu' : 'Category removed from Header Menu'
            );
        } catch (\Exception $e) {
            return responseError('Failed to toggle header menu status: '.$e->getMessage(), 500);
        }
    }
}
