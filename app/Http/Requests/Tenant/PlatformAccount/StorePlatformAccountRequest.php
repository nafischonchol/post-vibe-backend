<?php

namespace App\Http\Requests\Tenant\PlatformAccount;

use App\Http\Requests\BaseFormRequest;

class StorePlatformAccountRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'platform' => ['required', 'in:youtube,tiktok,facebook'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_handle' => ['nullable', 'string', 'max:255'],
            'avatar_url' => ['nullable', 'url'],
            'access_token' => ['nullable', 'string'],
            'refresh_token' => ['nullable', 'string'],
        ];
    }
}
