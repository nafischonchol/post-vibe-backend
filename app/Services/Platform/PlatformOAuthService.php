<?php

namespace App\Services\Platform;

use App\Models\PlatformApplication;
use App\Models\TenantPlatformAccount;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlatformOAuthService
{
    /**
     * Generate the OAuth authorization redirect URL for a specific platform.
     */
    public function getAuthorizationUrl(int $tenantId, string $platform, ?string $customRedirectUri = null): string
    {
        $app = PlatformApplication::where('platform', $platform)->first();
        $state = Str::random(40);

        // Store state in cache for 15 minutes to prevent CSRF
        Cache::put("oauth_state_{$state}", [
            'tenant_id' => $tenantId,
            'platform' => $platform,
            'created_at' => now()->timestamp,
        ], now()->addMinutes(15));

        $backendCallback = route('oauth.callback', ['platform' => $platform]);
        $redirectUri = $customRedirectUri ?: ($app?->redirect_uri ?: $backendCallback);

        // If credentials are not configured or simulated, return fallback simulation endpoint
        if (! $app || empty($app->client_id) || empty($app->encrypted_client_secret) || str_starts_with($app->client_id, 'mock_')) {
            return route('oauth.callback', [
                'platform' => $platform,
                'code' => 'mock_auth_code_' . Str::random(24),
                'state' => $state,
            ]);
        }

        return match ($platform) {
            'youtube' => $this->buildGoogleUrl($app->client_id, $redirectUri, $state),
            'tiktok' => $this->buildTikTokUrl($app->client_id, $redirectUri, $state),
            'facebook' => $this->buildFacebookUrl($app->client_id, $redirectUri, $state),
            default => throw new Exception("Unsupported OAuth platform: {$platform}"),
        };
    }

    /**
     * Handle the OAuth provider callback and persist credentials.
     */
    public function handleCallback(string $platform, string $code, string $state): array
    {
        $cachedState = Cache::pull("oauth_state_{$state}");

        if (! $cachedState || ($cachedState['platform'] ?? '') !== $platform) {
            throw new Exception("Invalid or expired OAuth state token.");
        }

        $tenantId = (int) $cachedState['tenant_id'];
        $app = PlatformApplication::where('platform', $platform)->first();
        $redirectUri = $app?->redirect_uri ?: route('oauth.callback', ['platform' => $platform]);

        // Simulated / Local Fallback Flow
        if (str_starts_with($code, 'mock_auth_code_') || ! $app || empty($app->client_id) || empty($app->encrypted_client_secret)) {
            return $this->createMockAccount($tenantId, $platform);
        }

        // Live Provider Exchange Flow
        return match ($platform) {
            'youtube' => $this->exchangeGoogleCode($tenantId, $app, $code, $redirectUri),
            'tiktok' => $this->exchangeTikTokCode($tenantId, $app, $code, $redirectUri),
            'facebook' => $this->exchangeFacebookCode($tenantId, $app, $code, $redirectUri),
            default => throw new Exception("Unsupported OAuth platform: {$platform}"),
        };
    }

    protected function buildGoogleUrl(string $clientId, string $redirectUri, string $state): string
    {
        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', [
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.readonly',
                'https://www.googleapis.com/auth/userinfo.profile',
            ]),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return "https://accounts.google.com/o/oauth2/v2/auth?{$params}";
    }

    protected function buildTikTokUrl(string $clientKey, string $redirectUri, string $state): string
    {
        $params = http_build_query([
            'client_key' => $clientKey,
            'scope' => 'user.info.basic,video.upload,video.publish',
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return "https://www.tiktok.com/v2/auth/authorize/?{$params}";
    }

    protected function buildFacebookUrl(string $clientId, string $redirectUri, string $state): string
    {
        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'pages_show_list,pages_read_engagement,pages_manage_posts,publish_video',
            'response_type' => 'code',
        ]);

        return "https://www.facebook.com/v20.0/dialog/oauth?{$params}";
    }

    protected function exchangeGoogleCode(int $tenantId, PlatformApplication $app, string $code, string $redirectUri): array
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $app->client_id,
            'client_secret' => $app->encrypted_client_secret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new Exception("Google token exchange failed: " . $response->body());
        }

        $tokenData = $response->json();
        $accessToken = $tokenData['access_token'];
        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresIn = $tokenData['expires_in'] ?? 3600;

        // Fetch YouTube channel details
        $channelRes = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true');

        $channel = $channelRes->json('items.0') ?? [];
        $channelTitle = $channel['snippet']['title'] ?? 'YouTube Creator';
        $channelHandle = $channel['snippet']['customUrl'] ?? ('@' . Str::slug($channelTitle));
        $avatar = $channel['snippet']['thumbnails']['default']['url'] ?? null;
        $channelId = $channel['id'] ?? ('yt_' . Str::random(10));

        $account = TenantPlatformAccount::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform' => 'youtube',
                'external_account_id' => $channelId,
            ],
            [
                'account_name' => $channelTitle,
                'account_handle' => $channelHandle,
                'avatar_url' => $avatar,
                'encrypted_access_token' => $accessToken,
                'encrypted_refresh_token' => $refreshToken,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'scopes' => explode(' ', $tokenData['scope'] ?? ''),
                'status' => 'active',
                'last_validated_at' => now(),
            ]
        );

        return ['status' => 'success', 'account' => $account];
    }

    protected function exchangeTikTokCode(int $tenantId, PlatformApplication $app, string $code, string $redirectUri): array
    {
        $res = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $app->client_id,
            'client_secret' => $app->encrypted_client_secret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (! $res->successful()) {
            throw new Exception("TikTok token exchange failed: " . $res->body());
        }

        $data = $res->json('data') ?? $res->json();
        $accessToken = $data['access_token'];
        $refreshToken = $data['refresh_token'] ?? null;
        $openId = $data['open_id'] ?? ('tt_' . Str::random(10));
        $expiresIn = $data['expires_in'] ?? 86400;

        // Fetch TikTok user profile
        $userRes = Http::withToken($accessToken)
            ->post('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => ['open_id', 'union_id', 'avatar_url', 'display_name'],
            ]);

        $userData = $userRes->json('data.user') ?? [];
        $displayName = $userData['display_name'] ?? 'TikTok Creator';
        $avatar = $userData['avatar_url'] ?? null;

        $account = TenantPlatformAccount::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform' => 'tiktok',
                'external_account_id' => $openId,
            ],
            [
                'account_name' => $displayName,
                'account_handle' => '@' . Str::slug($displayName),
                'avatar_url' => $avatar,
                'encrypted_access_token' => $accessToken,
                'encrypted_refresh_token' => $refreshToken,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'scopes' => explode(',', $data['scope'] ?? ''),
                'status' => 'active',
                'last_validated_at' => now(),
            ]
        );

        return ['status' => 'success', 'account' => $account];
    }

    protected function exchangeFacebookCode(int $tenantId, PlatformApplication $app, string $code, string $redirectUri): array
    {
        $tokenRes = Http::get('https://graph.facebook.com/v20.0/oauth/access_token', [
            'client_id' => $app->client_id,
            'client_secret' => $app->encrypted_client_secret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (! $tokenRes->successful()) {
            throw new Exception("Facebook token exchange failed: " . $tokenRes->body());
        }

        $shortLivedToken = $tokenRes->json('access_token');

        // Exchange for Long-Lived User Access Token (~60 days)
        $exchangeRes = Http::get('https://graph.facebook.com/v20.0/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $app->client_id,
            'client_secret' => $app->encrypted_client_secret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        $longLivedToken = $exchangeRes->json('access_token') ?: $shortLivedToken;
        $expiresIn = $exchangeRes->json('expires_in') ?: 5184000;

        // Fetch User's Pages
        $pagesRes = Http::get("https://graph.facebook.com/v20.0/me/accounts?access_token={$longLivedToken}");
        $page = $pagesRes->json('data.0') ?? [];

        $pageId = $page['id'] ?? ('fb_page_' . Str::random(10));
        $pageName = $page['name'] ?? 'Facebook Creator Page';
        $pageToken = $page['access_token'] ?? $longLivedToken;

        $account = TenantPlatformAccount::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform' => 'facebook',
                'external_account_id' => $pageId,
            ],
            [
                'account_name' => $pageName,
                'account_handle' => '@' . Str::slug($pageName),
                'avatar_url' => "https://graph.facebook.com/v20.0/{$pageId}/picture?type=normal",
                'encrypted_access_token' => $pageToken,
                'encrypted_refresh_token' => $longLivedToken,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'scopes' => ['pages_show_list', 'publish_video'],
                'status' => 'active',
                'last_validated_at' => now(),
            ]
        );

        return ['status' => 'success', 'account' => $account];
    }

    protected function createMockAccount(int $tenantId, string $platform): array
    {
        $platformNames = [
            'youtube' => ['name' => 'YouTube Channel Official', 'handle' => '@postvibe_yt', 'avatar' => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=150'],
            'tiktok' => ['name' => 'PostVibe Shorts', 'handle' => '@postvibe_tiktok', 'avatar' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150'],
            'facebook' => ['name' => 'Post Vibe Media Page', 'handle' => '@postvibepage', 'avatar' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=150'],
        ];

        $info = $platformNames[$platform] ?? ['name' => ucfirst($platform) . ' Account', 'handle' => '@' . $platform, 'avatar' => null];

        $account = TenantPlatformAccount::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform' => $platform,
                'account_name' => $info['name'],
            ],
            [
                'external_account_id' => $platform . '_' . Str::random(12),
                'account_handle' => $info['handle'],
                'avatar_url' => $info['avatar'],
                'encrypted_access_token' => 'mock_access_' . Str::random(32),
                'encrypted_refresh_token' => 'mock_refresh_' . Str::random(32),
                'token_expires_at' => now()->addDays(60),
                'scopes' => ['read', 'publish'],
                'status' => 'active',
                'last_validated_at' => now(),
            ]
        );

        return ['status' => 'success', 'account' => $account];
    }
}
