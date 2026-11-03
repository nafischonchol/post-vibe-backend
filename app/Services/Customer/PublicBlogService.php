<?php

namespace App\Services\Customer;

use App\Http\Resources\Blog\BlogCategoryResource;
use App\Http\Resources\Blog\BlogCommentResource;
use App\Http\Resources\Blog\BlogPostDetailResource;
use App\Http\Resources\Blog\BlogPostResource;
use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogPost;
use Illuminate\Http\Request;

class PublicBlogService
{
    public function index(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 9);
            $query = BlogPost::published()->with(['category', 'author'])->withCount('comments');

            if ($request->filled('category')) {
                $categorySlug = $request->input('category');
                $query->whereHas('category', function ($q) use ($categorySlug) {
                    $q->where('slug', $categorySlug)
                        ->orWhere('id', $categorySlug);
                });
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('excerpt', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%");
                });
            }

            if ($request->has('featured') && $request->boolean('featured')) {
                $query->featured();
            }

            $posts = $query->latest('published_at')->paginate($perPage);

            return responseSuccess([
                'items' => BlogPostResource::collection($posts),
                'pagination' => pagination($posts),
            ]);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch blogs: '.$th->getMessage(), 500, $th);
        }
    }

    public function categories()
    {
        try {
            $categories = BlogCategory::where('is_active', true)
                ->withCount('publishedPosts')
                ->orderBy('order_num', 'asc')
                ->get();

            return responseSuccess(BlogCategoryResource::collection($categories));
        } catch (\Throwable $th) {
            return responseError('Failed to fetch blog categories: '.$th->getMessage(), 500, $th);
        }
    }

    public function featuredPosts(Request $request)
    {
        try {
            $limit = (int) $request->input('limit', 4);
            $posts = BlogPost::published()
                ->featured()
                ->with(['category', 'author'])
                ->latest('published_at')
                ->take($limit)
                ->get();

            return responseSuccess(BlogPostResource::collection($posts));
        } catch (\Throwable $th) {
            return responseError('Failed to fetch featured posts: '.$th->getMessage(), 500, $th);
        }
    }

    public function recentPosts(Request $request)
    {
        try {
            $limit = (int) $request->input('limit', 5);
            $posts = BlogPost::published()
                ->with(['category', 'author'])
                ->latest('published_at')
                ->take($limit)
                ->get();

            return responseSuccess(BlogPostResource::collection($posts));
        } catch (\Throwable $th) {
            return responseError('Failed to fetch recent posts: '.$th->getMessage(), 500, $th);
        }
    }

    public function show(string $slug)
    {
        try {
            $id = extractIdFromSlug($slug);
            $post = BlogPost::published()
                ->with(['category', 'author'])
                ->withCount('comments')
                ->findOrFail($id);

            // Increment view counter
            $post->increment('views_count');

            // Find related posts from the same category
            $related = BlogPost::published()
                ->where('id', '!=', $post->id)
                ->when($post->blog_category_id, function ($q) use ($post) {
                    $q->where('blog_category_id', $post->blog_category_id);
                })
                ->with(['category'])
                ->latest('published_at')
                ->take(4)
                ->get();

            // Extract embedded product identifiers from content
            $embeddedProducts = [];
            if (! empty($post->content)) {
                $productIds = [];

                // Match {{product:slug_url}} or [product:slug_url]
                if (preg_match_all('/(?:\{\{product:|\[product:)([a-zA-Z0-9_\-]+)(?:\}\}|\])/i', $post->content, $matches)) {
                    foreach ($matches[1] as $token) {
                        $pId = extractIdFromSlug(trim($token));
                        if ($pId > 0) {
                            $productIds[] = $pId;
                        }
                    }
                }

                // Match direct /products/slug-url links
                if (preg_match_all('/(?:https?:\/\/[^\s"\'<>]+)?\/products\/([a-zA-Z0-9_\-]+)/i', $post->content, $urlMatches)) {
                    foreach ($urlMatches[1] as $token) {
                        $pId = extractIdFromSlug(trim($token));
                        if ($pId > 0) {
                            $productIds[] = $pId;
                        }
                    }
                }

                $productIds = array_unique(array_filter($productIds));

                if (! empty($productIds)) {
                    $products = \App\Models\Product::whereIn('id', $productIds)
                        ->where('status', 'active')
                        ->with(['category', 'brand', 'variants'])
                        ->withAvg(['reviews' => fn ($q) => $q->where('status', 'approved')], 'rating')
                        ->get();

                    $embeddedProducts = \App\Http\Resources\Customer\ProductResource::collection($products)->resolve();
                }
            }

            $resourceData = (new BlogPostDetailResource($post))->resolve();
            $resourceData['related_posts'] = BlogPostResource::collection($related);
            $resourceData['embedded_products'] = $embeddedProducts;

            return responseSuccess($resourceData);
        } catch (\Throwable $th) {
            return responseError('Blog post not found: '.$th->getMessage(), 404, $th);
        }
    }

    public function comments(string $slug, Request $request)
    {
        try {
            $id = extractIdFromSlug($slug);
            $post = BlogPost::findOrFail($id);

            $perPage = (int) $request->input('per_page', 20);
            $comments = BlogComment::where('blog_post_id', $post->id)
                ->where('status', 'approved')
                ->whereNull('parent_id')
                ->with(['client', 'replies' => function ($q) {
                    $q->where('status', 'approved')->with('client')->latest('id');
                }])
                ->latest('id')
                ->paginate($perPage);

            return responseSuccess([
                'items' => BlogCommentResource::collection($comments),
                'pagination' => pagination($comments),
            ]);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch comments: '.$th->getMessage(), 500, $th);
        }
    }

    public function storeComment(string $slug, $request)
    {
        try {
            $id = extractIdFromSlug($slug);
            $post = BlogPost::findOrFail($id);

            if (! $post->allow_comments) {
                return responseError('Comments are disabled for this post.', 403);
            }

            $data = $request->validated();
            $data['blog_post_id'] = $post->id;
            $data['ip_address'] = $request->ip();
            $data['user_agent'] = $request->userAgent();

            // Enforce that client must be logged in to comment
            $client = auth('sanctum')->user() ?? $request->user();
            if (! ($client instanceof \App\Models\Client)) {
                return responseError('Please log in to submit a comment.', 401);
            }

            $data['client_id'] = $client->id;
            $data['author_name'] = !empty($client->name) ? $client->name : $data['author_name'];
            $data['author_email'] = !empty($client->email) ? $client->email : ($data['author_email'] ?? null);

            // Defaults to pending for moderation
            $data['status'] = 'pending';

            $comment = BlogComment::create($data);

            return responseSuccess(
                BlogCommentResource::make($comment),
                'Thank you! Your comment has been submitted and is awaiting approval.',
                201
            );
        } catch (\Throwable $th) {
            return responseError('Failed to submit comment: '.$th->getMessage(), 500, $th);
        }
    }
}
