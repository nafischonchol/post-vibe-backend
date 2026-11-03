<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'short_description' => $this->short_description,
            'redirect_url' => $this->redirect_url,
            'banner_image' => $this->banner_image ? Storage::url($this->banner_image) : null,
            'mobile_banner_image' => $this->mobile_banner_image ? Storage::url($this->mobile_banner_image) : null,
        ];
    }
}
