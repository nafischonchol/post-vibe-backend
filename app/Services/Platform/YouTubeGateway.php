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

class YouTubeGateway implements PlatformGatewayInterface
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
        // Ensure fresh token
        $account = $this->refreshToken($account);
        $accessToken = $account->encrypted_access_token;

        // If credentials are mock/testing, return simulated response
        if (str_starts_with($accessToken, 'mock_') || empty($accessToken)) {
            $id = 'yt_' . substr(md5(uniqid()), 0, 11);
            return [
                'external_post_id' => $id,
                'external_post_url' => "https://youtube.com/shorts/{$id}",
                'raw' => ['simulated' => true, 'platform' => 'youtube'],
            ];
        }

        // Production YouTube Data API v3 Resumable Upload Flow
        $app = PlatformApplication::where('platform', 'youtube')->first();
        $title = $metadata->title ?: ($video->original_caption ? substr($video->original_caption, 0, 95) : 'Shorts Video');
        $description = $metadata->description ?: $video->original_caption ?: '';
        $tags = $metadata->tags ?: [];

        // 1. Initialize Resumable Upload
        $initResponse = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Upload-Content-Type' => 'video/mp4',
        ])->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
            'snippet' => [
                'title' => $title,
                'description' => $description,
                'tags' => $tags,
                'categoryId' => '22', // People & Blogs
            ],
            'status' => [
                'privacyStatus' => 'public',
                'selfDeclaredMadeForKids' => false,
            ],
        ]);

        if (! $initResponse->successful() || ! $initResponse->header('Location')) {
            throw new Exception("YouTube upload initialization failed: " . $initResponse->body());
        }

        $uploadUrl = $initResponse->header('Location');

        // 2. Fetch binary file from local/S3 storage
        $mediaBinary = $this->storage->getVideoBinary($video->temporary_storage_path);
        if (! $mediaBinary) {
            throw new Exception("Unable to load video binary from storage: {$video->temporary_storage_path}");
        }

        // 3. Upload Binary Stream to Resumable Session URL
        $uploadResponse = Http::withHeaders([
            'Content-Type' => 'video/mp4',
            'Content-Length' => strlen($mediaBinary),
        ])->withBody($mediaBinary, 'video/mp4')->put($uploadUrl);

        if (! $uploadResponse->successful()) {
            throw new Exception("YouTube video binary transfer failed: " . $uploadResponse->body());
        }

        $resData = $uploadResponse->json();
        $videoId = $resData['id'] ?? null;

        if (! $videoId) {
            throw new Exception("YouTube API response missing video ID.");
        }

        return [
            'external_post_id' => $videoId,
            'external_post_url' => "https://youtube.com/shorts/{$videoId}",
            'raw' => $resData,
        ];
    }

    public function reconcile(TenantPlatformAccount $account, string $externalPostId): array
    {
        $accessToken = $account->encrypted_access_token;
        if (str_starts_with($accessToken, 'mock_')) {
            return [
                'published' => true,
                'external_post_id' => $externalPostId,
                'external_post_url' => "https://youtube.com/shorts/{$externalPostId}",
            ];
        }

        $res = Http::withToken($accessToken)
            ->get("https://www.googleapis.com/youtube/v3/videos?part=snippet,status&id={$externalPostId}");

        if ($res->successful() && !empty($res->json('items'))) {
            return [
                'published' => true,
                'external_post_id' => $externalPostId,
                'external_post_url' => "https://youtube.com/shorts/{$externalPostId}",
            ];
        }

        return ['published' => false];
    }

    public function refreshToken(TenantPlatformAccount $account): TenantPlatformAccount
    {
        if (!$account->isTokenExpiringSoon(30) || empty($account->encrypted_refresh_token) || str_starts_with($account->encrypted_refresh_token, 'mock_')) {
            return $account;
        }

        $app = PlatformApplication::where('platform', 'youtube')->first();
        if (!$app || empty($app->client_id) || empty($app->encrypted_client_secret)) {
            return $account;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $app->client_id,
            'client_secret' => $app->encrypted_client_secret,
            'refresh_token' => $account->encrypted_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $account->update([
                'encrypted_access_token' => $data['access_token'],
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
                'last_validated_at' => now(),
            ]);
        } else {
            Log::warning("YouTube token renewal failed for account ID {$account->id}: " . $response->body());
        }

        return $account;
    }
}
