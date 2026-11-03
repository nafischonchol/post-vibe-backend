<?php

namespace App\Services\Admin;

use App\Enums\CourierEnum;
use App\Http\Resources\CourierSettingResource;
use App\Models\CourierSetting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;

class CourierSettingService
{
    public function index()
    {
        $settings = CourierSetting::all();

        return responseSuccess(CourierSettingResource::collection($settings));
    }

    public function updateOrCreate($request)
    {
        try {
            // Validate incoming payload
            $validator = Validator::make($request->all(), [
                'courier_name' => ['required', new Enum(CourierEnum::class)],
                'is_enabled' => ['required', 'boolean'],
                'is_default' => ['required', 'boolean'],
                'credentials' => ['required', 'array'],
            ]);

            if ($validator->fails()) {
                return responseError($validator->errors()->first(), 422);
            }

            $courierName = $request->input('courier_name');
            $isEnabled = $request->boolean('is_enabled');
            $isDefault = $request->boolean('is_default');
            $credentials = $request->input('credentials');

            // Business logic: Ensure at least one enabled courier is default, and only one is default.
            if (! $isEnabled) {
                $isDefault = false;
            } else {
                // If it is enabled, and not explicitly marked as default,
                // check if there is another courier currently enabled AND marked as default.
                if (! $isDefault) {
                    $hasOtherDefault = CourierSetting::where('courier_name', '!=', $courierName)
                        ->where('is_enabled', true)
                        ->where('is_default', true)
                        ->exists();

                    if (! $hasOtherDefault) {
                        $isDefault = true;
                    }
                }
            }

            // Find existing config or prepare new one
            $setting = CourierSetting::updateOrCreate(
                [
                    'courier_name' => $courierName,
                ],
                [
                    'is_enabled' => $isEnabled,
                    'is_default' => $isDefault,
                    'credentials' => $credentials,
                ]
            );

            // If this courier is set as default, disable default status for all other couriers
            if ($isDefault) {
                CourierSetting::where('courier_name', '!=', $courierName)
                    ->update(['is_default' => false]);
            } else {
                // If this courier is NOT default (e.g. it was just disabled or saved as non-default),
                // make sure there is still at least one default if there are any other enabled couriers.
                $hasDefault = CourierSetting::where('is_enabled', true)
                    ->where('is_default', true)
                    ->exists();

                if (! $hasDefault) {
                    $firstEnabled = CourierSetting::where('is_enabled', true)
                        ->first();
                    if ($firstEnabled) {
                        $firstEnabled->update(['is_default' => true]);
                    }
                }
            }

            return responseSuccess(
                CourierSettingResource::make($setting),
                ucfirst($courierName).' courier settings saved successfully.'
            );
        } catch (\Exception $e) {
            return responseError('Failed to save courier settings: '.$e->getMessage(), 500);
        }
    }

    public function checkFraud($request)
    {
        try {
            $rawPhone = (string) ($request->input('phone') ?? $request->query('phone') ?? '');
            $cleanPhone = preg_replace('/[^\d]/', '', $rawPhone);

            // Strip leading 88 if provided (e.g. 88017XXXXXXXX -> 017XXXXXXXX)
            if (str_starts_with($cleanPhone, '880') && strlen($cleanPhone) === 13) {
                $cleanPhone = substr($cleanPhone, 2);
            }

            if (! preg_match('/^01[3-9]\d{8}$/', $cleanPhone)) {
                return responseError('Please provide a valid 11-digit Bangladeshi mobile number (e.g., 01712345678).', 422);
            }

            $refresh = $request->boolean('refresh') || $request->boolean('force');
            $report = $this->getAndSyncFraudReport($cleanPhone, $refresh);

            return responseSuccess($report, 'Customer courier history fetched successfully.');
        } catch (\Throwable $e) {
            return responseError('Failed to check courier history: '.$e->getMessage(), 500);
        }
    }

