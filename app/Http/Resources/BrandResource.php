<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'slug_url' => $this->slug_url,
            'icon' => $this->icon ? Storage::url($this->icon) : null,
            'icon_relative' => $this->icon,
            'authenticity_video_url' => $this->authenticity_video_url,
            'is_active' => $this->is_active,
            'meta_title' => $this->meta_title,
            'meta_keyword' => $this->meta_keyword ?? [],
            'meta_description' => $this->meta_description,
            'meta_image' => $this->meta_image ? Storage::url($this->meta_image) : null,
            'meta_image_relative' => $this->meta_image,
            'seo_bottom_content' => $this->seo_bottom_content,
            'seo_bottom_content_bn' => $this->seo_bottom_content_bn,
            'faqs' => $this->faqs ?? [],
            'faqs_bn' => $this->faqs_bn ?? [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
