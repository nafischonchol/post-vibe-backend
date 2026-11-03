<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Blog\StoreBlogCategoryRequest;
use App\Http\Requests\Admin\Blog\UpdateBlogCategoryRequest;
use App\Models\BlogCategory;
use App\Services\Admin\BlogCategoryService;
use Illuminate\Http\Request;

class BlogCategoryController extends Controller
{
    public function __construct(public BlogCategoryService $categoryService) {}

    public function index()
    {
        return $this->categoryService->index();
    }

    public function show(string $id)
    {
        return $this->categoryService->show($id);
    }

    public function store(StoreBlogCategoryRequest $request)
    {
        return $this->categoryService->store($request);
    }

    public function update(UpdateBlogCategoryRequest $request, BlogCategory $blog_category)
    {
        return $this->categoryService->update($blog_category, $request);
    }

    public function toggleStatus(BlogCategory $blog_category, Request $request)
    {
        return $this->categoryService->toggleStatus($blog_category, $request);
    }

    public function destroy(BlogCategory $blog_category)
    {
        return $this->categoryService->destroy($blog_category);
    }
}
