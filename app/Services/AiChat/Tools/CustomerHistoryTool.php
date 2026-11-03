<?php

namespace App\Services\AiChat\Tools;

use App\Models\Cart;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class CustomerHistoryTool
{
    public static function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_customer_history',
                'description' => 'Retrieve the customer\'s past purchase history, recent orders, and items currently in their shopping cart. Call this to provide personalized skincare suggestions, restock reminders, or complementary product pairings based on what they bought before.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'include_cart' => [
                            'type' => 'boolean',
                            'description' => 'Whether to include current shopping cart contents. Default is true.',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function execute(array $args, ?int $clientId = null): array
    {
        Log::debug("CustomerHistoryTool args", ['args' => $args, 'client_id' => $clientId]);

        if (!$clientId) {
            return [
                'is_authenticated' => false,
                'message' => 'Customer is currently browsing as a guest. No past purchase history is available.',
            ];
        }

        // 1. Fetch recent completed / non-cancelled orders
        $orders = Order::where('client_id', $clientId)
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->with(['orderItems.product.category', 'orderItems.product.brand'])
            ->latest('id')
            ->limit(5)
            ->get();

        $purchasedProducts = [];
        $purchasedCategories = [];

        foreach ($orders as $order) {
            foreach ($order->orderItems as $item) {
                if ($item->product) {
                    $purchasedProducts[] = [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'brand' => $item->product->brand?->name,
                        'category' => $item->product->category?->name,
                        'order_date' => $order->created_at->format('M d, Y'),
                    ];
                    if ($item->product->category?->name) {
                        $purchasedCategories[] = $item->product->category->name;
                    }
                }
            }
        }

        $cartItems = [];
        if ($args['include_cart'] ?? true) {
            $cart = Cart::where('client_id', $clientId)->with(['product.brand', 'product.category'])->get();
            foreach ($cart as $c) {
                if ($c->product) {
                    $cartItems[] = [
                        'id' => $c->product->id,
                        'name' => $c->product->name,
                        'quantity' => $c->quantity,
                        'brand' => $c->product->brand?->name,
                    ];
                }
            }
        }

        return [
            'is_authenticated' => true,
            'recent_orders_count' => $orders->count(),
            'purchased_products' => array_slice($purchasedProducts, 0, 8),
            'top_categories_bought' => array_values(array_unique($purchasedCategories)),
            'cart_items' => $cartItems,
            'guidance' => 'Use these past purchases to recommend complementary steps (e.g. if they bought retinol, suggest a soothing barrier cream; if they bought cleanser, suggest toner or sunscreen).',
        ];
    }
}
