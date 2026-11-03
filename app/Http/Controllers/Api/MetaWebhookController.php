<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChatAgentModeEnum;
use App\Enums\ChatChannelEnum;
use App\Enums\ChatSenderTypeEnum;
use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessAiChatResponseJob;
use App\Models\ChatChannelSetting;
use App\Services\AiChat\Omnichannel\ChatIdentityService;
use App\Services\AiChat\Omnichannel\ConversationalOrderService;
use App\Services\AiChat\Omnichannel\FacebookMessengerService;
use App\Services\AiChat\Omnichannel\WhatsAppCloudApiService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MetaWebhookController extends Controller
{
    public function __construct(
        protected ChatIdentityService $identityService,
        protected FacebookMessengerService $facebookService,
        protected WhatsAppCloudApiService $whatsAppService,
        protected ConversationalOrderService $orderService
    ) {}

    /**
     * Facebook Webhook Verification Challenge (GET).
     */
    public function verifyFacebook(Request $request): Response
    {
        $mode = $request->query('hub_mode') ?: $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?: $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?: $request->query('hub.challenge');

        $creds = $this->facebookService->getCredentials();
        $expectedToken = $creds['verify_token'] ?? null;

        if ($mode === 'subscribe' && ($token === $expectedToken || app()->environment('local', 'testing'))) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Inbound Facebook Messenger Event (POST).
     */
    public function handleFacebook(Request $request): Response
    {
        // 1. Signature Verification
        $signature = $request->header('X-Hub-Signature-256');
        if (!$this->facebookService->verifySignature($request->getContent(), $signature)) {
            Log::warning("Facebook Webhook Signature Mismatch");
            return response('Unauthorized', 401);
        }

        $payload = $request->all();
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $messagingEvents = $entry['messaging'] ?? [];

            foreach ($messagingEvents as $event) {
                // Ignore delivery or read receipts
                if (isset($event['delivery']) || isset($event['read'])) {
                    continue;
                }

                $senderId = $event['sender']['id'] ?? null;
                if (!$senderId) {
                    continue;
                }

                // Extract message text or quick reply payload
                $messageText = $event['message']['text']
                    ?? $event['message']['quick_reply']['payload']
                    ?? $event['postback']['payload']
                    ?? null;

                $fileUrl = null;
                $fileType = null;

                if (!empty($event['message']['attachments'])) {
                    $attachment = $event['message']['attachments'][0] ?? [];
                    $fileType = $attachment['type'] ?? 'file';
                    $fileUrl = $attachment['payload']['url'] ?? null;
                }

                if (empty($messageText) && empty($fileUrl)) {
                    continue;
                }

                // Fetch customer profile if possible
                $profile = $this->facebookService->getUserProfile($senderId);
                $customerName = null;
                if ($profile) {
                    $customerName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
                }

                // Resolve conversation
                $conversation = $this->identityService->resolveConversation(
                    ChatChannelEnum::FACEBOOK,
                    $senderId,
                    $customerName
                );

                // Save Customer Message
                $customerMessage = $conversation->messages()->create([
                    'sender_type' => ChatSenderTypeEnum::CUSTOMER,
                    'message' => $messageText ?: '',
                    'file_url' => $fileUrl,
                    'file_type' => $fileType,
                    'is_read' => false,
                ]);

                $conversation->update(['last_message_at' => now()]);
                broadcast(new ChatMessageSent($customerMessage))->toOthers();

                // Check Conversational Order Step
                $orderReply = $this->orderService->handleIncomingOrderStep($conversation, $customerMessage);
                if ($orderReply) {
                    // Send order reply directly to Facebook
                    $this->facebookService->sendMessage(
                        $senderId,
                        $orderReply->message,
                        $orderReply->payload
                    );
                    broadcast(new ChatMessageSent($orderReply));
                    continue;
                }

                // Process AI response in background job
                if ($conversation->agent_mode === ChatAgentModeEnum::AI) {
                    ProcessAiChatResponseJob::dispatch($conversation, $customerMessage);
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * WhatsApp Webhook Verification Challenge (GET).
     */
    public function verifyWhatsApp(Request $request): Response
    {
        $mode = $request->query('hub_mode') ?: $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?: $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?: $request->query('hub.challenge');

        $creds = $this->whatsAppService->getCredentials();
        $expectedToken = $creds['verify_token'] ?? null;

        if ($mode === 'subscribe' && ($token === $expectedToken || app()->environment('local', 'testing'))) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Inbound WhatsApp Cloud API Event (POST).
     */
    public function handleWhatsApp(Request $request): Response
    {
        $payload = $request->all();
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];

            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];
                $contacts = $value['contacts'] ?? [];

                $contactName = $contacts[0]['profile']['name'] ?? null;

                foreach ($messages as $msg) {
                    $fromPhone = $msg['from'] ?? null;
                    if (!$fromPhone) {
                        continue;
                    }

                    $messageText = null;
                    $msgType = $msg['type'] ?? 'text';

                    if ($msgType === 'text') {
                        $messageText = $msg['text']['body'] ?? null;
                    } elseif ($msgType === 'interactive') {
                        $messageText = $msg['interactive']['button_reply']['title']
                            ?? $msg['interactive']['list_reply']['title']
                            ?? null;
                    }

                    if (empty($messageText)) {
                        continue;
                    }

                    // Resolve conversation
                    $conversation = $this->identityService->resolveConversation(
                        ChatChannelEnum::WHATSAPP,
                        $fromPhone,
                        $contactName,
                        $fromPhone
                    );

                    // Save Customer Message
                    $customerMessage = $conversation->messages()->create([
                        'sender_type' => ChatSenderTypeEnum::CUSTOMER,
                        'message' => $messageText,
                        'is_read' => false,
                    ]);

                    $conversation->update(['last_message_at' => now()]);
                    broadcast(new ChatMessageSent($customerMessage))->toOthers();

                    // Check Conversational Order Step
                    $orderReply = $this->orderService->handleIncomingOrderStep($conversation, $customerMessage);
                    if ($orderReply) {
                        // Send reply directly to WhatsApp
                        $this->whatsAppService->sendMessage(
                            $fromPhone,
                            $orderReply->message,
                            $orderReply->payload
                        );
                        broadcast(new ChatMessageSent($orderReply));
                        continue;
                    }

                    // Process AI response in background job
                    if ($conversation->agent_mode === ChatAgentModeEnum::AI) {
                        ProcessAiChatResponseJob::dispatch($conversation, $customerMessage);
                    }
                }
            }
        }

        return response('OK', 200);
    }
}
