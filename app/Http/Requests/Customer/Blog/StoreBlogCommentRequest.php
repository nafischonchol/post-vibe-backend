<?php

namespace App\Http\Requests\Customer\Blog;

use App\Http\Requests\BaseFormRequest;

class StoreBlogCommentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'author_name' => 'required|string|max:100',
            'author_email' => 'nullable|email|max:150',
            'content' => 'required|string|min:2|max:2000',
            'parent_id' => 'nullable|exists:blog_comments,id',
        ];
    }
}
