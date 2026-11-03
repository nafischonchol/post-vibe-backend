<?php

namespace App\Listeners;

use App\Enums\OrderStatusEnum;
use App\Events\OrderStatusUpdatedEvent;
use App\Services\Marketing\MetaConversionApiService;
use App\Traits\ExtractsOrderTrackingData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendOrderStatusUpdatedMetaCapiListener implements ShouldQueue
{
    use ExtractsOrderTrackingData, InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    /**
     * Handle the event.
     */
    public function handle(OrderStatusUpdatedEvent $event, ?MetaConversionApiService $service = null): void
    {
        $service = $service ?? app(MetaConversionApiService::class);
        $order = $event->order;
        $newStatus = $event->newStatus;
        $context = $event->trackingContext;

        $targetEventName = match ($newStatus) {
            OrderStatusEnum::DELIVERED => 'OrderDelivered',
            OrderStatusEnum::CANCELLED => 'OrderCancelled',
            OrderStatusEnum::RETURNED => 'Refund',
            default => null,
        };

        if (! $targetEventName) {
            return;
        }

        $clientSnapshot = $order->client_snapshot ?? [];

        // If it was an anonymous walk-in sale with no customer contact info, skip sending
        if (empty($order->client_id) && empty($clientSnapshot['phone']) && empty($clientSnapshot['email'])) {
            return;
        }

        $userData = $this->extractUserData($order, $context);

        $customData = [
            'currency' => 'BDT',
            'order_id' => (string) $order->invoice_no,
            'status' => $newStatus->value,
        ];

        if ($targetEventName === 'Refund' || $targetEventName === 'OrderDelivered') {
            $customData['value'] = (float) $order->grand_total;
        }

        $statusSlug = strtolower(str_replace(' ', '_', $newStatus->value));
        $eventId = $this->generateEventId($order, $context, $statusSlug);

        $service->sendEvent(
            eventName: $targetEventName,
            eventId: $eventId,
            userData: $userData,
            customData: $customData,
            eventSourceUrl: null,
            eventTime: time(),
            actionSource: 'system_generated'
        );
    }
}
