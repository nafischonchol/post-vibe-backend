<?php

namespace App\Services\Admin;

use App\Contracts\AiServiceInterface;
use App\Http\Resources\Blog\BlogPostDetailResource;
use App\Http\Resources\Blog\BlogPostResource;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Traits\UploadAble;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogPostService
{
    use UploadAble;

    public function __construct(public ?AiServiceInterface $aiService = null)
    {
        $this->aiService = $aiService ?? app(AiServiceInterface::class);
    }

    public function index(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 15);
            $query = BlogPost::with(['category', 'author'])->withCount('comments');

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('excerpt', 'like', "%{$search}%");
                });
            }

            if ($request->filled('category_id')) {
                $query->where('blog_category_id', $request->input('category_id'));
            }

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->has('is_featured') && $request->input('is_featured') !== '') {
                $query->where('is_featured', $request->boolean('is_featured'));
            }

            $posts = $query->latest('id')->paginate($perPage);

            return responseSuccess([
                'items' => BlogPostResource::collection($posts),
                'pagination' => pagination($posts),
            ]);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch blog posts: '.$th->getMessage(), 500, $th);
        }
    }

    public function show(string $id)
    {
        try {
            $post = BlogPost::with(['category', 'author'])->withCount('comments')->findOrFail($id);

            return responseSuccess(BlogPostDetailResource::make($post));
        } catch (\Throwable $th) {
            return responseError('Blog post not found: '.$th->getMessage(), 404, $th);
        }
    }

    public function store($request)
    {
        try {
            $data = $request->validated();

            $adminId = auth('sanctum')->id() ?? \App\Models\Admin::first()?->id ?? 1;
            $data['admin_id'] = $adminId;

            if (empty($data['slug'])) {
                $data['slug'] = Str::slug($data['title']);
            }

            // Ensure unique slug
            $originalSlug = $data['slug'];
            $count = 1;
            while (BlogPost::where('slug', $data['slug'])->exists()) {
                $data['slug'] = $originalSlug . '-' . $count++;
            }

            if ($request->hasFile('featured_image')) {
                $data['featured_image'] = $this->uploadFile($request->file('featured_image'), 'blogs/posts');
            }

            if ($data['status'] === 'published' && empty($data['published_at'])) {
                $data['published_at'] = now();
            }

            $data['is_featured'] = $request->boolean('is_featured', false);
            $data['allow_comments'] = $request->boolean('allow_comments', true);

            $post = BlogPost::create($data);
            $post->load(['category', 'author']);

            $this->triggerNextjsRevalidation();

            return responseSuccess(BlogPostDetailResource::make($post), 'Blog post created successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to create blog post: '.$th->getMessage(), 500, $th);
        }
    }

    public function update(BlogPost $post, $request)
    {
        try {
            $data = $request->validated();

            if (!empty($data['slug'])) {
                $data['slug'] = Str::slug($data['slug']);
            }

            if ($request->hasFile('featured_image')) {
                if ($post->featured_image) {
                    $this->deleteFile($post->featured_image);
                }
                $data['featured_image'] = $this->uploadFile($request->file('featured_image'), 'blogs/posts');
            }

            if ($data['status'] === 'published' && empty($post->published_at) && empty($data['published_at'])) {
                $data['published_at'] = now();
            }

            if ($request->has('is_featured')) {
                $data['is_featured'] = $request->boolean('is_featured');
            }

            if ($request->has('allow_comments')) {
                $data['allow_comments'] = $request->boolean('allow_comments');
            }

            $post->update($data);
            $post->load(['category', 'author']);

            $this->triggerNextjsRevalidation();

            return responseSuccess(BlogPostDetailResource::make($post), 'Blog post updated successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to update blog post: '.$th->getMessage(), 500, $th);
        }
    }

    public function toggleStatus(BlogPost $post, Request $request)
    {
        try {
            $newStatus = $request->input('status', $post->status === 'published' ? 'draft' : 'published');
            $update = ['status' => $newStatus];

            if ($newStatus === 'published' && empty($post->published_at)) {
                $update['published_at'] = now();
            }

            $post->update($update);

            $this->triggerNextjsRevalidation();

            return responseSuccess(BlogPostResource::make($post), 'Status updated successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to update status: '.$th->getMessage(), 500, $th);
        }
    }

    public function toggleFeatured(BlogPost $post, Request $request)
    {
        try {
            $isFeatured = $request->has('is_featured') ? $request->boolean('is_featured') : ! $post->is_featured;
            $post->update(['is_featured' => $isFeatured]);

            $this->triggerNextjsRevalidation();

            return responseSuccess(BlogPostResource::make($post), 'Featured status updated successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to update featured status: '.$th->getMessage(), 500, $th);
        }
    }

    public function uploadContentImage(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,webp,gif|max:5120',
            ]);

            $path = $this->uploadFile($request->file('image'), 'blogs/content');
            $url = Storage::url($path);

            return responseSuccess(['url' => $url], 'Image uploaded successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to upload image: '.$th->getMessage(), 500, $th);
        }
    }

    public function destroy(BlogPost $post)
    {
        try {
            if ($post->featured_image) {
                $this->deleteFile($post->featured_image);
            }
            if ($post->thumbnail_image) {
                $this->deleteFile($post->thumbnail_image);
            }
            $post->delete();
            $this->triggerNextjsRevalidation();

            return responseSuccess(null, 'Blog post deleted successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to delete blog post: '.$th->getMessage(), 500, $th);
        }
    }

    public function generateAi(Request $request)
    {
        try {
            if (! $this->aiService || ! $this->aiService->isConfigured()) {
                return responseError('AI service is not configured or is disabled. Please configure your OpenRouter API key in Settings > AI Setup.', 422);
            }

            $validated = $request->validate([
                'title' => 'nullable|string|max:500',
                'category_id' => 'nullable',
                'excerpt' => 'nullable|string',
                'content' => 'nullable|string',
                'target_fields' => 'nullable|array',
                'target_fields.*' => 'string',
                'language' => 'nullable|string|in:auto,bn,en',
                'tone' => 'nullable|string|in:informative,dermatologist,promotional,casual',
            ]);

            $title = trim($validated['title'] ?? '');
            $content = trim(strip_tags($validated['content'] ?? ''));
            $excerpt = trim($validated['excerpt'] ?? '');

            if (empty($title) && empty($content) && empty($excerpt)) {
                return responseError('Please provide at least a title, excerpt, or draft notes to generate with AI.', 422);
            }

            // Topic is the primary seed
            $topic = ! empty($title) ? $title : (! empty($excerpt) ? $excerpt : Str::limit($content, 120));

            // Fetch active blog categories for contextual recommendation
            $categories = BlogCategory::where('is_active', true)
                ->select('id', 'name')
                ->get()
                ->toArray();

            $aiResult = $this->aiService->generateBlogPost($topic, [
                'language' => $validated['language'] ?? 'auto',
                'tone' => $validated['tone'] ?? 'informative',
                'existing_title' => $validated['title'] ?? null,
                'existing_excerpt' => $validated['excerpt'] ?? null,
                'existing_content' => $validated['content'] ?? null,
                'category_id' => $validated['category_id'] ?? null,
                'target_fields' => $validated['target_fields'] ?? null,
                'categories' => $categories,
                'timeout' => 120,
            ]);

            return responseSuccess([
                'title' => $aiResult['title'] ?? $title,
                'slug' => $aiResult['slug'] ?? Str::slug($aiResult['title'] ?? $title),
                'suggested_category_id' => $aiResult['suggested_category_id'] ?? null,
                'excerpt' => $aiResult['excerpt'] ?? '',
                'content' => $aiResult['content_html'] ?? '',
                'meta_title' => $aiResult['meta_title'] ?? '',
                'meta_description' => $aiResult['meta_description'] ?? '',
                'meta_keywords' => $aiResult['meta_keywords'] ?? '',
            ], 'Blog draft generated successfully');
        } catch (\Throwable $th) {
            Log::error('AI Blog generation error: ' . $th->getMessage(), [
                'trace' => $th->getTraceAsString(),
            ]);

            return responseError('Failed to generate blog with AI: ' . $th->getMessage(), 500, $th);
        }
    }

    /**
     * Dispatch background cache revalidation to Next.js storefront for blogs and RSS feed.
     */
    protected function triggerNextjsRevalidation(): void
    {
        try {
            $frontendUrl = config('services.frontend.url', env('FRONTEND_URL', 'http://127.0.0.1:3090'));
            Http::timeout(1)->post("{$frontendUrl}/api/revalidate?tag=blogs&path=/rss.xml");
        } catch (\Throwable) {
            // Silently continue if frontend dev server is not reachable
        }
    }
}
