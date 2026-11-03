<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPlacedEvent
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  array  $trackingContext  Array containing ip, user_agent, fbp, fbc, event_id, source_url
     */
    public function __construct(
        public Order $order,
        public array $trackingContext = []
    ) {}
}
