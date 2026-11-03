<?php

namespace App\Services\Marketing;

use App\Models\SeoSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokEventsApiService
{
    /**
     * Send an event payload to TikTok Events API (Server-Side).
     *
     * @param  string  $eventName  Standard event name (e.g., CompletePayment, ViewContent, AddToCart, InitiateCheckout)
     * @param  string|null  $eventId  Unique event ID for deduplication with browser pixel
     * @param  array  $userData  Customer data (email, phone, ip, user agent, ttclid, external_id, etc.)
     * @param  array  $properties  Event specific data (value, currency, contents, order_id, query, etc.)
     * @param  string|null  $eventSourceUrl  Source URL where the event occurred
     * @param  int|null  $eventTime  Unix timestamp of event occurrence (defaults to now)
     * @return array Result summary with success status and response payload
     */
    public function sendEvent(
        string $eventName,
        ?string $eventId = null,
        array $userData = [],
        array $properties = [],
        ?string $eventSourceUrl = null,
        ?int $eventTime = null
    ): array {
        $credentials = $this->getCredentials();

        if (empty($credentials['pixel_id']) || empty($credentials['access_token'])) {
            Log::info("TikTok Events API: Skipped sending event '{$eventName}' because Pixel ID or Access Token is missing.");

            return [
                'success' => false,
                'message' => 'Pixel ID or Access Token is missing.',
            ];
        }

        // Map event name to TikTok standard if necessary
        $mappedEventName = $this->mapEventName($eventName);
        $formattedUser = $this->formatUserData($userData);
        $eventTime = $eventTime ?? time();

        $eventData = [
            'event' => $mappedEventName,
            'event_time' => $eventTime,
            'user' => $formattedUser,
        ];

        if (! empty($eventId)) {
            $eventData['event_id'] = (string) $eventId;
        }

        if (! empty($properties)) {
            $eventData['properties'] = $this->formatProperties($properties);
        }

        if (! empty($eventSourceUrl)) {
            $eventData['page'] = [
                'url' => $eventSourceUrl,
            ];
        }

        $requestBody = [
            'event_source' => 'web',
            'event_source_id' => $credentials['pixel_id'],
            'data' => [$eventData],
        ];

        if (! empty($credentials['test_event_code'])) {
            $requestBody['test_event_code'] = $credentials['test_event_code'];
        }

        $apiVersion = ! empty($credentials['api_version']) ? $credentials['api_version'] : 'v1.3';
        $url = "https://business-api.tiktok.com/open_api/{$apiVersion}/event/track/";

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Access-Token' => $credentials['access_token'],
                    'Content-Type' => 'application/json',
                ])
                ->asJson()
                ->post($url, $requestBody);

            if ($response->successful()) {
                $responseData = $response->json();
                $code = $responseData['code'] ?? null;

                if ($code === 0 || $code === 20000 || empty($code)) {
                    Log::info("TikTok Events API: Event '{$mappedEventName}' sent successfully (Event ID: ".($eventId ?? 'N/A').').');

                    return [
                        'success' => true,
                        'response' => $responseData,
                    ];
                }

                Log::warning("TikTok Events API: API error for event '{$mappedEventName}'. Code: {$code}, Message: ".($responseData['message'] ?? 'Unknown'));

                return [
                    'success' => false,
                    'code' => $code,
                    'message' => $responseData['message'] ?? 'API responded with error code',
                    'response' => $responseData,
                ];
            }

            $errorMessage = $response->body();
            Log::warning("TikTok Events API: Failed to send event '{$mappedEventName}'. Status: {$response->status()}, Response: {$errorMessage}");

            return [
                'success' => false,
                'status' => $response->status(),
                'message' => $errorMessage,
            ];
        } catch (\Throwable $e) {
            Log::error("TikTok Events API Exception for event '{$mappedEventName}': ".$e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Retrieve TikTok Events API credentials directly from database (cached SEO settings).
     */
    public function getCredentials(): array
    {
        $seoSettings = Cache::remember('customer_seo_settings', 3600, function () {
            return SeoSetting::first();
        });

        $pixelId = $seoSettings?->tiktok_pixel_id;
        $accessToken = $seoSettings?->tiktok_access_token;
        $testEventCode = $seoSettings?->tiktok_test_event_code;
        $apiVersion = $seoSettings?->tiktok_api_version ?: 'v1.3';

        return [
            'pixel_id' => $pixelId ? trim($pixelId) : null,
            'access_token' => $accessToken ? trim($accessToken) : null,
            'test_event_code' => $testEventCode ? trim($testEventCode) : null,
            'api_version' => $apiVersion ? trim($apiVersion) : 'v1.3',
        ];
    }

    /**
     * Map common event aliases to standard TikTok Events API event names.
     */
    public function mapEventName(string $event): string
    {
        return match (strtolower(trim($event))) {
            'purchase', 'completepayment', 'complete_payment' => 'CompletePayment',
            'placeanorder', 'place_order', 'order_placed' => 'PlaceAnOrder',
            'viewcontent', 'view_content', 'view_item' => 'ViewContent',
            'addtocart', 'add_to_cart' => 'AddToCart',
            'addtowishlist', 'add_to_wishlist' => 'AddToWishlist',
            'initiatecheckout', 'initiate_checkout', 'begin_checkout' => 'InitiateCheckout',
            'search' => 'Search',
            'contact' => 'Contact',
            default => $event,
        };
    }

    /**
     * Format and hash user data according to TikTok Events API specifications.
     */
    public function formatUserData(array $rawUserData): array
    {
        $userData = [];

        // 1. Email (hash - single string or array)
        if (! empty($rawUserData['email'])) {
            $cleanEmail = strtolower(trim($rawUserData['email']));
            $userData['email'] = hash('sha256', $cleanEmail);
        }

        // 2. Phone (normalize BD & hash)
        if (! empty($rawUserData['phone']) || ! empty($rawUserData['phone_number'])) {
            $rawPhone = $rawUserData['phone'] ?? $rawUserData['phone_number'];
            $cleanPhone = $this->normalizePhoneNumber((string) $rawPhone);
            if ($cleanPhone) {
                $userData['phone_number'] = hash('sha256', '+'.$cleanPhone);
            }
        }

        // 3. Client IP Address (do not hash)
        if (! empty($rawUserData['client_ip_address'])) {
            $userData['ip'] = $rawUserData['client_ip_address'];
        } elseif (! empty($rawUserData['ip'])) {
            $userData['ip'] = $rawUserData['ip'];
        }

        // 4. Client User Agent (do not hash)
        if (! empty($rawUserData['client_user_agent'])) {
            $userData['user_agent'] = $rawUserData['client_user_agent'];
        } elseif (! empty($rawUserData['user_agent'])) {
            $userData['user_agent'] = $rawUserData['user_agent'];
        }

        // 5. TikTok Click ID (ttclid) (do not hash)
        if (! empty($rawUserData['ttclid'])) {
            $userData['ttclid'] = trim($rawUserData['ttclid']);
        }

        // 6. External ID (hash)
        if (! empty($rawUserData['external_id'])) {
            $userData['external_id'] = hash('sha256', (string) $rawUserData['external_id']);
        }

        return $userData;
    }

    /**
     * Format properties for TikTok Events API.
     */
    public function formatProperties(array $rawProperties): array
    {
        $properties = [];

        if (isset($rawProperties['value'])) {
            $properties['value'] = (float) $rawProperties['value'];
        }

        $properties['currency'] = ! empty($rawProperties['currency']) ? strtoupper(trim($rawProperties['currency'])) : 'BDT';

        if (! empty($rawProperties['order_id'])) {
            $properties['order_id'] = (string) $rawProperties['order_id'];
        }

        if (! empty($rawProperties['query'])) {
            $properties['query'] = (string) $rawProperties['query'];
        }

        if (! empty($rawProperties['contents']) && is_array($rawProperties['contents'])) {
            $contents = [];
            foreach ($rawProperties['contents'] as $item) {
                $contents[] = [
                    'price' => (float) ($item['price'] ?? $item['item_price'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'content_id' => (string) ($item['content_id'] ?? $item['id'] ?? ''),
                    'content_type' => $item['content_type'] ?? 'product',
                    'content_name' => $item['content_name'] ?? $item['name'] ?? '',
                    'content_category' => $item['content_category'] ?? $item['category'] ?? '',
                ];
            }
            $properties['contents'] = $contents;
        }

        return $properties;
    }

    /**
     * Normalize Bangladesh phone number format.
     */
    protected function normalizePhoneNumber(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (empty($digits)) {
            return null;
        }

        // If local 11-digit starting with 01 (e.g. 01712345678) -> 8801712345678
        if (strlen($digits) === 11 && str_starts_with($digits, '01')) {
            return '88'.$digits;
        }

        // If 13-digit starting with 8801
        if (strlen($digits) === 13 && str_starts_with($digits, '8801')) {
            return $digits;
        }

        return $digits;
    }
}
