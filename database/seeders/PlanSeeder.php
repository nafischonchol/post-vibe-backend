<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanPlatformQuota;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'price_monthly' => 29.00,
                'price_yearly' => 290.00,
                'global_daily_quota' => 10,
                'team_member_limit' => 1,
                'storage_limit_mb' => 5120, // 5GB
                'features' => [
                    'video_analysis' => false,
                    'automatic_publishing' => true,
                    'analytics' => 'basic',
                ],
                'quotas' => [
                    'youtube' => 10,
                    'tiktok' => 10,
                    'facebook' => 10,
                ],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'price_monthly' => 79.00,
                'price_yearly' => 790.00,
                'global_daily_quota' => 50,
                'team_member_limit' => 3,
                'storage_limit_mb' => 20480, // 20GB
                'features' => [
                    'video_analysis' => true,
                    'automatic_publishing' => true,
                    'analytics' => 'advanced',
                ],
                'quotas' => [
                    'youtube' => 50,
                    'tiktok' => 30,
                    'facebook' => 40,
                ],
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'price_monthly' => 199.00,
                'price_yearly' => 1990.00,
                'global_daily_quota' => 200,
                'team_member_limit' => 10,
                'storage_limit_mb' => 102400, // 100GB
                'features' => [
                    'video_analysis' => true,
                    'automatic_publishing' => true,
                    'analytics' => 'advanced',
                    'priority_queue' => true,
                ],
                'quotas' => [
                    'youtube' => 200,
                    'tiktok' => 100,
                    'facebook' => 150,
                ],
            ],
        ];

        foreach ($plans as $planData) {
            $quotas = $planData['quotas'];
            unset($planData['quotas']);

            $plan = Plan::updateOrCreate(
                ['slug' => $planData['slug']],
                $planData
            );

            foreach ($quotas as $platform => $dailyQuota) {
                PlanPlatformQuota::updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'platform' => $platform,
                    ],
                    [
                        'daily_quota' => $dailyQuota,
                        'enabled' => true,
                    ]
                );
            }
        }
    }
}
