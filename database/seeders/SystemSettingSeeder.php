<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key' => 'video_content_analysis_enabled',
                'value' => false,
                'group' => 'ai',
            ],
            [
                'key' => 'default_ai_provider',
                'value' => 'openrouter',
                'group' => 'ai',
            ],
            [
                'key' => 'default_ai_model',
                'value' => 'google/gemini-2.5-flash',
                'group' => 'ai',
            ],
            [
                'key' => 'global_ai_spending_limit',
                'value' => 500.00, // Monthly dollar cap
                'group' => 'ai',
            ],
            [
                'key' => 'max_video_size_mb',
                'value' => 500, // 500MB
                'group' => 'publishing',
            ],
            [
                'key' => 'max_video_duration_seconds',
                'value' => 300, // 5 minutes max
                'group' => 'publishing',
            ],
            [
                'key' => 'upload_retry_limits',
                'value' => 3,
                'group' => 'publishing',
            ],
            [
                'key' => 'default_timezone',
                'value' => 'UTC',
                'group' => 'general',
            ],
        ];

        foreach ($settings as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
