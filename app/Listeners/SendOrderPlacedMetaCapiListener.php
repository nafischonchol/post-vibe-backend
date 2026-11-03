<?php

namespace App\Listeners;

use App\Events\OrderPlacedEvent;
use App\Services\Marketing\MetaConversionApiService;
use App\Traits\ExtractsOrderTrackingData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendOrderPlacedMetaCapiListener implements ShouldQueue
{
    use ExtractsOrderTrackingData, InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    /**
     * Handle the event.
     */
    public function handle(OrderPlacedEvent $event, ?MetaConversionApiService $service = null): void
    {
        $service = $service ?? app(MetaConversionApiService::class);
        $order = $event->order;
        $context = $event->trackingContext;

        $userData = $this->extractUserData($order, $context);
        $itemsData = $this->extractOrderItems($order);

        $customData = [
            'currency' => 'BDT',
            'value' => (float) $order->grand_total,
            'order_id' => (string) $order->invoice_no,
            'content_type' => 'product',
            'contents' => $itemsData['contents'],
            'num_items' => $itemsData['num_items'],
        ];

        $service->sendEvent(
            eventName: 'Purchase',
            eventId: $this->generateEventId($order, $context),
            userData: $userData,
            customData: $customData,
            eventSourceUrl: $this->getSourceUrl($context),
            eventTime: time(),
            actionSource: $context['action_source'] ?? 'website'
        );
    }
}
