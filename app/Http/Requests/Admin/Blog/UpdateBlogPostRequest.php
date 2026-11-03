<?php

namespace App\Http\Requests\Admin\Blog;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateBlogPostRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $id = $this->route('post') ?? $this->route('blog_post');
        if (is_object($id)) {
            $id = $id->id;
        }

        return [
            'blog_category_id' => 'nullable|exists:blog_categories,id',
            'title' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('blog_posts', 'slug')->ignore($id),
            ],
            'excerpt' => 'nullable|string',
            'content' => 'required|string',
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:5120',
            'status' => 'required|in:draft,published,scheduled,archived',
            'published_at' => 'nullable|date',
            'is_featured' => 'nullable|boolean',
            'allow_comments' => 'nullable|boolean',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'meta_keywords' => 'nullable|string',
        ];
    }
}
