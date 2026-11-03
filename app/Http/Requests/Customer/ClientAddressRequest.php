<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\BaseFormRequest;

class ClientAddressRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'phone' => ['required', 'string', 'digits:11', 'regex:/^01[3-9]\d{8}$/'],
            'address' => ['required', 'string', 'max:250'],
            'city' => ['required', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:50'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Please enter a phone number.',
            'phone.digits' => 'Phone number must be exactly 11 digits.',
            'phone.regex' => 'Please enter a valid 11-digit mobile number (e.g., 017XXXXXXXX).',
        ];
    }
}
