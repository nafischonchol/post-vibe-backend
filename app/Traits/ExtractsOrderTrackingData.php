<?php

namespace App\Traits;

use App\Models\Order;

trait ExtractsOrderTrackingData
{
    /**
     * Extract normalized customer identity and browser context from Order.
     */
    public function extractUserData(Order $order, array $context = []): array
    {
        $clientSnapshot = $order->client_snapshot ?? [];

        return [
            'name' => $clientSnapshot['name'] ?? $order->client?->name ?? null,
            'email' => $clientSnapshot['email'] ?? $order->client?->email ?? null,
            'phone' => $clientSnapshot['phone'] ?? $order->client?->phone ?? null,
            'city' => $clientSnapshot['city'] ?? $clientSnapshot['district'] ?? null,
            'zip' => $clientSnapshot['zip'] ?? $clientSnapshot['postal_code'] ?? null,
            'country' => 'bd',
            'client_ip_address' => $context['ip'] ?? request()->ip(),
            'client_user_agent' => $context['user_agent'] ?? request()->userAgent(),
            'fbp' => $context['fbp'] ?? request()->cookie('_fbp'),
            'fbc' => $context['fbc'] ?? request()->cookie('_fbc'),
            'ttclid' => $context['ttclid'] ?? request()->cookie('ttclid') ?? request()->cookie('_ttp'),
            'external_id' => $order->client_id,
        ];
    }

    /**
     * Extract line items from order formatted for marketing conversion payloads.
     */
    public function extractOrderItems(Order $order): array
    {
        if (! $order->relationLoaded('items')) {
            $order->load('items.product');
        }

        $contents = [];
        $numItems = 0;

        foreach ($order->items as $item) {
            $quantity = (int) ($item->quantity ?? 1);
            $numItems += $quantity;
            $contents[] = [
                'id' => (string) ($item->product_id ?? $item->product_variant_id ?? $item->id),
                'content_id' => (string) ($item->product_id ?? $item->product_variant_id ?? $item->id),
                'content_name' => (string) ($item->product?->name ?? $item->item_name ?? 'Product'),
                'content_type' => 'product',
                'name' => (string) ($item->product?->name ?? $item->item_name ?? 'Product'),
                'quantity' => $quantity,
                'price' => (float) ($item->unit_price ?? 0),
                'item_price' => (float) ($item->unit_price ?? 0),
            ];
        }

        return [
            'contents' => $contents,
            'num_items' => $numItems,
        ];
    }

    /**
     * Generate unique event ID for deduplication.
     */
    public function generateEventId(Order $order, array $context = [], ?string $suffix = null): string
    {
        if (! empty($context['event_id'])) {
            return $context['event_id'];
        }

        $base = 'order_'.$order->invoice_no;

        return $suffix ? "{$base}_{$suffix}" : $base;
    }

    /**
     * Get event source URL.
     */
    public function getSourceUrl(array $context = []): ?string
    {
        return $context['source_url'] ?? request()->header('Referer');
    }
}
