<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\BaseFormRequest;

class UpdatePasswordRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required_without:otp', 'nullable', 'string'],
            'otp' => ['required_without:current_password', 'nullable', 'string', 'min:4', 'max:10'],
            'new_password' => ['required', 'string', 'min:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required_without' => 'Please provide either your current password or an OTP verification code.',
            'otp.required_without' => 'Please provide either your current password or an OTP verification code.',
            'new_password.required' => 'Please enter your new password.',
            'new_password.min' => 'The new password must be at least 6 characters.',
        ];
    }
}
