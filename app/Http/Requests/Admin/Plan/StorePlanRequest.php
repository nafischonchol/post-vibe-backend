<?php

namespace App\Http\Requests\Admin\Plan;

use App\Http\Requests\BaseFormRequest;

class StorePlanRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly' => ['required', 'numeric', 'min:0'],
            'global_daily_quota' => ['required', 'integer', 'min:1'],
            'team_member_limit' => ['required', 'integer', 'min:1'],
            'storage_limit_mb' => ['required', 'integer', 'min:100'],
            'features' => ['nullable', 'array'],
            'platform_quotas' => ['nullable', 'array'],
            'platform_quotas.*.platform' => ['required', 'string'],
            'platform_quotas.*.daily_quota' => ['required', 'integer', 'min:0'],
        ];
    }
}
