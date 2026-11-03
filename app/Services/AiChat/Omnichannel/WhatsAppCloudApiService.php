<?php

namespace App\Services\AiChat\Omnichannel;

use App\Enums\ChatChannelEnum;
use App\Helpers\PhoneNumberHelper;
use App\Models\ChatChannelSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCloudApiService
{
    protected string $graphApiVersion = 'v21.0';

    /**
     * Get configured WhatsApp credentials from DB.
     */
    public function getCredentials(): array
    {
        return ChatChannelSetting::getCredentials(ChatChannelEnum::WHATSAPP);
    }

    /**
     * Send message back to customer via WhatsApp Cloud API.
     */
    public function sendMessage(string $recipientPhone, string $messageText, ?array $payload = null): bool
    {
        $creds = $this->getCredentials();
        $token = $creds['access_token'] ?? null;
        $phoneNumberId = $creds['phone_number_id'] ?? null;

        if (!$token || !$phoneNumberId) {
            Log::warning("WhatsApp Cloud API Send skipped: Credentials not fully configured in ChatChannelSetting.");
            return false;
        }

        $e164Phone = PhoneNumberHelper::toWhatsAppE164($recipientPhone);

        // Check if we can format interactive quick reply buttons (WhatsApp allows 1-3 buttons)
        $suggested = $payload['suggested_replies'] ?? [];
        $hasButtons = !empty($suggested) && is_array($suggested) && count($suggested) <= 3;

        $cleanText = $this->formatForWhatsApp($messageText);

        if ($hasButtons) {
            $buttons = [];
            foreach ($suggested as $idx => $label) {
                $buttonTitle = mb_substr(trim((string) $label), 0, 20); // Max 20 chars
                $buttons[] = [
                    'type' => 'reply',
                    'reply' => [
                        'id' => 'btn_' . ($idx + 1),
                        'title' => $buttonTitle,
                    ],
                ];
            }

            $body = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $e164Phone,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => $cleanText],
                    'action' => [
                        'buttons' => $buttons,
                    ],
                ],
            ];
        } else {
            // Standard text message
            $body = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $e164Phone,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $cleanText,
                ],
            ];
        }

        try {
            $url = "https://graph.facebook.com/{$this->graphApiVersion}/{$phoneNumberId}/messages";
            $res = Http::withToken($token)
                ->timeout(8)
                ->post($url, $body);

            if (!$res->successful()) {
                Log::error("WhatsApp Cloud API Send failed: " . $res->body(), [
                    'recipient' => $e164Phone,
                    'status' => $res->status(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Exception in WhatsApp Cloud API Send: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sanitize and adapt Markdown for WhatsApp compatibility.
     */
    public function formatForWhatsApp(string $text): string
    {
        // 1. Remove [SUGGESTIONS: ...] tags
        $clean = preg_replace('/\[SUGGESTIONS:\s*[^\]]*\]/is', '', $text);

        // 2. Convert Markdown links [Title](URL) -> Title (URL)
        $clean = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/i', '$1 ($2)', $clean);

        // 3. Convert Markdown headers ### Title -> *Title*
        $clean = preg_replace('/^#{1,6}\s*(.+)$/m', '*$1*', $clean);

        // 4. Convert Markdown bold **text** -> WhatsApp bold *text*
        $clean = preg_replace('/\*\*(.+?)\*\*/', '*$1*', $clean);

        return trim($clean);
    }
}
