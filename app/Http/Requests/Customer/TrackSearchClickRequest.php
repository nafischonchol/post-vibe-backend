<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class TrackSearchClickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'search_log_id' => ['nullable', 'integer'],
            'query' => ['nullable', 'string', 'max:255'],
            'visitor_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
