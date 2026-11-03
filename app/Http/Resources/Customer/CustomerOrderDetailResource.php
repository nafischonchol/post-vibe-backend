<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerOrderDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'date' => $this->created_at->format('M d, Y h:i A'),
            'created_at' => $this->created_at->toISOString(),
            'status' => is_object($this->status) ? $this->status->value : (string) $this->status,
            'client_snapshot' => $this->client_snapshot,
            'total_amount' => (float) $this->total_amount,
            'coupon_code' => $this->coupon_code,
            'landing_offer_id' => $this->landing_offer_id,
            'offer_snapshot' => $this->offer_snapshot,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'delivery_charge' => $this->delivery_charge !== null ? (float) $this->delivery_charge : null,
            'grand_total' => (float) $this->grand_total,
            'paid_amount' => (float) $this->paid_amount,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'status_histories' => $this->whenLoaded('statusHistories', function () {
                return $this->statusHistories->map(function ($history) {
                    return [
                        'id' => $history->id,
                        'status' => is_object($history->status) ? $history->status->value : (string) $history->status,
                        'note' => $history->note,
                        'created_at' => $history->created_at->format('M d, Y h:i A'),
                    ];
                });
            }),
            'parcel' => $this->whenLoaded('parcels', function () {
                $latestParcel = $this->parcels->first();
                if (! $latestParcel) {
                    return null;
                }
                $provider = strtolower($latestParcel->courier_provider ?? '');
                $trackingCode = $latestParcel->tracking_code;
                $consignmentId = $latestParcel->consignment_id;
                $trackingUrl = null;

                if ($provider === 'steadfast' && $trackingCode) {
                    $trackingUrl = 'https://steadfast.com.bd/t/'.$trackingCode;
                } elseif ($provider === 'pathao' && $consignmentId) {
                    $trackingUrl = 'https://merchant.pathao.com/tracking?consignment_id='.$consignmentId;
                } elseif ($provider === 'redx' && $trackingCode) {
                    $trackingUrl = 'https://redx.com.bd/track/'.$trackingCode;
                }

                return [
                    'courier_provider' => $latestParcel->courier_provider,
                    'tracking_code' => $trackingCode,
                    'consignment_id' => $consignmentId,
                    'status' => $latestParcel->status,
                    'tracking_url' => $trackingUrl,
                ];
            }),
        ];
    }
}
