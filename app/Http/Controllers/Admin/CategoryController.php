<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Category\StoreCategoryRequest;
use App\Http\Requests\Admin\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\Admin\CategoryService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(public CategoryService $categoryService) {}

    public function index()
    {
        return $this->categoryService->index();
    }

    public function store(StoreCategoryRequest $request)
    {
        return $this->categoryService->store($request);
    }

    public function show(Category $category)
    {
        return $this->categoryService->show($category->id);
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        return $this->categoryService->update($category, $request);
    }

    public function toggleHeaderMenu(Request $request, Category $category)
    {
        return $this->categoryService->toggleHeaderMenu($category, $request);
    }
}
