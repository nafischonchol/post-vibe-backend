<?php

namespace App\Http\Requests\Tenant\Publishing;

use App\Http\Requests\BaseFormRequest;

class StorePublishingBatchRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'video_id' => ['required', 'exists:videos,id'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.platform' => ['required', 'string', 'in:youtube,tiktok,facebook'],
            'targets.*.account_id' => ['nullable', 'exists:tenant_platform_accounts,id'],
            'scheduled_at' => ['nullable', 'date'],
        ];
    }
}
