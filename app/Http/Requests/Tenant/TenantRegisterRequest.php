<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class TenantRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:tenants,email'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'string', 'email', 'max:255', 'unique:tenant_users,email'],
            'owner_password' => ['required', 'string', 'min:8'],
        ];
    }
}
