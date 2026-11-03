<?php

namespace App\Services\Customer;

use App\Http\Resources\Customer\CartResource;
use App\Models\Cart;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartService
{
    /**
     * Get cart items for logged in client or guest visitor.
     */
    public function getCartItems(?Request $request = null)
    {
        try {
            $clientId = auth('sanctum')->id() ?? Auth::id();
            $visitorId = $request?->header('X-Visitor-Id') ?: $request?->input('guest_token');

            $query = Cart::with(['product.brand', 'variant', 'combo.items.variant', 'combo.items.product']);

            if ($clientId) {
                $query->where('client_id', $clientId);
            } elseif ($visitorId) {
                $query->where('visitor_id', $visitorId);
            } else {
                $items = collect();
                return responseSuccess(CartResource::collection($items), 'Cart items retrieved successfully');
            }

            if ($request && $request->filled('cart_ids')) {
                $rawIds = $request->input('cart_ids');
                $cartIds = is_array($rawIds)
                    ? $rawIds
                    : explode(',', (string) $rawIds);
                $cartIds = array_filter(array_map('intval', $cartIds), fn ($id) => $id > 0);
                if (! empty($cartIds)) {
                    $query->whereIn('id', $cartIds);
                }
            }

            $items = $query->latest('id')->get();

            return responseSuccess(CartResource::collection($items), 'Cart items retrieved successfully');
        } catch (\Exception $e) {
            return responseError('Failed to retrieve cart items: '.$e->getMessage(), 500, $e);
        }
    }

    /**
     * Add item to cart.
     */
    public function addToCart(array $data, ?Request $request = null)
    {
        try {
            $clientId = auth('sanctum')->id() ?? Auth::id();
            $visitorId = $request?->header('X-Visitor-Id') ?: ($data['visitor_id'] ?? $request?->input('guest_token'));

            if (! $visitorId) {
                $visitorId = (string) Str::ulid();
            }

            $quantity = isset($data['quantity']) ? max(1, (int) $data['quantity']) : 1;

            // Handle Combo Item
            if (! empty($data['combo_id'])) {
                $comboId = (int) $data['combo_id'];
                $combo = \App\Models\Combo::with(['items.variant'])->where('id', $comboId)->where('is_active', true)->first();

                if (! $combo) {
                    throw new \InvalidArgumentException('This combo offer is currently unavailable or inactive.');
                }

                if ($combo->stock < $quantity) {
                    throw new \InvalidArgumentException('Insufficient stock available for this combo offer.');
                }

                $cartQuery = Cart::query();
                if ($clientId) {
                    $cartQuery->where('client_id', $clientId);
                } else {
                    $cartQuery->where('visitor_id', $visitorId);
                }
                $cart = $cartQuery->where('combo_id', $comboId)->first();

                if ($cart) {
                    $cart->increment('quantity', $quantity);
                    if ($clientId && $cart->visitor_id !== $visitorId) {
                        $cart->update(['visitor_id' => $visitorId]);
                    }
                    $item = $cart->fresh(['combo.items.variant', 'combo.items.product']);
                } else {
                    $item = Cart::create([
                        'visitor_id' => $visitorId,
                        'client_id' => $clientId,
                        'combo_id' => $comboId,
                        'quantity' => $quantity,
                    ])->load(['combo.items.variant', 'combo.items.product']);
                }

                return responseSuccess(CartResource::make($item), 'Combo added to cart successfully');
            }

            $productId = (int) $data['product_id'];
            $variantId = ! empty($data['product_variant_id']) ? (int) $data['product_variant_id'] : null;

            // If variantId is null, pick the first active variant of the product
            if (! $variantId) {
                $firstActiveVariant = ProductVariant::where('product_id', $productId)
                    ->where('is_active', true)
                    ->first();
                $variantId = $firstActiveVariant?->id;

                if (! $variantId) {
                    throw new \InvalidArgumentException('No active product variant available for this product.');
                }
            }

            if ($clientId) {
                $cart = Cart::where('client_id', $clientId)
                    ->where('product_id', $productId)
                    ->where('product_variant_id', $variantId)
                    ->first();
            } else {
                $cart = Cart::where('visitor_id', $visitorId)
                    ->where('product_id', $productId)
                    ->where('product_variant_id', $variantId)
                    ->first();
            }

            if ($cart) {
                $cart->increment('quantity', $quantity);
                if ($clientId && $cart->visitor_id !== $visitorId) {
                    $cart->update(['visitor_id' => $visitorId]);
                }
                $item = $cart->fresh(['product.brand', 'variant']);
            } else {
                $item = Cart::create([
                    'visitor_id' => $visitorId,
                    'client_id' => $clientId,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'quantity' => $quantity,
                ])->load(['product.brand', 'variant']);
            }

            return responseSuccess(CartResource::make($item), 'Item added to cart successfully');
        } catch (\InvalidArgumentException $e) {
            return responseError($e->getMessage(), 422);
        } catch (\Exception $e) {
            return responseError('Failed to add item to cart: '.$e->getMessage(), 500, $e);
        }
    }

    /**
     * Update quantity of a cart item.
     */
    public function updateCart(int $id, int $quantity, ?Request $request = null)
    {
        try {
            $clientId = auth('sanctum')->id() ?? Auth::id();
            $visitorId = $request?->header('X-Visitor-Id') ?: $request?->input('guest_token');

            $query = Cart::query();

            if ($clientId) {
                $query->where('client_id', $clientId);
            } elseif ($visitorId) {
                $query->where('visitor_id', $visitorId);
            } else {
                return responseError('Cart item not found', 404);
            }

            $cart = $query->findOrFail($id);
            $cart->update(['quantity' => max(1, $quantity)]);
            $item = $cart->fresh(['product.brand', 'variant']);

            return responseSuccess(CartResource::make($item), 'Cart updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to update cart: '.$e->getMessage(), 500, $e);
        }
    }

    /**
     * Remove item from cart.
     */
    public function removeFromCart(int $id, ?Request $request = null)
    {
        try {
            $clientId = auth('sanctum')->id() ?? Auth::id();
            $visitorId = $request?->header('X-Visitor-Id') ?: $request?->input('guest_token');

            $query = Cart::query();

            if ($clientId) {
                $query->where('client_id', $clientId);
            } elseif ($visitorId) {
                $query->where('visitor_id', $visitorId);
            } else {
                return responseError('Cart item not found', 404);
            }

            $cart = $query->findOrFail($id);
            $cart->delete();

            return responseSuccess(null, 'Item removed from cart successfully');
        } catch (\Exception $e) {
            return responseError('Failed to remove item from cart: '.$e->getMessage(), 500, $e);
        }
    }

    /**
     * Clear all items in cart.
     */
    public function clearCart(?Request $request = null)
    {
        try {
            $clientId = auth('sanctum')->id() ?? Auth::id();
            $visitorId = $request?->header('X-Visitor-Id') ?: $request?->input('guest_token');

            if ($clientId) {
                Cart::where('client_id', $clientId)->delete();
            } elseif ($visitorId) {
                Cart::where('visitor_id', $visitorId)->delete();
            }

            return responseSuccess(null, 'Cart cleared successfully');
        } catch (\Exception $e) {
            return responseError('Failed to clear cart: '.$e->getMessage(), 500, $e);
        }
    }

    /**
     * Merge guest cart items into authenticated client's cart.
     * Rule: If item exists in client cart, update client quantity with guest item quantity.
     */
    public function mergeGuestCart(int $clientId, string $visitorId): void
    {
        if (! $visitorId) {
            return;
        }

        DB::transaction(function () use ($clientId, $visitorId) {
            $guestItems = Cart::where('visitor_id', $visitorId)
                ->where(function ($q) use ($clientId) {
                    $q->whereNull('client_id')->orWhere('client_id', '!=', $clientId);
                })
                ->get();

            foreach ($guestItems as $guestItem) {
                $existingClientItem = Cart::where('client_id', $clientId)
                    ->where('product_id', $guestItem->product_id)
                    ->where('product_variant_id', $guestItem->product_variant_id)
                    ->first();

                if ($existingClientItem) {
                    // Update client item quantity to match visitor item quantity
                    $existingClientItem->update([
                        'quantity' => $guestItem->quantity,
                        'visitor_id' => $visitorId,
                    ]);
                    $guestItem->delete();
                } else {
                    $guestItem->update([
                        'client_id' => $clientId,
                        'visitor_id' => $visitorId,
                    ]);
                }
            }
        });
    }
}
