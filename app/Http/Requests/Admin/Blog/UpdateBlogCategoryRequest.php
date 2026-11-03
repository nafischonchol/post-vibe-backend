<?php

namespace App\Http\Requests\Admin\Blog;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateBlogCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $id = $this->route('category') ?? $this->route('blog_category');
        if (is_object($id)) {
            $id = $id->id;
        }

        return [
            'name' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('blog_categories', 'slug')->ignore($id),
            ],
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:2048',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'order_num' => 'nullable|integer',
        ];
    }
}
