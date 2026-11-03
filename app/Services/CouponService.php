<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use InvalidArgumentException;

class CouponService
{
    /**
     * Validate a coupon against order subtotal and customer, returning discount calculation.
     *
     * @throws InvalidArgumentException
     */
    public function validateAndCalculate(
        string $code,
        float $subtotal,
        ?int $clientId = null,
        ?string $phone = null
    ): array {
        $cleanCode = strtoupper(trim($code));

        if (empty($cleanCode)) {
            throw new InvalidArgumentException('Please enter a coupon code.');
        }

        $coupon = Coupon::where('code', $cleanCode)->first();

        if (! $coupon) {
            throw new InvalidArgumentException('Invalid coupon code.');
        }

        if (! $coupon->is_active) {
            throw new InvalidArgumentException('This coupon is currently inactive.');
        }

        $now = now();
        if ($coupon->starts_at && $now->lt($coupon->starts_at)) {
            throw new InvalidArgumentException('This coupon will be active starting '.$coupon->starts_at->format('d M, Y h:i A').'.');
        }

        if ($coupon->expires_at && $now->gt($coupon->expires_at)) {
            throw new InvalidArgumentException('This coupon expired on '.$coupon->expires_at->format('d M, Y').'.');
        }

        if ($coupon->usage_limit_total !== null && $coupon->used_count >= $coupon->usage_limit_total) {
            throw new InvalidArgumentException('This coupon has reached its total usage limit.');
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            throw new InvalidArgumentException(
                'Minimum order amount of ৳'.number_format((float) $coupon->min_order_amount, 2).' is required to apply this coupon.'
            );
        }

        // If clientId is not provided but phone is, try resolving client by phone
        if (! $clientId && ! empty($phone)) {
            $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
            if (! empty($cleanPhone)) {
                $matchedClient = Client::where('phone', $cleanPhone)
                    ->orWhere('phone', ltrim($cleanPhone, '+880'))
                    ->orWhere('phone', '0'.ltrim($cleanPhone, '+880'))
                    ->first();
                if ($matchedClient) {
                    $clientId = (int) $matchedClient->id;
                }
            }
        }

        // Specific client restrictions
        if ($coupon->target_type === 'specific_clients') {
            if (! $clientId) {
                throw new InvalidArgumentException('This coupon is exclusively for specific registered accounts. Please log in.');
            }

            $isAssigned = $coupon->clients()->where('client_id', $clientId)->exists();
            if (! $isAssigned) {
                throw new InvalidArgumentException('You are not eligible to use this coupon.');
            }
        }

        // Per-client usage limit
        if ($clientId && $coupon->usage_limit_per_client > 0) {
            $usedByClientCount = CouponUsage::where('coupon_id', $coupon->id)
                ->where('client_id', $clientId)
                ->count();

            if ($usedByClientCount >= $coupon->usage_limit_per_client) {
                throw new InvalidArgumentException('You have already used this coupon the maximum allowed times.');
            }
        }

        // Calculate discount
        $discount = 0.0;
        if ($coupon->type === 'fixed') {
            $discount = min((float) $coupon->value, $subtotal);
        } elseif ($coupon->type === 'percentage') {
            $percentageDiscount = round(($subtotal * (float) $coupon->value) / 100, 2);
            if ($coupon->max_discount_amount !== null && $coupon->max_discount_amount > 0) {
                $discount = min($percentageDiscount, (float) $coupon->max_discount_amount);
            } else {
                $discount = $percentageDiscount;
            }
            $discount = min($discount, $subtotal);
        }

        return [
            'coupon_id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'min_order_amount' => $coupon->min_order_amount ? (float) $coupon->min_order_amount : null,
            'max_discount_amount' => $coupon->max_discount_amount ? (float) $coupon->max_discount_amount : null,
            'discount_amount' => round($discount, 2),
            'message' => 'Coupon applied successfully.',
        ];
    }

    /**
     * Record usage after successful order placement.
     */
    public function recordUsage(Coupon $coupon, Order $order, ?int $clientId, float $discountAmount): CouponUsage
    {
        $usage = CouponUsage::create([
            'coupon_id' => $coupon->id,
            'order_id' => $order->id,
            'client_id' => $clientId,
            'discount_amount' => $discountAmount,
        ]);

        $coupon->increment('used_count');

        $order->update([
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
        ]);

        return $usage;
    }
}
