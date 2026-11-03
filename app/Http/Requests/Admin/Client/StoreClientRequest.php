<?php

namespace App\Http\Requests\Admin\Client;

use App\Enums\ClientTypeEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:6'],
            'type' => ['required', Rule::enum(ClientTypeEnum::class)],
            'balance' => ['nullable', 'numeric'],
            'address' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
