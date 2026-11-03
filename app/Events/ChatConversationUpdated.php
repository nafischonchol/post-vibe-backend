<?php

namespace App\Events;

use App\Models\ChatConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ChatConversation $conversation;

    public function __construct(ChatConversation $conversation)
    {
        $this->conversation = $conversation;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.conversation.' . $this->conversation->id),
            new PrivateChannel('admin.chat'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation' => [
                'id' => $this->conversation->id,
                'channel' => $this->conversation->channel->value ?? (string) $this->conversation->channel,
                'agent_mode' => $this->conversation->agent_mode->value ?? (string) $this->conversation->agent_mode,
                'status' => $this->conversation->status->value ?? (string) $this->conversation->status,
                'assigned_admin_id' => $this->conversation->assigned_admin_id,
                'customer_name' => $this->conversation->client?->name ?? $this->conversation->customer_name,
                'last_message_at' => $this->conversation->last_message_at?->toISOString(),
            ],
        ];
    }
}
