<?php

namespace App\Services\Marketing;

use App\Models\SeoSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaConversionApiService
{
    /**
     * Send an event payload to Meta Conversion API.
     *
     * @param  string  $eventName  Standard or custom event name (e.g., Purchase, ViewContent)
     * @param  string|null  $eventId  Unique event ID for deduplication with browser pixel
     * @param  array  $userData  Customer data (email, phone, ip, user agent, fbp, fbc, etc.)
     * @param  array  $customData  Event specific data (value, currency, contents, order_id)
     * @param  string|null  $eventSourceUrl  Source URL where the event occurred
     * @param  int|null  $eventTime  Unix timestamp of event occurrence (defaults to now)
     * @param  string  $actionSource  Action source: website, email, system_generated, etc.
     * @return array Result summary with success status and response payload
     */
    public function sendEvent(
        string $eventName,
        ?string $eventId = null,
        array $userData = [],
        array $customData = [],
        ?string $eventSourceUrl = null,
        ?int $eventTime = null,
        string $actionSource = 'website'
    ): array {
        $credentials = $this->getCredentials();

        if (empty($credentials['pixel_id']) || empty($credentials['access_token'])) {
            Log::info("Meta CAPI: Skipped sending event '{$eventName}' because Pixel ID or Access Token is missing.");

            return [
                'success' => false,
                'message' => 'Pixel ID or Access Token is missing.',
            ];
        }

        $formattedUserData = $this->formatUserData($userData);
        $eventTime = $eventTime ?? time();

        $eventPayload = [
            'event_name' => $eventName,
            'event_time' => $eventTime,
            'action_source' => $actionSource,
            'user_data' => $formattedUserData,
        ];

        if (! empty($eventId)) {
            $eventPayload['event_id'] = (string) $eventId;
        }

        if (! empty($eventSourceUrl)) {
            $eventPayload['event_source_url'] = $eventSourceUrl;
        }

        if (! empty($customData)) {
            $eventPayload['custom_data'] = $customData;
        }

        $requestBody = [
            'data' => [$eventPayload],
        ];

        if (! empty($credentials['test_event_code'])) {
            $requestBody['test_event_code'] = $credentials['test_event_code'];
        }

        $apiVersion = ! empty($credentials['api_version']) ? $credentials['api_version'] : 'v20.0';
        $url = "https://graph.facebook.com/{$apiVersion}/{$credentials['pixel_id']}/events";

        try {
            $response = Http::timeout(10)
                ->asJson()
                ->post($url.'?access_token='.urlencode($credentials['access_token']), $requestBody);

            if ($response->successful()) {
                Log::info("Meta CAPI: Event '{$eventName}' sent successfully (Event ID: ".($eventId ?? 'N/A').').');

                return [
                    'success' => true,
                    'response' => $response->json(),
                ];
            }

            $errorMessage = $response->body();
            Log::warning("Meta CAPI: Failed to send event '{$eventName}'. Status: {$response->status()}, Response: {$errorMessage}");

            return [
                'success' => false,
                'status' => $response->status(),
                'message' => $errorMessage,
            ];
        } catch (\Throwable $e) {
            Log::error("Meta CAPI Exception for event '{$eventName}': ".$e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Retrieve Meta CAPI credentials directly from database (cached SEO settings).
     */
    public function getCredentials(): array
    {
        $seoSettings = Cache::remember('customer_seo_settings', 3600, function () {
            return SeoSetting::first();
        });

        $pixelId = $seoSettings?->meta_pixel_id;
        $accessToken = $seoSettings?->meta_capi_token;
        $testEventCode = $seoSettings?->meta_test_event_code;
        $apiVersion = $seoSettings?->meta_api_version ?: 'v20.0';

        return [
            'pixel_id' => $pixelId ? trim($pixelId) : null,
            'access_token' => $accessToken ? trim($accessToken) : null,
            'test_event_code' => $testEventCode ? trim($testEventCode) : null,
            'api_version' => $apiVersion ? trim($apiVersion) : 'v20.0',
        ];
    }

    /**
     * Format and hash user data according to Meta CAPI specifications.
     */
    public function formatUserData(array $rawUserData): array
    {
        $userData = [];

        // 1. Email (hash)
        if (! empty($rawUserData['email'])) {
            $cleanEmail = strtolower(trim($rawUserData['email']));
            $userData['em'] = [hash('sha256', $cleanEmail)];
        }

        // 2. Phone (normalize & hash)
        if (! empty($rawUserData['phone'])) {
            $cleanPhone = $this->normalizePhoneNumber($rawUserData['phone']);
            if ($cleanPhone) {
                $userData['ph'] = [hash('sha256', $cleanPhone)];
            }
        }

        // 3. First & Last Name (hash)
        if (! empty($rawUserData['first_name'])) {
            $cleanFirst = strtolower(trim($rawUserData['first_name']));
            $userData['fn'] = [hash('sha256', $cleanFirst)];
        } elseif (! empty($rawUserData['name'])) {
            $nameParts = explode(' ', trim($rawUserData['name']), 2);
            $cleanFirst = strtolower(trim($nameParts[0]));
            $userData['fn'] = [hash('sha256', $cleanFirst)];

            if (! empty($nameParts[1])) {
                $cleanLast = strtolower(trim($nameParts[1]));
                $userData['ln'] = [hash('sha256', $cleanLast)];
            }
        }

        if (! empty($rawUserData['last_name'])) {
            $cleanLast = strtolower(trim($rawUserData['last_name']));
            $userData['ln'] = [hash('sha256', $cleanLast)];
        }

        // 4. City (hash)
        if (! empty($rawUserData['city'])) {
            $cleanCity = strtolower(trim(preg_replace('/[^a-zA-Z]/', '', $rawUserData['city'])));
            if ($cleanCity) {
                $userData['ct'] = [hash('sha256', $cleanCity)];
            }
        }

        // 5. Country (hash) - defaults to 'bd'
        $country = ! empty($rawUserData['country']) ? strtolower(trim($rawUserData['country'])) : 'bd';
        $userData['country'] = [hash('sha256', $country)];

        // 6. Zip / Postal Code (hash)
        if (! empty($rawUserData['zip']) || ! empty($rawUserData['postal_code'])) {
            $rawZip = $rawUserData['zip'] ?? $rawUserData['postal_code'];
            $cleanZip = strtolower(trim(preg_replace('/[^0-9a-zA-Z]/', '', (string) $rawZip)));
            if ($cleanZip) {
                $userData['zp'] = [hash('sha256', $cleanZip)];
            }
        }

        // 6. Client IP Address (do not hash)
        if (! empty($rawUserData['client_ip_address'])) {
            $userData['client_ip_address'] = $rawUserData['client_ip_address'];
        } elseif (! empty($rawUserData['ip'])) {
            $userData['client_ip_address'] = $rawUserData['ip'];
        }

        // 7. Client User Agent (do not hash)
        if (! empty($rawUserData['client_user_agent'])) {
            $userData['client_user_agent'] = $rawUserData['client_user_agent'];
        } elseif (! empty($rawUserData['user_agent'])) {
            $userData['client_user_agent'] = $rawUserData['user_agent'];
        }

        // 8. Meta Click ID (_fbc) (do not hash)
        if (! empty($rawUserData['fbc'])) {
            $userData['fbc'] = trim($rawUserData['fbc']);
        }

        // 9. Meta Browser ID (_fbp) (do not hash)
        if (! empty($rawUserData['fbp'])) {
            $userData['fbp'] = trim($rawUserData['fbp']);
        }

        // 10. External ID (hash if present)
        if (! empty($rawUserData['external_id'])) {
            $userData['external_id'] = [hash('sha256', (string) $rawUserData['external_id'])];
        }

        return $userData;
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

        // If local 11-digit starting with 01
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
