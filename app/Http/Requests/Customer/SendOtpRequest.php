<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^(?:\+?880|0)?1[3-9]\d{8}$/'],
            'purpose' => ['nullable', 'string', 'in:auth,login,register,reset'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Please enter your mobile phone number.',
            'phone.regex' => 'Please enter a valid 11-digit Bangladeshi mobile number (e.g., 01712345678).',
        ];
    }
}
