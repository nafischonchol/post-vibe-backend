<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AttributeValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (is_array($this->resource)) {
            return [
                'id' => $this->resource['id'] ?? null,
                'value' => $this->resource['value'] ?? '',
                'image' => ! empty($this->resource['image']) ? Storage::url($this->resource['image']) : null,
                'image_relative' => $this->resource['image'] ?? null,
                'meta_title' => $this->resource['meta_title'] ?? null,
                'meta_description' => $this->resource['meta_description'] ?? null,
                'is_active' => (bool) ($this->resource['is_active'] ?? true),
            ];
        }

        if (is_string($this->resource) || is_numeric($this->resource)) {
            return [
                'id' => null,
                'value' => (string) $this->resource,
                'image' => null,
                'image_relative' => null,
                'meta_title' => null,
                'meta_description' => null,
                'is_active' => true,
            ];
        }

        return [
            'id' => $this->id,
            'value' => $this->value,
            'image' => $this->image ? Storage::url($this->image) : null,
            'image_relative' => $this->image,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'is_active' => (bool) ($this->is_active ?? true),
        ];
    }
}
