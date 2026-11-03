<?php

namespace App\Http\Resources\Blog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BlogCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image' => $this->image ? Storage::url($this->image) : null,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'is_active' => (bool) $this->is_active,
            'order_num' => (int) $this->order_num,
            'posts_count' => $this->whenCounted('posts'),
            'published_posts_count' => $this->whenCounted('publishedPosts'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
