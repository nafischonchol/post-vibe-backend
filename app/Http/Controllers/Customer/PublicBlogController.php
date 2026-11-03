<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Blog\StoreBlogCommentRequest;
use App\Services\Customer\PublicBlogService;
use Illuminate\Http\Request;

class PublicBlogController extends Controller
{
    public function __construct(public PublicBlogService $blogService) {}

    public function index(Request $request)
    {
        return $this->blogService->index($request);
    }

    public function categories()
    {
        return $this->blogService->categories();
    }

    public function featured(Request $request)
    {
        return $this->blogService->featuredPosts($request);
    }

    public function recent(Request $request)
    {
        return $this->blogService->recentPosts($request);
    }

    public function show(string $slug)
    {
        return $this->blogService->show($slug);
    }

    public function comments(string $slug, Request $request)
    {
        return $this->blogService->comments($slug, $request);
    }

    public function storeComment(string $slug, StoreBlogCommentRequest $request)
    {
        return $this->blogService->storeComment($slug, $request);
    }
}
