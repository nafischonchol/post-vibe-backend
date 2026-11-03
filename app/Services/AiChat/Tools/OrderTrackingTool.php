<?php

namespace App\Services\AiChat\Tools;

use App\Models\Order;

class OrderTrackingTool
{
    public static function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'track_order',
                'description' => 'Check the status and details of orders for the customer. Can check recent orders or a specific invoice number.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'invoice_no' => [
                            'type' => 'string',
                            'description' => 'Optional specific invoice number to look up (e.g. "INV-12345")',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function execute(array $args, ?int $clientId = null): array
    {
        if (!$clientId) {
            return ['error' => 'Customer is not authenticated. Please ask the customer to log in.'];
        }

        $invoiceNo = $args['invoice_no'] ?? null;

        $query = Order::query()
            ->where('client_id', $clientId)
            ->with(['orderItems.product']);

        if ($invoiceNo) {
            $order = $query->where('invoice_no', $invoiceNo)->first();
            if (!$order) {
                return ['error' => "No order found matching invoice number: {$invoiceNo}"];
            }

            return [
                'order' => [
                    'id' => $order->id,
                    'invoice_no' => $order->invoice_no,
                    'status' => $order->status?->value ?? (string) $order->status,
                    'grand_total' => (float) $order->grand_total,
                    'parcel_status' => $order->parcel_booking_status,
                    'courier_provider' => $order->latest_courier_provider,
                    'date' => $order->created_at->format('d M, Y h:i A'),
                    'items' => $order->orderItems->map(fn($item) => [
                        'product_name' => $item->product?->name ?? 'Product',
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                    ]),
                ],
            ];
        }

        // Return latest 3 orders
        $orders = $query->latest()->limit(3)->get();

        if ($orders->isEmpty()) {
            return ['message' => 'You currently have no orders placed.'];
        }

        return [
            'orders' => $orders->map(fn($order) => [
                'id' => $order->id,
                'invoice_no' => $order->invoice_no,
                'status' => $order->status?->value ?? (string) $order->status,
                'grand_total' => (float) $order->grand_total,
                'parcel_status' => $order->parcel_booking_status,
                'courier_provider' => $order->latest_courier_provider,
                'date' => $order->created_at->format('d M, Y'),
                'items_count' => $order->orderItems->count(),
            ])->toArray(),
        ];
    }
}
