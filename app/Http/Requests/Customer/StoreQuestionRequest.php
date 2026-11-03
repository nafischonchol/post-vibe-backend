<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\BaseFormRequest;

class StoreQuestionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'question' => 'required|string|min:3|max:1000',
            'author_name' => 'nullable|string|max:100',
            'author_email' => 'nullable|email|max:150',
        ];
    }
}
