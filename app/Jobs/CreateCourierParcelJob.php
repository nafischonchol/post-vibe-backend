<?php

namespace App\Jobs;

use App\Models\CourierSetting;
use App\Models\Order;
use App\Models\OrderParcel;
use Bonik\Courier\Facades\BDCourier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateCourierParcelJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Order $order,
        public string $courierName
    ) {}

    public function handle(): void
    {
        $providerKey = strtolower($this->courierName);

        // Fetch Courier Credentials from courier_settings table
        $setting = CourierSetting::where('courier_name', $providerKey)
            ->where('is_enabled', true)
            ->first();

        if (! $setting || empty($setting->credentials)) {
            $errorMsg = "Courier '{$this->courierName}' is not configured or enabled.";
            Log::error("Courier booking failed for Order #{$this->order->id}: {$errorMsg}");

            OrderParcel::create([
                'order_id' => $this->order->id,
                'courier_provider' => $providerKey,
                'status' => 'failed',
                'booking_error' => $errorMsg,
            ]);

            $this->order->update([
                'parcel_booking_status' => 'failed',
                'latest_courier_provider' => $providerKey,
            ]);

            return;
        }

        $credentials = $setting->credentials;

        // Customer info from client snapshot
        $client = $this->order->client_snapshot ?? [];
        $address = $client['address'] ?? '';
        $city = $client['city'] ?? '';
        $fullAddress = trim(($address ? $address : '').($city ? ($address ? ', '.$city : $city) : ''));

        $customerInfo = [
            'name' => $client['name'] ?? 'Customer',
            'phone' => $client['phone'] ?? '',
            'address' => $fullAddress ?: 'Dhaka',
        ];

        // Calculate COD amount: grand_total - paid_amount
        $codAmount = max(0, (float) $this->order->grand_total - (float) $this->order->paid_amount);

        $parcelInfo = [
            'invoice_id' => $this->order->invoice_no,
            'cod_amount' => $codAmount,
            'weight' => 0.5,
            'note' => 'Handle with care',
        ];

        try {
            $result = BDCourier::createParcel(
                provider: $providerKey,
                credentials: $credentials,
                customerInfo: $customerInfo,
                parcelInfo: $parcelInfo
            );

            if (! empty($result['success']) && $result['success'] === true) {
                OrderParcel::create([
                    'order_id' => $this->order->id,
                    'courier_provider' => $providerKey,
                    'tracking_code' => $result['tracking_code'] ?? null,
                    'consignment_id' => $result['consignment_id'] ?? null,
                    'invoice_id' => $result['invoice_id'] ?? $this->order->invoice_no,
                    'status' => $result['status'] ?? 'pending',
                    'raw_status' => $result['raw_status'] ?? null,
                    'delivery_charge' => (float) ($result['delivery_charge'] ?? 0),
                    'response_payload' => $result['raw_response'] ?? $result,
                ]);

                $this->order->update([
                    'parcel_booking_status' => 'completed',
                    'latest_courier_provider' => $providerKey,
                ]);
            } else {
                $errorMsg = $result['message'] ?? 'Courier provider returned booking failure.';

                OrderParcel::create([
                    'order_id' => $this->order->id,
                    'courier_provider' => $providerKey,
                    'status' => 'failed',
                    'booking_error' => $errorMsg,
                    'response_payload' => $result['raw_response'] ?? $result,
                ]);

                $this->order->update([
                    'parcel_booking_status' => 'failed',
                    'latest_courier_provider' => $providerKey,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("Exception in CreateCourierParcelJob for Order #{$this->order->id}: ".$e->getMessage());

            OrderParcel::create([
                'order_id' => $this->order->id,
                'courier_provider' => $providerKey,
                'status' => 'failed',
                'booking_error' => $e->getMessage(),
            ]);

            $this->order->update([
                'parcel_booking_status' => 'failed',
                'latest_courier_provider' => $providerKey,
            ]);
        }
    }
}
