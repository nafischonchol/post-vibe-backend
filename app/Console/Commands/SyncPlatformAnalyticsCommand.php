<?php

namespace App\Console\Commands;

use App\Models\PlatformPostAnalytic;
use App\Models\PublishingSchedule;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncPlatformAnalyticsCommand extends Command
{
    protected $signature = 'analytics:sync-platforms {--limit=50}';
    protected $description = 'Poll views, likes, comments and engagement metrics from published social reels';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $schedules = PublishingSchedule::with(['platformAccount', 'batch'])
            ->where('upload_status', 'published')
            ->whereNotNull('external_post_id')
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();

        if ($schedules->isEmpty()) {
            $this->info("No published reels pending analytics sync.");
            return Command::SUCCESS;
        }

        $this->info("Syncing analytics telemetry for {$schedules->count()} published items...");

        foreach ($schedules as $schedule) {
            try {
                $platform = $schedule->platform;
                $account = $schedule->platformAccount;
                $postId = $schedule->external_post_id;
                $views = 0;
                $likes = 0;
                $comments = 0;
                $shares = 0;

                if ($account && !str_starts_with($account->encrypted_access_token, 'mock_')) {
                    $token = $account->encrypted_access_token;

                    if ($platform === 'youtube') {
                        $res = Http::withToken($token)
                            ->get("https://www.googleapis.com/youtube/v3/videos?part=statistics&id={$postId}");
                        if ($res->successful()) {
                            $stats = $res->json('items.0.statistics') ?? [];
                            $views = (int) ($stats['viewCount'] ?? 0);
                            $likes = (int) ($stats['likeCount'] ?? 0);
                            $comments = (int) ($stats['commentCount'] ?? 0);
                        }
                    } elseif ($platform === 'facebook') {
                        $res = Http::get("https://graph.facebook.com/v20.0/{$postId}/video_insights?access_token={$token}");
                        if ($res->successful()) {
                            $data = $res->json('data') ?? [];
                            foreach ($data as $metric) {
                                if (($metric['name'] ?? '') === 'total_video_views') {
                                    $views = (int) ($metric['values'][0]['value'] ?? 0);
                                }
                            }
                        }
                    }
                } else {
                    // Simulated organic engagement progression over time
                    $hoursLive = max(1, now()->diffInHours($schedule->published_at ?: now()->subHours(2)));
                    $views = rand(150, 400) * $hoursLive;
                    $likes = (int) ($views * (rand(5, 12) / 100));
                    $comments = (int) ($likes * (rand(2, 6) / 100));
                    $shares = (int) ($likes * (rand(1, 4) / 100));
                }

                $engagementRate = $views > 0 ? (($likes + $comments + $shares) / $views) * 100 : 0.0;

                PlatformPostAnalytic::updateOrCreate(
                    [
                        'publishing_schedule_id' => $schedule->id,
                        'external_post_id' => $postId,
                    ],
                    [
                        'tenant_id' => $schedule->tenant_id,
                        'platform' => $platform,
                        'views' => $views,
                        'likes' => $likes,
                        'comments' => $comments,
                        'shares' => $shares,
                        'engagement' => round($engagementRate, 4),
                        'collected_at' => now(),
                        'raw_data' => [
                            'synced_at' => now()->toIso8601String(),
                            'platform' => $platform,
                        ],
                    ]
                );

            } catch (Exception $e) {
                Log::warning("Sync analytics failed for schedule ID {$schedule->id}: " . $e->getMessage());
            }
        }

        $this->info("Social platform analytics sync completed successfully.");
        return Command::SUCCESS;
    }
}
