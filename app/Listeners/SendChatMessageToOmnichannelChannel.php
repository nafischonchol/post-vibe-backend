<?php

namespace App\Listeners;

use App\Enums\ChatChannelEnum;
use App\Enums\ChatSenderTypeEnum;
use App\Events\ChatMessageSent;
use App\Services\AiChat\Omnichannel\FacebookMessengerService;
use App\Services\AiChat\Omnichannel\WhatsAppCloudApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendChatMessageToOmnichannelChannel implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        protected FacebookMessengerService $facebookService,
        protected WhatsAppCloudApiService $whatsAppService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(ChatMessageSent $event): void
    {
        $message = $event->message;

        // Only outbound messages (AI or ADMIN) need to be forwarded to external channels
        $senderType = $message->sender_type instanceof ChatSenderTypeEnum
            ? $message->sender_type
            : ChatSenderTypeEnum::tryFrom((string) $message->sender_type);

        if (!in_array($senderType, [ChatSenderTypeEnum::AI, ChatSenderTypeEnum::ADMIN], true)) {
            return;
        }

        $conversation = $message->conversation ?? \App\Models\ChatConversation::find($message->chat_conversation_id);
        if (!$conversation) {
            return;
        }

        $channel = $conversation->channel instanceof ChatChannelEnum
            ? $conversation->channel
            : ChatChannelEnum::tryFrom((string) $conversation->channel);

        if ($channel === ChatChannelEnum::WEB || !$conversation->external_user_id) {
            return;
        }

        try {
            if ($channel === ChatChannelEnum::FACEBOOK) {
                $this->facebookService->sendMessage(
                    $conversation->external_user_id,
                    $message->message ?? '',
                    $message->payload
                );
            } elseif ($channel === ChatChannelEnum::WHATSAPP) {
                $this->whatsAppService->sendMessage(
                    $conversation->external_user_id,
                    $message->message ?? '',
                    $message->payload
                );
            }
        } catch (\Throwable $e) {
            Log::error("Failed to forward outbound chat message to {$channel->value}: " . $e->getMessage(), [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
        }
    }
}
