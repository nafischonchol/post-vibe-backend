<?php

namespace App\Services\Platform;

use App\Models\PlatformApplication;
use App\Models\PublishingSchedule;
use App\Models\TenantPlatformAccount;
use App\Models\Video;
use App\Models\VideoPlatformMetadata;
use App\Services\Storage\MediaStorageService;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookGateway implements PlatformGatewayInterface
{
    public function __construct(
        protected MediaStorageService $storage
    ) {}

    public function upload(
        TenantPlatformAccount $account,
        Video $video,
        VideoPlatformMetadata $metadata,
        PublishingSchedule $schedule
    ): array {
        $account = $this->refreshToken($account);
        $accessToken = $account->encrypted_access_token;

        if (str_starts_with($accessToken, 'mock_') || empty($accessToken)) {
            $reelId = 'fb_' . substr(md5(uniqid()), 0, 14);
            return [
                'external_post_id' => $reelId,
                'external_post_url' => "https://facebook.com/reel/{$reelId}",
                'raw' => ['simulated' => true, 'platform' => 'facebook'],
            ];
        }

        // Facebook Graph API v20.0 Reels Publishing Flow
        $pageId = $account->external_account_id ?: 'me';
        $caption = $metadata->caption ?: $video->original_caption ?: '';
        if (!empty($metadata->hashtags)) {
            $caption .= ' ' . implode(' ', array_map(fn($t) => str_starts_with($t, '#') ? $t : "#{$t}", $metadata->hashtags));
        }

        // 1. Initialize Reels Upload Session
        $initRes = Http::post("https://graph.facebook.com/v20.0/{$pageId}/video_reels", [
            'upload_phase' => 'start',
            'access_token' => $accessToken,
        ]);

        if (! $initRes->successful() || ! $initRes->json('video_id')) {
            throw new Exception("Facebook Reels initialization failed: " . $initRes->body());
        }

        $videoId = $initRes->json('video_id');
        $uploadUrl = $initRes->json('upload_url');

        // 2. Upload Video Binary
        $mediaBinary = $this->storage->getVideoBinary($video->temporary_storage_path);
        if (! $mediaBinary) {
            throw new Exception("Unable to load video binary from storage: {$video->temporary_storage_path}");
        }

        $uploadRes = Http::withHeaders([
            'Authorization' => "OAuth {$accessToken}",
            'offset' => '0',
            'file_size' => strlen($mediaBinary),
        ])->withBody($mediaBinary, 'application/octet-stream')->post($uploadUrl);

        if (! $uploadRes->successful()) {
            throw new Exception("Facebook video binary transfer failed: " . $uploadRes->body());
        }

        // 3. Finish / Publish Reels Phase
        $finishRes = Http::post("https://graph.facebook.com/v20.0/{$pageId}/video_reels", [
            'upload_phase' => 'finish',
            'access_token' => $accessToken,
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'description' => $caption,
        ]);

        if (! $finishRes->successful()) {
            throw new Exception("Facebook Reels publishing finish phase failed: " . $finishRes->body());
        }

        return [
            'external_post_id' => $videoId,
            'external_post_url' => "https://facebook.com/reel/{$videoId}",
            'raw' => $finishRes->json(),
        ];
    }

    public function reconcile(TenantPlatformAccount $account, string $externalPostId): array
    {
        $accessToken = $account->encrypted_access_token;
        if (str_starts_with($accessToken, 'mock_')) {
            return [
                'published' => true,
                'external_post_id' => $externalPostId,
                'external_post_url' => "https://facebook.com/reel/{$externalPostId}",
            ];
        }

        $res = Http::get("https://graph.facebook.com/v20.0/{$externalPostId}?fields=status&access_token={$accessToken}");

        if ($res->successful() && $res->json('status.video_status') === 'ready') {
            return [
                'published' => true,
                'external_post_id' => $externalPostId,
                'external_post_url' => "https://facebook.com/reel/{$externalPostId}",
            ];
        }

        return ['published' => false];
    }

    public function refreshToken(TenantPlatformAccount $account): TenantPlatformAccount
    {
        // Facebook long-lived tokens generally last 60 days
        if (!$account->isTokenExpiringSoon(60) || empty($account->encrypted_access_token) || str_starts_with($account->encrypted_access_token, 'mock_')) {
            return $account;
        }

        $app = PlatformApplication::where('platform', 'facebook')->first();
        if (!$app || empty($app->client_id) || empty($app->encrypted_client_secret)) {
            return $account;
        }

        $res = Http::get('https://graph.facebook.com/v20.0/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $app->client_id,
            'client_secret' => $app->encrypted_client_secret,
            'fb_exchange_token' => $account->encrypted_access_token,
        ]);

        if ($res->successful()) {
            $data = $res->json();
            $account->update([
                'encrypted_access_token' => $data['access_token'],
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 5184000), // ~60 days
                'last_validated_at' => now(),
            ]);
        } else {
            Log::warning("Facebook token exchange failed for account ID {$account->id}: " . $res->body());
        }

        return $account;
    }
}
