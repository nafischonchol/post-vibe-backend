<?php

namespace App\Services\Admin;

use App\Http\Requests\Admin\ShippingSetting\UpdateShippingSettingRequest;
use App\Http\Resources\ShippingSettingResource;
use App\Models\ShippingSetting;
use Illuminate\Support\Facades\Cache;

class ShippingSettingService
{
    const CACHE_KEY = 'customer_shipping_settings';

    /**
     * Get or create default shipping settings.
     */
    public function getSettings()
    {
        $setting = ShippingSetting::first();
        if (! $setting) {
            $setting = ShippingSetting::create([
                'inside_dhaka_charge' => 60.00,
                'outside_dhaka_charge' => 120.00,
                'is_free_shipping_active' => false,
                'free_shipping_threshold' => 1500.00,
                'estimated_delivery_inside_dhaka' => '2-3 business days',
                'estimated_delivery_outside_dhaka' => '3-5 business days',
            ]);
        }

        return responseSuccess(ShippingSettingResource::make($setting));
    }

    /**
     * Update shipping settings.
     */
    public function update(UpdateShippingSettingRequest $request)
    {
        try {
            $data = $request->validated();
            $data['is_free_shipping_active'] = $request->boolean('is_free_shipping_active');

            $setting = ShippingSetting::first();
            if ($setting) {
                $setting->update($data);
            } else {
                $setting = ShippingSetting::create($data);
            }

            Cache::forget(self::CACHE_KEY);

            return responseSuccess(ShippingSettingResource::make($setting->fresh()), 'Shipping settings updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to update shipping settings: '.$e->getMessage(), 500);
        }
    }

    /**
     * Get public settings for customer checkout.
     */
    public function getPublicSettings()
    {
        $cachedData = Cache::remember(self::CACHE_KEY, 3600, function () {
            $setting = ShippingSetting::first();
            if (! $setting) {
                $setting = ShippingSetting::create([
                    'inside_dhaka_charge' => 60.00,
                    'outside_dhaka_charge' => 120.00,
                    'is_free_shipping_active' => false,
                    'free_shipping_threshold' => 1500.00,
                    'estimated_delivery_inside_dhaka' => '2-3 business days',
                    'estimated_delivery_outside_dhaka' => '3-5 business days',
                ]);
            }

            return ShippingSettingResource::make($setting)->resolve();
        });

        return responseSuccess($cachedData);
    }
}
