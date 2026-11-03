<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Blog\StoreBlogPostRequest;
use App\Http\Requests\Admin\Blog\UpdateBlogPostRequest;
use App\Models\BlogPost;
use App\Services\Admin\BlogPostService;
use Illuminate\Http\Request;

class BlogPostController extends Controller
{
    public function __construct(public BlogPostService $postService) {}

    public function index(Request $request)
    {
        return $this->postService->index($request);
    }

    public function show(string $id)
    {
        return $this->postService->show($id);
    }

    public function store(StoreBlogPostRequest $request)
    {
        return $this->postService->store($request);
    }

    public function update(UpdateBlogPostRequest $request, BlogPost $blog_post)
    {
        return $this->postService->update($blog_post, $request);
    }

    public function toggleStatus(BlogPost $blog_post, Request $request)
    {
        return $this->postService->toggleStatus($blog_post, $request);
    }

    public function toggleFeatured(BlogPost $blog_post, Request $request)
    {
        return $this->postService->toggleFeatured($blog_post, $request);
    }

    public function uploadImage(Request $request)
    {
        return $this->postService->uploadContentImage($request);
    }

    public function generateAi(Request $request)
    {
        return $this->postService->generateAi($request);
    }

    public function destroy(BlogPost $blog_post)
    {
        return $this->postService->destroy($blog_post);
    }
}