    public function getAndSyncFraudReport(string $rawPhone, bool $refresh = false): array
    {
        $cleanPhone = preg_replace('/[^\d]/', '', $rawPhone);
        if (str_starts_with($cleanPhone, '880') && strlen($cleanPhone) === 13) {
            $cleanPhone = substr($cleanPhone, 2);
        }

        if (! preg_match('/^01[3-9]\d{8}$/', $cleanPhone)) {
            throw new \InvalidArgumentException('Invalid Bangladeshi mobile number.');
        }

        if ($refresh) {
            \Illuminate\Support\Facades\Cache::forget("bd_courier:result:elitemart:{$cleanPhone}");
            \Illuminate\Support\Facades\Cache::forget("bd_courier:result:steadfast:{$cleanPhone}");
            \Illuminate\Support\Facades\Cache::forget("bd_courier:result:pathao:{$cleanPhone}");
            \Illuminate\Support\Facades\Cache::forget("bd_courier:result:redx:{$cleanPhone}");
            \Illuminate\Support\Facades\Cache::forget("bd_courier:result:carrybee:{$cleanPhone}");
        }

        $rawResult = \Bonik\Courier\Facades\BDCourier::check($cleanPhone);

        $summary = $rawResult['totalSummary'] ?? [
            'total' => 0,
            'success' => 0,
            'cancel' => 0,
            'success_rate' => null,
            'cancel_rate' => null,
        ];

        // Filter out totalSummary to get courier-specific records
        $couriers = [];
        foreach ($rawResult as $key => $val) {
            if ($key !== 'totalSummary' && is_array($val)) {
                $couriers[$key] = $val;
            }
        }

        // Query internal store orders for this phone number
        $phoneVariants = [
            $cleanPhone,
            '88' . $cleanPhone,
            '+88' . $cleanPhone,
            '+880' . substr($cleanPhone, 1),
        ];

        $clientIds = \App\Models\Client::whereIn('phone', $phoneVariants)->pluck('id');

        $internalOrders = \App\Models\Order::query()
            ->where(function ($q) use ($clientIds, $phoneVariants) {
                if ($clientIds->isNotEmpty()) {
                    $q->whereIn('client_id', $clientIds);
                }
                foreach ($phoneVariants as $pv) {
                    $q->orWhere('client_snapshot->phone', $pv);
                }
            })
            ->get();

        $internalTotal = $internalOrders->count();
        $internalDelivered = 0;
        $internalCancelled = 0;
        $internalInProgress = 0;

        foreach ($internalOrders as $order) {
            $statusVal = $order->status instanceof \App\Enums\OrderStatusEnum
                ? $order->status->value
                : (string) $order->status;

            if ($statusVal === \App\Enums\OrderStatusEnum::DELIVERED->value) {
                $internalDelivered++;
            } elseif (in_array($statusVal, [
                \App\Enums\OrderStatusEnum::CANCELLED->value,
                \App\Enums\OrderStatusEnum::RETURNED->value,
                \App\Enums\OrderStatusEnum::UNREACHABLE->value,
            ], true)) {
                $internalCancelled++;
            } else {
                $internalInProgress++;
            }
        }

        $internalResolved = $internalDelivered + $internalCancelled;
        $internalSuccessRate = $internalResolved > 0
            ? round(($internalDelivered / $internalResolved) * 100, 2)
            : ($internalTotal > 0 ? 100 : null);

        $courierTotal = (int) ($summary['total'] ?? 0);
        $courierSuccess = (int) ($summary['success'] ?? 0);
        $courierCancel = (int) ($summary['cancel'] ?? 0);

        // Combined overall stats (Our Store + External Couriers)
        $combinedTotal = $courierTotal + $internalTotal;
        $combinedSuccess = $courierSuccess + $internalDelivered;
        $combinedCancel = $courierCancel + $internalCancelled;
        $combinedResolved = $combinedSuccess + $combinedCancel;

        $pathaoRating = $couriers['pathao']['rating'] ?? null;
        $pathaoSuccessRate = $couriers['pathao']['success_rate'] ?? null;

        if ($combinedResolved > 0) {
            $cancelRate = round(($combinedCancel / $combinedResolved) * 100, 2);
            $successRate = round(($combinedSuccess / $combinedResolved) * 100, 2);
        } elseif ($combinedCancel > 0) {
            $cancelRate = 100;
            $successRate = 0;
        } elseif ($combinedTotal > 0) {
            // Customer has placed orders with zero cancellations so far
            $cancelRate = 0;
            $successRate = 100;
        } else {
            $cancelRate = 0;
            if ($pathaoSuccessRate !== null && is_numeric($pathaoSuccessRate) && $pathaoSuccessRate > 0) {
                $successRate = (float) $pathaoSuccessRate;
            } else {
                $successRate = 100;
            }
        }

        $riskLevel = 'new';
        $riskLabel = 'New Customer';

        if ($internalCancelled >= 2 || $pathaoRating === 'risky_customer' || ($combinedResolved >= 3 && $cancelRate >= 50)) {
            $riskLevel = 'high';
            $riskLabel = 'High Return Risk';
        } elseif ($pathaoRating === 'moderate_customer' || ($combinedResolved >= 2 && $cancelRate >= 25)) {
            $riskLevel = 'medium';
            $riskLabel = 'Moderate Risk';
        } elseif ($internalDelivered >= 1 || $pathaoRating === 'excellent_customer' || $pathaoRating === 'good_customer' || ($combinedResolved > 0 && $successRate >= 70)) {
            $riskLevel = 'low';
            $riskLabel = 'Safe Customer';
        } elseif ($combinedTotal === 0) {
            $riskLevel = 'new';
            $riskLabel = 'New Customer';
        } else {
            $riskLevel = 'low';
            $riskLabel = 'Safe Customer';
        }

        // Prepend Our Store to couriers list
        $storeStats = [
            'name' => 'Our Store',
            'data_type' => 'delivery',
            'total' => $internalTotal,
            'success' => $internalDelivered,
            'cancel' => $internalCancelled,
            'in_progress' => $internalInProgress,
            'success_rate' => $internalSuccessRate,
            'risk_level' => $internalCancelled >= 2
                ? 'high'
                : ($internalDelivered > 0 || $internalTotal > 0 ? 'low' : 'unknown'),
        ];

        $allBreakdowns = array_merge([
            'mohima_store' => $storeStats,
        ], $couriers);

        $report = [
            'phone' => $cleanPhone,
            'summary' => [
                'total' => $combinedTotal,
                'success' => $combinedSuccess,
                'cancel' => $combinedCancel,
                'success_rate' => $successRate,
                'cancel_rate' => $cancelRate,
                'risk_level' => $riskLevel,
                'risk_label' => $riskLabel,
                'our_store' => [
                    'total' => $internalTotal,
                    'delivered' => $internalDelivered,
                    'cancelled' => $internalCancelled,
                    'in_progress' => $internalInProgress,
                ],
            ],
            'couriers' => $allBreakdowns,
        ];

        // Save / Update to matching clients in clients table
        \App\Models\Client::whereIn('phone', $phoneVariants)->get()->each(function ($client) use ($report) {
            $client->update(['client_rating' => $report]);
        });

        return $report;
    }
}
