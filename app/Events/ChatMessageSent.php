<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ChatMessage $message;

    public function __construct(ChatMessage $message)
    {
        $this->message = $message;
    }

    /**
     * The channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.conversation.' . $this->message->chat_conversation_id),
            new PrivateChannel('admin.chat'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'chat_conversation_id' => $this->message->chat_conversation_id,
                'sender_type' => $this->message->sender_type->value ?? (string) $this->message->sender_type,
                'sender_id' => $this->message->sender_id,
                'message' => $this->message->message,
                'file_url' => $this->message->file_url,
                'file_name' => $this->message->file_name,
                'file_type' => $this->message->file_type,
                'file_size' => $this->message->file_size,
                'payload' => $this->sanitizePayloadForBroadcast($this->message->payload),
                'is_read' => $this->message->is_read,
                'created_at' => $this->message->created_at->toISOString(),
            ],
        ];
    }

    /**
     * Keep broadcast payload lightweight to ensure real-time delivery over Reverb WebSocket.
     */
    protected function sanitizePayloadForBroadcast($payload): ?array
    {
        if (empty($payload) || !is_array($payload)) {
            return null;
        }

        $clean = [];

        // 1. Sanitize Products (only the essential attributes for ProductChatCard)
        if (!empty($payload['products']) && is_array($payload['products'])) {
            $clean['products'] = [];
            foreach (array_slice($payload['products'], 0, 4) as $p) {
                if (!is_array($p) || empty($p['id'])) {
                    continue;
                }
                $clean['products'][] = [
                    'id' => (int) $p['id'],
                    'name' => (string) ($p['name'] ?? ''),
                    'bangla_name' => (string) ($p['bangla_name'] ?? ''),
                    'slug' => (string) ($p['slug'] ?? ''),
                    'slug_url' => (string) ($p['slug_url'] ?? ''),
                    'price' => (float) ($p['price'] ?? 0),
                    'regular_price' => (float) ($p['regular_price'] ?? 0),
                    'discount_percentage' => (int) ($p['discount_percentage'] ?? 0),
                    'stock' => (int) ($p['stock'] ?? 0),
                    'in_stock' => (bool) ($p['in_stock'] ?? false),
                    'image' => $p['image'] ?? null,
                    'category' => $p['category'] ?? null,
                    'brand' => $p['brand'] ?? null,
                ];
            }
        }

        // 2. Sanitize single Product Details
        if (!empty($payload['product_details']) && is_array($payload['product_details'])) {
            $pd = $payload['product_details'];
            $clean['product_details'] = [
                'id' => (int) ($pd['id'] ?? 0),
                'name' => (string) ($pd['name'] ?? ''),
                'bangla_name' => (string) ($pd['bangla_name'] ?? ''),
                'slug_url' => (string) ($pd['slug_url'] ?? ''),
                'image' => $pd['image'] ?? null,
                'total_stock' => (int) ($pd['total_stock'] ?? 0),
            ];
        }

        // 3. Orders & Cart
        if (!empty($payload['order'])) {
            $clean['order'] = $payload['order'];
        }
        if (!empty($payload['orders']) && is_array($payload['orders'])) {
            $clean['orders'] = array_slice($payload['orders'], 0, 5);
        }
        if (!empty($payload['cart_action'])) {
            $clean['cart_action'] = $payload['cart_action'];
        }
        if (!empty($payload['cart_item'])) {
            $clean['cart_item'] = $payload['cart_item'];
        }
        if (!empty($payload['suggested_replies']) && is_array($payload['suggested_replies'])) {
            $clean['suggested_replies'] = array_slice($payload['suggested_replies'], 0, 6);
        }

        return !empty($clean) ? $clean : null;
    }
}
