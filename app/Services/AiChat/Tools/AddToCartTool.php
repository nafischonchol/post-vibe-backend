<?php

namespace App\Services\AiChat\Tools;

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Log;

class AddToCartTool
{
    public static function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'add_to_cart',
                'description' => 'Add a specific product to the customer\'s shopping cart. Call this whenever the customer asks to add an item to their cart, buy a product, or order an item.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => [
                            'type' => 'integer',
                            'description' => 'The ID of the product to add to the cart (preferred if known).',
                        ],
                        'product_name' => [
                            'type' => 'string',
                            'description' => 'The name or keyword of the product to add if the ID is not directly known.',
                        ],
                        'quantity' => [
                            'type' => 'integer',
                            'description' => 'Number of units to add (default is 1).',
                        ],
                        'variant_id' => [
                            'type' => 'integer',
                            'description' => 'Optional variant ID (e.g. specific shade, volume, or size). If not provided, default active variant is selected.',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function execute(array $args, ?int $clientId = null, ?\App\Models\ChatConversation $conversation = null): array
    {
        Log::debug("AddToCartTool args", ['args' => $args, 'client_id' => $clientId]);

        if (!$clientId) {
            return [
                'success' => false,
                'error' => 'Customer is not logged in. Please politely ask the customer to log in to add items to their cart.',
            ];
        }

        $productId = isset($args['product_id']) && is_numeric($args['product_id']) ? (int) $args['product_id'] : null;
        $productName = trim($args['product_name'] ?? '');
        $quantity = isset($args['quantity']) && (int) $args['quantity'] > 0 ? (int) $args['quantity'] : 1;
        $variantId = isset($args['variant_id']) && is_numeric($args['variant_id']) ? (int) $args['variant_id'] : null;

        $product = null;

        $nameMatchesCandidate = function (Product $p, string $targetName): bool {
            $pName = mb_strtolower($p->name . ' ' . ($p->bangla_name ?? '') . ' ' . $p->slug);
            $targetLower = mb_strtolower($targetName);
            if (str_contains($pName, $targetLower) || str_contains($targetLower, mb_strtolower($p->name))) {
                return true;
            }
            $words = array_filter(preg_split('/\s+/u', $targetLower), fn($w) => mb_strlen($w) >= 3);
            if (empty($words)) return true;
            $matchCount = 0;
            foreach ($words as $w) {
                if (str_contains($pName, $w)) {
                    $matchCount++;
                }
            }
            return ($matchCount / count($words)) >= 0.4;
        };

        if ($productId) {
            $candidate = Product::find($productId);
            if ($candidate) {
                if (empty($productName) || $nameMatchesCandidate($candidate, $productName)) {
                    $product = $candidate;
                } else {
                    Log::warning("AddToCartTool: product_id {$productId} ('{$candidate->name}') does not match requested name '{$productName}'. Discarding.");
                }
            }
        }

        if (!$product && !empty($productName)) {
            $product = Product::where('status', 'active')
                ->where(function ($q) use ($productName) {
                    $q->where('name', 'like', "%{$productName}%")
                      ->orWhere('bangla_name', 'like', "%{$productName}%")
                      ->orWhere('slug', 'like', "%{$productName}%");
                })
                ->first();

            if (!$product) {
                $words = array_filter(preg_split('/\s+/u', $productName), fn($w) => mb_strlen($w) >= 3);
                if (!empty($words)) {
                    $query = Product::where('status', 'active');
                    foreach (array_slice($words, 0, 3) as $w) {
                        $query->where('name', 'like', "%{$w}%");
                    }
                    $product = $query->first();

                    if (!$product) {
                        $product = Product::where('status', 'active')
                            ->where(function ($q) use ($words) {
                                foreach ($words as $w) {
                                    $q->orWhere('name', 'like', "%{$w}%");
                                }
                            })
                            ->first();
                    }
                }
            }
        }

        if (!$product && $conversation) {
            $lastAiMsg = $conversation->messages()
                ->where('sender_type', \App\Enums\ChatSenderTypeEnum::AI)
                ->whereNotNull('payload')
                ->orderBy('id', 'desc')
                ->first();

            if ($lastAiMsg && !empty($lastAiMsg->payload['products'])) {
                $products = $lastAiMsg->payload['products'];
                if (count($products) === 1 && !empty($products[0]['id'])) {
                    $product = Product::find($products[0]['id']);
                }
            }
        }

        if (!$product) {
            return [
                'success' => false,
                'error' => "Product not found. Please verify the product name or ID.",
            ];
        }

        $statusStr = $product->status instanceof \BackedEnum
            ? $product->status->value
            : (string) $product->status;

        if ($statusStr !== 'active') {
            return [
                'success' => false,
                'error' => "Product is currently inactive.",
            ];
        }

        $variant = null;
        if ($variantId) {
            $variant = ProductVariant::where('product_id', $product->id)->find($variantId);
        }

        if (!$variant) {
            $variant = ProductVariant::where('product_id', $product->id)
                ->where('is_active', true)
                ->orderBy('price', 'asc')
                ->first();
        }

        if (!$variant) {
            return [
                'success' => false,
                'error' => "No active variant found for this product.",
            ];
        }

        if ($variant->stock < $quantity) {
            return [
                'success' => false,
                'error' => "Insufficient stock. Only {$variant->stock} units available in stock.",
                'available_stock' => $variant->stock,
            ];
        }

        // Add or update cart item for the client
        $cartItem = Cart::firstOrNew([
            'client_id' => $clientId,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);

        $cartItem->quantity = ($cartItem->exists ? $cartItem->quantity : 0) + $quantity;
        if ($cartItem->quantity > $variant->stock) {
            $cartItem->quantity = $variant->stock;
        }
        $cartItem->save();

        $effectivePrice = (float) ($variant->discount_price ?: $variant->price);

        return [
            'success' => true,
            'message' => "Successfully added '{$product->name}' to cart.",
            'cart_item' => [
                'id' => $cartItem->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'variant_id' => $variant->id,
                'quantity' => $cartItem->quantity,
                'unit_price' => $effectivePrice,
                'total_price' => $effectivePrice * $cartItem->quantity,
                'image' => $product->image ? Product::getImageUrl($product->image) : null,
                'slug_url' => $product->slug_url,
            ],
            'action' => 'cart_updated',
        ];
    }
}
