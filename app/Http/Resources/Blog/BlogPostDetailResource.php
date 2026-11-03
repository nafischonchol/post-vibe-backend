<?php

namespace App\Http\Resources\Blog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BlogPostDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'blog_category_id' => $this->blog_category_id,
            'category' => $this->whenLoaded('category', function () {
                return [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ];
            }),
            'admin_id' => $this->admin_id,
            'author' => $this->whenLoaded('author', function () {
                return [
                    'id' => $this->author->id,
                    'name' => $this->author->name,
                    'avatar' => $this->author->avatar ? Storage::url($this->author->avatar) : null,
                ];
            }),
            'title' => $this->title,
            'slug' => $this->slug,
            'slug_url' => $this->slug_url,
            'excerpt' => $this->excerpt,
            'content' => $this->content,
            'featured_image' => $this->featured_image ? Storage::url($this->featured_image) : null,
            'thumbnail_image' => $this->thumbnail_image ? Storage::url($this->thumbnail_image) : null,
            'status' => $this->status,
            'published_at' => $this->published_at?->toISOString(),
            'views_count' => (int) $this->views_count,
            'is_featured' => (bool) $this->is_featured,
            'allow_comments' => (bool) $this->allow_comments,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,
            'comments_count' => $this->whenCounted('comments'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
