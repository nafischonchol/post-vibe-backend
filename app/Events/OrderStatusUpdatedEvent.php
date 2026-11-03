<?php

namespace App\Events;

use App\Enums\OrderStatusEnum;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdatedEvent
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Order $order,
        public OrderStatusEnum $oldStatus,
        public OrderStatusEnum $newStatus,
        public array $trackingContext = []
    ) {}
}
