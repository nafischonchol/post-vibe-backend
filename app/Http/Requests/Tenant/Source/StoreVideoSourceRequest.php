<?php

namespace App\Http\Requests\Tenant\Source;

use App\Http\Requests\BaseFormRequest;

class StoreVideoSourceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'in:instagram,facebook,youtube,tiktok,generic'],
            'source_identifier' => ['required', 'string'],
            'source_url' => ['required', 'url'],
            'name' => ['required', 'string', 'max:255'],
            'scan_frequency' => ['nullable', 'integer', 'min:15', 'max:1440'],
        ];
    }
}
