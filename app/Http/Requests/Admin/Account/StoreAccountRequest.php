<?php

namespace App\Http\Requests\Admin\Account;

use App\Enums\AccountTypeEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountTypeEnum::class)],
            'account_number' => [
                Rule::requiredIf($this->input('type') !== AccountTypeEnum::CASH->value),
                'nullable',
                'string',
                'max:255',
            ],
            'is_active' => ['boolean'],
        ];
    }
}
