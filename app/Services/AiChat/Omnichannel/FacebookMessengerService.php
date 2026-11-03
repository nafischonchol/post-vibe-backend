<?php

namespace App\Services\AiChat\Omnichannel;

use App\Enums\ChatChannelEnum;
use App\Models\ChatChannelSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookMessengerService
{
    protected string $graphApiVersion = 'v21.0';

    /**
     * Get configured Facebook credentials from DB.
     */
    public function getCredentials(): array
    {
        return ChatChannelSetting::getCredentials(ChatChannelEnum::FACEBOOK);
    }

    /**
     * Verify webhook signature using the App Secret stored in DB.
     */
    public function verifySignature(string $payload, ?string $signatureHeader): bool
    {
        $creds = $this->getCredentials();
        $appSecret = $creds['app_secret'] ?? null;

        if (empty($appSecret) || empty($signatureHeader)) {
            // If in local/testing and no secret set, allow for development
            return app()->environment('local', 'testing');
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);

        return hash_equals($expectedSignature, $signatureHeader);
    }

    /**
     * Fetch customer's public profile from Meta Graph API using PSID.
     */
    public function getUserProfile(string $psid): ?array
    {
        $creds = $this->getCredentials();
        $token = $creds['page_access_token'] ?? null;

        if (!$token) {
            return null;
        }

        try {
            $response = Http::timeout(5)->get("https://graph.facebook.com/{$this->graphApiVersion}/{$psid}", [
                'fields' => 'first_name,last_name,profile_pic',
                'access_token' => $token,
            ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to fetch FB profile for {$psid}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Send message back to customer via Facebook Send API.
     */
    public function sendMessage(string $recipientPsid, string $messageText, ?array $payload = null): bool
    {
        $creds = $this->getCredentials();
        $token = $creds['page_access_token'] ?? null;

        if (!$token) {
            Log::warning("Facebook Messenger Send skipped: No page_access_token configured in ChatChannelSetting.");
            return false;
        }

        // Build message payload
        $messageData = [
            'text' => $messageText,
        ];

        // Format suggested replies as Facebook Quick Replies
        $suggested = $payload['suggested_replies'] ?? [];
        if (!empty($suggested) && is_array($suggested)) {
            $quickReplies = [];
            foreach (array_slice($suggested, 0, 10) as $chip) {
                $chipTitle = mb_substr((string) $chip, 0, 20); // FB limits title to 20 chars
                $quickReplies[] = [
                    'content_type' => 'text',
                    'title' => $chipTitle,
                    'payload' => $chipTitle,
                ];
            }
            if (!empty($quickReplies)) {
                $messageData['quick_replies'] = $quickReplies;
            }
        }

        try {
            $url = "https://graph.facebook.com/{$this->graphApiVersion}/me/messages";
            $res = Http::timeout(8)->post("{$url}?access_token={$token}", [
                'recipient' => ['id' => $recipientPsid],
                'messaging_type' => 'RESPONSE',
                'message' => $messageData,
            ]);

            if (!$res->successful()) {
                Log::error("Facebook Messenger Send API failed: " . $res->body(), [
                    'recipient' => $recipientPsid,
                    'status' => $res->status(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Exception in Facebook Messenger Send API: " . $e->getMessage());
            return false;
        }
    }
}
