<?php

namespace App\Http\Requests\Tenant\PlatformAccount;

use App\Http\Requests\BaseFormRequest;

class UpdatePlatformAccountRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'account_name' => ['sometimes', 'required', 'string', 'max:255'],
            'account_handle' => ['nullable', 'string', 'max:255'],
            'external_account_id' => ['nullable', 'string', 'max:255'],
            'avatar_url' => ['nullable', 'url'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive,expired,revoked,needs_reconnect'],
            'access_token' => ['nullable', 'string', 'min:10', 'not_regex:/^mock_/i'],
            'refresh_token' => ['nullable', 'string', 'min:10', 'not_regex:/^mock_/i'],
        ];
    }
}
