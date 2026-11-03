<?php

namespace App\Listeners;

use App\Events\OrderPlacedEvent;
use App\Services\Marketing\TikTokEventsApiService;
use App\Traits\ExtractsOrderTrackingData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendOrderPlacedTikTokEventListener implements ShouldQueue
{
    use ExtractsOrderTrackingData, InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    /**
     * Handle the event.
     */
    public function handle(OrderPlacedEvent $event, ?TikTokEventsApiService $service = null): void
    {
        $service = $service ?? app(TikTokEventsApiService::class);
        $order = $event->order;
        $context = $event->trackingContext;

        $userData = $this->extractUserData($order, $context);
        $itemsData = $this->extractOrderItems($order);

        $properties = [
            'currency' => 'BDT',
            'value' => (float) $order->grand_total,
            'order_id' => (string) $order->invoice_no,
            'contents' => $itemsData['contents'],
        ];

        $service->sendEvent(
            eventName: 'CompletePayment',
            eventId: $this->generateEventId($order, $context),
            userData: $userData,
            properties: $properties,
            eventSourceUrl: $this->getSourceUrl($context),
            eventTime: time()
        );
    }
}
