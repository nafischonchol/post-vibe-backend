<?php

namespace App\Http\Requests\Admin\Order;

use App\Enums\OrderStatusEnum;
use App\Http\Requests\BaseFormRequest;
use App\Models\CourierSetting;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    OrderStatusEnum::CONFIRMED->value,
                    OrderStatusEnum::PACKAGING->value,
                    OrderStatusEnum::READY_TO_DELIVER->value,
                    OrderStatusEnum::CANCELLED->value,
                ]),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'courier_name' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if ($this->input('status') === OrderStatusEnum::READY_TO_DELIVER->value) {
                        if (empty($value)) {
                            $fail('Courier name is required when status is Ready To Deliver.');

                            return;
                        }

                        $providerKey = strtolower($value);
                        $setting = CourierSetting::where('courier_name', $providerKey)
                            ->where('is_enabled', true)
                            ->first();

                        if (! $setting) {
                            $fail("Courier '{$value}' is not enabled or configured in courier settings.");

                            return;
                        }

                        $credentials = $setting->credentials;
                        if (empty($credentials) || ! is_array($credentials)) {
                            $fail("Courier '{$value}' credentials are invalid or missing.");

                            return;
                        }

                        // Validate specific courier credentials
                        if ($providerKey === 'steadfast') {
                            if (empty($credentials['api_key']) || empty($credentials['secret_key'])) {
                                $fail('Steadfast Courier API Key or Secret Key is missing in settings.');
                            }
                        } elseif ($providerKey === 'pathao') {
                            $hasToken = ! empty($credentials['bearer_token']);
                            $hasClientCreds = ! empty($credentials['client_id']) &&
                                              ! empty($credentials['client_secret']) &&
                                              ! empty($credentials['username']) &&
                                              ! empty($credentials['password']) &&
                                              ! empty($credentials['store_id']);
                            if (! $hasToken && ! $hasClientCreds) {
                                $fail('Pathao Courier Client ID, Client Secret, Username, Password, or Store ID is missing in settings.');
                            }
                        }
                    }
                },
            ],
        ];
    }
}
