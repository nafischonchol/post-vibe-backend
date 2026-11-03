<?php

namespace App\Services\AiChat\Omnichannel;

use App\Enums\ChatAgentModeEnum;
use App\Enums\ChatChannelEnum;
use App\Enums\ChatConversationStatusEnum;
use App\Helpers\PhoneNumberHelper;
use App\Models\ChatConversation;
use App\Models\Client;
use App\Models\ClientSocialIdentity;
use Illuminate\Support\Facades\Log;

class ChatIdentityService
{
    /**
     * Resolve or create an active chat conversation for an omnichannel customer.
     */
    public function resolveConversation(
        ChatChannelEnum $channel,
        string $externalId,
        ?string $customerName = null,
        ?string $customerPhone = null
    ): ChatConversation {
        // 1. Check if there's already an open conversation for this channel & external ID
        $conversation = ChatConversation::where('channel', $channel)
            ->where('external_user_id', $externalId)
            ->where('status', ChatConversationStatusEnum::OPEN)
            ->latest('last_message_at')
            ->first();

        if ($conversation) {
            // Update customer name/phone if newly available
            $updates = [];
            if ($customerName && empty($conversation->customer_name)) {
                $updates['customer_name'] = $customerName;
            }
            if ($customerPhone && empty($conversation->customer_phone)) {
                $updates['customer_phone'] = $customerPhone;
            }
            if (!empty($updates)) {
                $conversation->update($updates);
            }

            return $conversation;
        }

        // 2. Identify if this external ID is linked to an existing Client
        $clientId = null;
        $isPhoneVerified = false;

        if ($channel === ChatChannelEnum::WHATSAPP) {
            // WhatsApp externalId is the phone number
            $normalizedPhone = PhoneNumberHelper::toLocalBd($externalId);
            if ($normalizedPhone) {
                $existingClient = Client::where('phone', $normalizedPhone)->first();
                if ($existingClient) {
                    $clientId = $existingClient->id;
                    $customerName = $customerName ?: $existingClient->name;
                    $customerPhone = $normalizedPhone;
                    $isPhoneVerified = true;
                } else {
                    $customerPhone = $normalizedPhone;
                }
            }
        } else {
            // Facebook Messenger: check if PSID is linked in client_social_identities
            $identity = ClientSocialIdentity::where('channel', $channel)
                ->where('external_id', $externalId)
                ->with('client')
                ->first();

            if ($identity && $identity->client) {
                $clientId = $identity->client->id;
                $customerName = $customerName ?: $identity->client->name;
                $customerPhone = $customerPhone ?: $identity->client->phone;
                $isPhoneVerified = true;
            }
        }

        return ChatConversation::create([
            'channel' => $channel,
            'external_user_id' => $externalId,
            'client_id' => $clientId,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'agent_mode' => ChatAgentModeEnum::AI,
            'status' => ChatConversationStatusEnum::OPEN,
            'conversation_state' => 'idle',
            'is_phone_verified' => $isPhoneVerified,
            'last_message_at' => now(),
        ]);
    }

    /**
     * Permanently link a client to a channel external ID (e.g. FB PSID) after OTP verification.
     */
    public function linkClientToIdentity(
        Client $client,
        ChatChannelEnum $channel,
        string $externalId,
        ?array $profileData = null
    ): ClientSocialIdentity {
        $identity = ClientSocialIdentity::updateOrCreate(
            [
                'channel' => $channel,
                'external_id' => $externalId,
            ],
            [
                'client_id' => $client->id,
                'profile_data' => $profileData,
            ]
        );

        // Update all conversations for this channel & external ID
        ChatConversation::where('channel', $channel)
            ->where('external_user_id', $externalId)
            ->update([
                'client_id' => $client->id,
                'customer_name' => $client->name,
                'customer_phone' => $client->phone,
                'is_phone_verified' => true,
            ]);

        Log::info("Linked Client #{$client->id} ({$client->phone}) to {$channel->value} ID: {$externalId}");

        return $identity;
    }
}
