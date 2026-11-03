<?php

namespace App\Services\Admin;

use App\Http\Resources\Blog\BlogCategoryResource;
use App\Models\BlogCategory;
use App\Traits\UploadAble;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BlogCategoryService
{
    use UploadAble;

    public function index()
    {
        try {
            $categories = BlogCategory::withCount(['posts', 'publishedPosts'])
                ->orderBy('order_num', 'asc')
                ->latest('id')
                ->get();

            return responseSuccess(BlogCategoryResource::collection($categories));
        } catch (\Throwable $th) {
            return responseError('Failed to fetch blog categories: '.$th->getMessage(), 500, $th);
        }
    }

    public function show(string $id)
    {
        try {
            $category = BlogCategory::withCount(['posts', 'publishedPosts'])->findOrFail($id);

            return responseSuccess(BlogCategoryResource::make($category));
        } catch (\Throwable $th) {
            return responseError('Blog category not found: '.$th->getMessage(), 404, $th);
        }
    }

    public function store($request)
    {
        try {
            $data = $request->validated();
            if (empty($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            if ($request->hasFile('image')) {
                $data['image'] = $this->uploadFile($request->file('image'), 'blogs/categories');
            }

            $data['is_active'] = $request->boolean('is_active', true);
            $data['order_num'] = $request->input('order_num', 0);

            $category = BlogCategory::create($data);

            return responseSuccess(BlogCategoryResource::make($category), 'Blog category created successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to create blog category: '.$th->getMessage(), 500, $th);
        }
    }

    public function update(BlogCategory $category, $request)
    {
        try {
            $data = $request->validated();
            if (empty($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            if ($request->hasFile('image')) {
                if ($category->image) {
                    $this->deleteFile($category->image);
                }
                $data['image'] = $this->uploadFile($request->file('image'), 'blogs/categories');
            }

            if ($request->has('is_active')) {
                $data['is_active'] = $request->boolean('is_active');
            }
            if ($request->has('order_num')) {
                $data['order_num'] = (int) $request->input('order_num');
            }

            $category->update($data);

            return responseSuccess(BlogCategoryResource::make($category), 'Blog category updated successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to update blog category: '.$th->getMessage(), 500, $th);
        }
    }

    public function toggleStatus(BlogCategory $category, Request $request)
    {
        try {
            $isActive = $request->has('is_active') ? $request->boolean('is_active') : ! $category->is_active;
            $category->update(['is_active' => $isActive]);

            return responseSuccess(BlogCategoryResource::make($category), 'Status updated successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to update status: '.$th->getMessage(), 500, $th);
        }
    }

    public function destroy(BlogCategory $category)
    {
        try {
            if ($category->image) {
                $this->deleteFile($category->image);
            }
            $category->delete();

            return responseSuccess(null, 'Blog category deleted successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to delete blog category: '.$th->getMessage(), 500, $th);
        }
    }
}
