<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class RegisterOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^(?:\+?880|0)?1[3-9]\d{8}$/'],
            'otp' => ['required', 'string', 'min:4', 'max:10'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Please enter your mobile phone number.',
            'phone.regex' => 'Please enter a valid 11-digit Bangladeshi mobile number.',
            'otp.required' => 'Please enter the OTP verification code.',
        ];
    }
}
