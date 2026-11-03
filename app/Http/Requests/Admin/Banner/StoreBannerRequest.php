<?php

namespace App\Http\Requests\Admin\Banner;

use App\Enums\BannerTypeEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreBannerRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(BannerTypeEnum::class)],
            'short_description' => ['nullable', 'string', 'max:250'],
            'redirect_url' => ['nullable', 'string', 'max:2048'],
            'banner_image' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'mobile_banner_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'is_active' => ['boolean'],
        ];
    }
}
