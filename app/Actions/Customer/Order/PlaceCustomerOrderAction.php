<?php

namespace App\Actions\Customer\Order;

use App\Enums\ClientTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\StockMovementType;
use App\Events\OrderPlacedEvent;
use App\Http\Requests\Customer\Order\PlaceOrderRequest;
use App\Models\Cart;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\ClientOtp;
use App\Models\Combo;
use App\Models\Coupon;
use App\Models\LandingTierOffer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\ShippingSetting;
use App\Models\StockMovement;
use App\Services\CouponService;
use App\Traits\HandlesOrderCreation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class PlaceCustomerOrderAction
{
    use HandlesOrderCreation;

    /**
     * Execute customer order placement.
     */
    public function execute(array|PlaceOrderRequest $data, ?Authenticatable $client = null): Order
    {
        $validated = $data instanceof PlaceOrderRequest ? $data->validated() : $data;
        $client = $client ?? ($data instanceof PlaceOrderRequest ? $data->user() : null);

        // If client is not logged in, verify OTP if provided
        if (! $client && ! empty($validated['otp'])) {
            $cleanPhone = preg_replace('/[^0-9+]/', '', $validated['phone'] ?? '');
            $isValid = ClientOtp::verifyOtp($cleanPhone, $validated['otp'], 'auth')
                || ClientOtp::verifyOtp($cleanPhone, $validated['otp'], 'login')
                || ClientOtp::verifyOtp($cleanPhone, $validated['otp'], 'order');

            if (! $isValid) {
                throw new \InvalidArgumentException('Invalid or expired verification code.');
            }
        }

        return DB::transaction(function () use ($validated, $client) {
            $clientId = $this->resolveClientId($validated, $client);

            $totalAmount = 0;
            $variantData = [];
            $comboData = [];

            $cartIds = $validated['cart_ids'] ?? [];
            if (empty($cartIds) || ! is_array($cartIds)) {
                throw new \InvalidArgumentException('No valid cart items provided for the order.');
            }

            $cartQuery = Cart::whereIn('id', $cartIds)->with(['variant', 'product', 'combo.items.variant.product']);

            if ($clientId) {
                $cartQuery->where(function ($q) use ($clientId) {
                    $q->where('client_id', $clientId)->orWhereNull('client_id');
                });
            }

            $cartItems = $cartQuery->get();

            if ($cartItems->count() === 0) {
                throw new \InvalidArgumentException('No valid cart items found for the order.');
            }

            foreach ($cartItems as $cartItem) {
                // If it's a combo item
                if ($cartItem->combo_id) {
                    $combo = Combo::where('id', $cartItem->combo_id)
                        ->where('is_active', true)
                        ->with(['items.variant.product'])
                        ->first();

                    if (! $combo) {
                        throw new \InvalidArgumentException('One of the combo offers in your cart is currently inactive or unavailable.');
                    }

                    if ($combo->stock < $cartItem->quantity) {
                        throw new \InvalidArgumentException("Insufficient stock for combo offer: {$combo->name}");
                    }

                    $unitPrice = (float) $combo->combo_price;
                    $lineTotal = $unitPrice * (int) $cartItem->quantity;
                    $totalAmount += $lineTotal;

                    $comboData[] = [
                        'combo' => $combo,
                        'quantity' => (int) $cartItem->quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                    ];
                    continue;
                }

                // Regular Product Variant
                $variant = ProductVariant::where('id', $cartItem->product_variant_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $variant) {
                    throw new \InvalidArgumentException('One of the items in your cart is currently inactive or unavailable.');
                }

                if ($variant->stock < $cartItem->quantity) {
                    throw new \InvalidArgumentException("Insufficient stock for product SKU: {$variant->sku}");
                }

                $unitPrice = (float) ($variant->price ?? 0);
                $lineTotal = $unitPrice * (int) $cartItem->quantity;
                $totalAmount += $lineTotal;

                $variantData[] = [
                    'variant' => $variant,
                    'quantity' => (int) $cartItem->quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            Cart::whereIn('id', $cartIds)->delete();

            $discountAmount = 0.0;
            $appliedCoupon = null;
            $couponCode = ! empty($validated['coupon_code']) ? strtoupper(trim($validated['coupon_code'])) : null;

            // Authoritative Landing Tier Offer Validation & Execution
            $appliedTierOffer = null;
            $tierDiscount = 0.0;
            $isTierFreeShipping = false;

            if (! empty($validated['landing_offer_id'])) {
                $tierOffer = LandingTierOffer::with(['giftVariant.product', 'giftProduct'])
                    ->where('id', $validated['landing_offer_id'])
                    ->where('is_active', true)
                    ->first();

                if ($tierOffer) {
                    // Check if cart items contain the product matching the offer
                    $qualifyingQty = 0;
                    foreach ($variantData as $vd) {
                        if ($vd['variant']->product_id === $tierOffer->product_id) {
                            $qualifyingQty += (int) $vd['quantity'];
                        }
                    }

                    if ($qualifyingQty >= $tierOffer->quantity) {
                        $appliedTierOffer = $tierOffer;

                        if ((float) $tierOffer->discount_amount > 0) {
                            $tierDiscount = (float) $tierOffer->discount_amount;
                        } elseif ((float) $tierOffer->discount_percentage > 0) {
                            $tierDiscount = round(($totalAmount * (float) $tierOffer->discount_percentage) / 100, 2);
                        }

                        if ($tierOffer->is_free_shipping) {
                            $isTierFreeShipping = true;
                        }
                    }
                }
            }

            if ($couponCode) {
                $couponService = app(CouponService::class);
                $couponCalc = $couponService->validateAndCalculate(
                    code: $couponCode,
                    subtotal: $totalAmount,
                    clientId: $clientId,
                    phone: $validated['phone'] ?? null
                );
                $discountAmount = (float) $couponCalc['discount_amount'] + $tierDiscount;
                $appliedCoupon = Coupon::find($couponCalc['coupon_id']);
            } elseif ($tierDiscount > 0) {
                $discountAmount = $tierDiscount;
            } elseif (isset($validated['discount_amount']) && (float) $validated['discount_amount'] > 0) {
                // Fallback for manually authorized discounts if any
                $discountAmount = (float) $validated['discount_amount'];
            }

            $clientAddress = ! empty($validated['address_id']) && $clientId
                ? ClientAddress::where('client_id', $clientId)->where('id', $validated['address_id'])->first()
                : null;

            $shippingSetting = ShippingSetting::first();
            if ($isTierFreeShipping) {
                $deliveryCharge = 0.0;
            } elseif ($shippingSetting) {
                if ($shippingSetting->is_free_shipping_active && $shippingSetting->free_shipping_threshold !== null && $totalAmount >= (float) $shippingSetting->free_shipping_threshold) {
                    $deliveryCharge = 0.0;
                } else {
                    $districtStr = $clientAddress?->city ?? ($validated['district'] ?? ($validated['city'] ?? ''));
                    $isInsideDhaka = ! empty($districtStr) && (
                        stripos($districtStr, 'dhaka') !== false ||
                        str_contains($districtStr, 'ঢাকা')
                    );
                    $deliveryCharge = $isInsideDhaka
                        ? (float) $shippingSetting->inside_dhaka_charge
                        : (float) $shippingSetting->outside_dhaka_charge;
                }
            } else {
                $deliveryCharge = isset($validated['delivery_charge']) ? (float) $validated['delivery_charge'] : 0.0;
            }

            $taxAmount = 0;
            $grandTotal = max(0, $totalAmount - $discountAmount + $deliveryCharge);

            $invoiceNo = $this->generateInvoiceNo();

            $clientSnapshot = [
                'name' => $clientAddress?->name ?? ($validated['name'] ?? ($client?->name ?? 'Guest')),
                'phone' => $clientAddress?->phone ?? ($validated['phone'] ?? ($client?->phone ?? '')),
                'address' => $clientAddress?->address ?? ($validated['address'] ?? ''),
                'city' => $clientAddress?->city ?? ($validated['city'] ?? ($validated['district'] ?? '')),
                'label' => $clientAddress?->label ?? 'HOME',
                'note' => $validated['note'] ?? null,
            ];

            $offerSnapshot = null;
            if ($appliedTierOffer) {
                $offerSnapshot = [
                    'id' => $appliedTierOffer->id,
                    'title' => $appliedTierOffer->title,
                    'subtitle' => $appliedTierOffer->subtitle,
                    'quantity' => $appliedTierOffer->quantity,
                    'discount_amount' => $tierDiscount,
                    'is_free_shipping' => $isTierFreeShipping,
                    'gift_text' => $appliedTierOffer->gift_text,
                ];
            }

            $order = Order::create([
                'invoice_no' => $invoiceNo,
                'client_id' => $clientId,
                'coupon_id' => $appliedCoupon?->id,
                'coupon_code' => $appliedCoupon?->code,
                'landing_offer_id' => $appliedTierOffer?->id,
                'offer_snapshot' => $offerSnapshot,
                'client_snapshot' => $clientSnapshot,
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'delivery_charge' => $deliveryCharge,
                'grand_total' => $grandTotal,
                'paid_amount' => 0,
                'change_amount' => 0,
                'status' => OrderStatusEnum::PLACED->value,
                'created_by_id' => null,
            ]);

            if ($appliedCoupon && $discountAmount > 0) {
                app(CouponService::class)->recordUsage($appliedCoupon, $order, $clientId, $discountAmount);
            }

            $orderNote = ! empty($validated['note'])
                ? 'Order placed by customer via online checkout. Note: '.$validated['note']
                : 'Order placed by customer via online checkout.';

            $order->statusHistories()->create([
                'status' => OrderStatusEnum::PLACED,
                'note' => $orderNote,
                'changed_by_id' => null,
            ]);

            if (! empty($variantData)) {
                $this->createOrderItemsAndDeductStock($order, $variantData, null, 'Online Sale Order');
            }

            // If landing tier offer includes a free physical gift product, create gift order item and deduct stock
            if ($appliedTierOffer && $appliedTierOffer->gift_variant_id) {
                $giftVariant = ProductVariant::with('product')->find($appliedTierOffer->gift_variant_id);
                if ($giftVariant) {
                    $giftVariantTitle = $this->getVariantTitle($giftVariant);
                    $giftStockBefore = $giftVariant->stock;

                    $giftOrderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $giftVariant->product_id,
                        'product_variant_id' => $giftVariant->id,
                        'product_snapshot' => [
                            'name' => "FREE GIFT: {$giftVariant->product->name}",
                            'sku' => $giftVariant->sku,
                            'variant_title' => $giftVariantTitle,
                            'is_gift' => true,
                            'offer_name' => $appliedTierOffer->title,
                        ],
                        'unit_price' => 0.00,
                        'quantity' => 1,
                        'total' => 0.00,
                        'is_gift' => true,
                    ]);

                    $giftVariant->decrement('stock', 1);

                    StockMovement::create([
                        'product_id' => $giftVariant->product_id,
                        'product_variant_id' => $giftVariant->id,
                        'type' => StockMovementType::SALE,
                        'quantity' => 1,
                        'stock_before' => $giftStockBefore,
                        'stock_after' => $giftStockBefore - 1,
                        'reference_type' => OrderItem::class,
                        'reference_id' => $giftOrderItem->id,
                        'reason' => "Promotional Free Gift for Order {$order->invoice_no} ({$appliedTierOffer->title})",
                        'created_by_id' => null,
                    ]);
                }
            }

            if (! empty($comboData)) {
                foreach ($comboData as $cd) {
                    $combo = $cd['combo'];
                    $comboQty = (int) $cd['quantity'];

                    $itemsSnapshot = $combo->items->map(function ($ci) {
                        return [
                            'product_id' => $ci->product_id,
                            'product_name' => $ci->product?->name,
                            'variant_sku' => $ci->variant?->sku,
                            'quantity' => (int) $ci->quantity,
                            'unit_price' => (float) ($ci->variant?->discount_price ?? $ci->variant?->price ?? 0),
                        ];
                    })->toArray();

                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'combo_id' => $combo->id,
                        'product_id' => null,
                        'product_variant_id' => null,
                        'product_snapshot' => [
                            'is_combo' => true,
                            'name' => $combo->name,
                            'bangla_name' => $combo->bangla_name,
                            'slug' => $combo->slug,
                            'slug_url' => $combo->slug_url,
                            'items' => $itemsSnapshot,
                        ],
                        'unit_price' => $cd['unit_price'],
                        'quantity' => $comboQty,
                        'total' => $cd['line_total'],
                    ]);

                    foreach ($combo->items as $comboItem) {
                        $variant = ProductVariant::where('id', $comboItem->product_variant_id)
                            ->lockForUpdate()
                            ->first();

                        if ($variant) {
                            $deductQty = ((int) $comboItem->quantity) * $comboQty;
                            $stockBefore = $variant->stock;
                            $variant->decrement('stock', $deductQty);

                            StockMovement::create([
                                'product_id' => $variant->product_id,
                                'product_variant_id' => $variant->id,
                                'type' => StockMovementType::SALE,
                                'quantity' => $deductQty,
                                'stock_before' => $stockBefore,
                                'stock_after' => $stockBefore - $deductQty,
                                'reference_type' => OrderItem::class,
                                'reference_id' => $orderItem->id,
                                'reason' => "Combo Sale order {$order->invoice_no} ({$combo->name})",
                                'created_by_id' => null,
                            ]);
                        }
                    }
                }
            }

            return $order;
        });

        $trackingContext = [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'fbp' => $validated['fbp'] ?? request()->cookie('_fbp'),
            'fbc' => $validated['fbc'] ?? request()->cookie('_fbc'),
            'event_id' => $validated['event_id'] ?? null,
            'source_url' => $validated['source_url'] ?? request()->header('Referer'),
        ];

        event(new OrderPlacedEvent($order, $trackingContext));

        return $order;
    }

    /**
     * Resolve existing client or create a new client, sync address, and return client ID.
     */
    public function resolveClientId(array &$validated, ?Authenticatable $client = null): ?int
    {
        $clientId = null;

        if ($client) {
            $clientId = (int) $client->id;
        } elseif (! empty($validated['phone'])) {
            $cleanPhone = preg_replace('/[^0-9+]/', '', $validated['phone']);

            if (! empty($cleanPhone)) {
                $matchedClient = Client::where('phone', $cleanPhone)
                    ->orWhere('phone', ltrim($cleanPhone, '+880'))
                    ->orWhere('phone', '0'.ltrim($cleanPhone, '+880'))
                    ->first();

                if ($matchedClient) {
                    $updates = [];
                    if (! empty($validated['otp']) && ! $matchedClient->phone_verified_at) {
                        $updates['phone_verified_at'] = now();
                    }
                    if (! empty($validated['name']) && (empty($matchedClient->name) || str_starts_with($matchedClient->name, 'Customer '))) {
                        $updates['name'] = $validated['name'];
                    }
                    if (! empty($updates)) {
                        $matchedClient->update($updates);
                    }

                    $clientId = (int) $matchedClient->id;
                } else {
                    $newClient = Client::create([
                        'name' => ! empty($validated['name']) ? $validated['name'] : 'Customer '.substr($cleanPhone, -4),
                        'phone' => $cleanPhone,
                        'phone_verified_at' => ! empty($validated['otp']) ? now() : null,
                        'type' => ClientTypeEnum::CUSTOMER,
                        'status' => 'approved',
                        'is_active' => true,
                    ]);

                    $clientId = (int) $newClient->id;
                }
            }
        }

        // Save newly provided shipping address for the client if address_id was not provided
        if ($clientId && empty($validated['address_id']) && ! empty($validated['address'])) {
            $shippingAddress = trim($validated['address']);
            $shippingName = ! empty($validated['name']) ? trim($validated['name']) : ($client?->name ?? 'Customer');
            $shippingPhone = ! empty($validated['phone']) ? trim($validated['phone']) : ($client?->phone ?? '');
            $shippingCity = ! empty($validated['city']) ? trim($validated['city']) : (! empty($validated['district']) ? trim($validated['district']) : '');

            $existingAddress = ClientAddress::where('client_id', $clientId)
                ->where('address', $shippingAddress)
                ->first();

            if ($existingAddress) {
                $validated['address_id'] = $existingAddress->id;
            } else {
                $hasAddresses = ClientAddress::where('client_id', $clientId)->exists();

                $newAddress = ClientAddress::create([
                    'client_id' => $clientId,
                    'name' => $shippingName,
                    'phone' => $shippingPhone,
                    'address' => $shippingAddress,
                    'city' => $shippingCity,
                    'label' => 'HOME',
                    'is_default' => ! $hasAddresses,
                ]);

                $validated['address_id'] = $newAddress->id;
            }
        }

        return $clientId;
    }
}
