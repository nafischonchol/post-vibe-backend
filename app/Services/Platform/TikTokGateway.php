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

class TikTokGateway implements PlatformGatewayInterface
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

        if (empty($accessToken) || str_starts_with($accessToken, 'mock_')) {
            throw new Exception('TikTok credentials are missing or invalid. Provide a real OAuth access token.');
        }

        // TikTok Content Posting API v2
        $caption = $metadata->caption ?: $video->original_caption ?: '';
        if (!empty($metadata->hashtags)) {
            $caption .= ' ' . implode(' ', array_map(fn($t) => str_starts_with($t, '#') ? $t : "#{$t}", $metadata->hashtags));
        }

        // 1. Initialize Direct Video Post
        $mediaBinary = $this->storage->getVideoBinary($video->temporary_storage_path);
        if (! $mediaBinary) {
            throw new Exception("Unable to load video binary from storage: {$video->temporary_storage_path}");
        }

        // Query creator publishing info to dynamically match allowed privacy levels and interactions
        $app = PlatformApplication::where('platform', 'tiktok')->first();
        $isSandbox = ($app?->configuration['mode'] ?? '') === 'sandbox' || str_starts_with($app?->client_id ?? '', 'sb');
        $privacyLevel = $isSandbox ? 'SELF_ONLY' : 'PUBLIC_TO_EVERYONE';
        $disableComment = false;
        $disableDuet = false;
        $disableStitch = false;

        try {
            $creatorRes = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json; charset=UTF-8',
            ])->post('https://open.tiktokapis.com/v2/post/publish/creator_info/query/', (object) []);

            if ($creatorRes->successful() && $creatorRes->json('error.code') === 'ok') {
                $options = $creatorRes->json('data.privacy_level_options') ?? [];
                if (!in_array($privacyLevel, $options)) {
                    $privacyLevel = in_array('SELF_ONLY', $options) ? 'SELF_ONLY' : ($options[0] ?? 'SELF_ONLY');
                }
                $disableComment = (bool) $creatorRes->json('data.comment_disabled', false);
                $disableDuet = (bool) $creatorRes->json('data.duet_disabled', false);
                $disableStitch = (bool) $creatorRes->json('data.stitch_disabled', false);
            }
        } catch (\Throwable $e) {
            Log::warning("TikTok creator_info query failed: " . $e->getMessage());
        }

        $initRes = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type' => 'application/json; charset=UTF-8',
        ])->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
            'post_info' => [
                'title' => substr($caption, 0, 2200),
                'privacy_level' => $privacyLevel,
                'disable_duet' => $disableDuet,
                'disable_stitch' => $disableStitch,
                'disable_comment' => $disableComment,
                'video_cover_timestamp_ms' => 1000,
            ],
            'source_info' => [
                'source' => 'FILE_UPLOAD',
                'video_size' => strlen($mediaBinary),
                'chunk_size' => strlen($mediaBinary),
                'total_chunk_count' => 1,
            ],
        ]);

        // Auto-recover if rejected because client is in sandbox / unaudited
        if (! $initRes->successful() && $initRes->json('error.code') === 'unaudited_client_can_only_post_to_private_accounts' && $privacyLevel !== 'SELF_ONLY') {
            $privacyLevel = 'SELF_ONLY';
            $initRes = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json; charset=UTF-8',
            ])->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
                'post_info' => [
                    'title' => substr($caption, 0, 2200),
                    'privacy_level' => 'SELF_ONLY',
                    'disable_duet' => $disableDuet,
                    'disable_stitch' => $disableStitch,
                    'disable_comment' => $disableComment,
                    'video_cover_timestamp_ms' => 1000,
                ],
                'source_info' => [
                    'source' => 'FILE_UPLOAD',
                    'video_size' => strlen($mediaBinary),
                    'chunk_size' => strlen($mediaBinary),
                    'total_chunk_count' => 1,
                ],
            ]);
        }

        if (! $initRes->successful() || $initRes->json('error.code') !== 'ok') {
            throw new Exception("TikTok post initialization failed: " . $initRes->body());
        }

        $publishId = $initRes->json('data.publish_id');
        $uploadUrl = $initRes->json('data.upload_url');

        // 2. Put binary stream to TikTok upload URL
        $uploadRes = Http::withHeaders([
            'Content-Range' => 'bytes 0-' . (strlen($mediaBinary) - 1) . '/' . strlen($mediaBinary),
            'Content-Type' => 'video/mp4',
        ])->withBody($mediaBinary, 'video/mp4')->put($uploadUrl);

        if (! $uploadRes->successful()) {
            throw new Exception("TikTok binary transfer failed: " . $uploadRes->body());
        }

        $handle = ltrim($account->account_handle ?: 'creator', '@');

        return [
            'external_post_id' => $publishId,
            'external_post_url' => "https://www.tiktok.com/@{$handle}/video/{$publishId}",
            'raw' => $initRes->json(),
        ];
    }

    public function reconcile(TenantPlatformAccount $account, string $externalPostId): array
    {
        $accessToken = $account->encrypted_access_token;
        if (empty($accessToken) || str_starts_with($accessToken, 'mock_')) {
            throw new Exception('TikTok credentials are missing or invalid.');
        }

        $res = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type' => 'application/json',
        ])->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', [
            'publish_id' => $externalPostId,
        ]);

        if ($res->successful() && $res->json('data.status') === 'PUBLISH_COMPLETE') {
            $handle = ltrim($account->account_handle ?: 'creator', '@');
            return [
                'published' => true,
                'external_post_id' => $externalPostId,
                'external_post_url' => "https://www.tiktok.com/@{$handle}/video/{$externalPostId}",
            ];
        }

        return ['published' => false];
    }

    public function refreshToken(TenantPlatformAccount $account): TenantPlatformAccount
    {
        if (!$account->isTokenExpiringSoon(30) || empty($account->encrypted_refresh_token) || str_starts_with($account->encrypted_refresh_token, 'mock_')) {
            return $account;
        }

        $app = PlatformApplication::where('platform', 'tiktok')->first();
        if (!$app || empty($app->client_id) || empty($app->encrypted_client_secret)) {
            return $account;
        }

        $res = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $app->client_id,
            'client_secret' => $app->encrypted_client_secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->encrypted_refresh_token,
        ]);

        if ($res->successful()) {
            $data = $res->json('data') ?? $res->json();
            $account->update([
                'encrypted_access_token' => $data['access_token'],
                'encrypted_refresh_token' => $data['refresh_token'] ?? $account->encrypted_refresh_token,
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 86400),
                'last_validated_at' => now(),
            ]);
        } else {
            Log::warning("TikTok token renewal failed for account ID {$account->id}: " . $res->body());
        }

        return $account;
    }
}
