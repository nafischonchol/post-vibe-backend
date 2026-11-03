<?php

namespace App\Http\Requests\Admin\Account;

use App\Http\Requests\BaseFormRequest;

class SettleCourierPayoutRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destination_account_id' => ['required', 'exists:accounts,id'],
            'disbursed_amount' => ['required', 'numeric', 'min:0.01'],
            'fee_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
