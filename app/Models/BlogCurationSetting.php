<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BlogCurationSetting extends Model
{
    use HasFactory;

    protected $table = 'blog_curation_settings';

    protected $fillable = [
        'daily_limit',
        'is_auto_curate_enabled',
    ];

    protected $casts = [
        'daily_limit' => 'integer',
        'is_auto_curate_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(function () {
            Cache::forget('blog_curation_setting');
        });

        static::deleted(function () {
            Cache::forget('blog_curation_setting');
        });
    }

    /**
     * Get or initialize the active blog curation settings.
     */
    public static function getSettings(): self
    {
        return Cache::remember('blog_curation_setting', 3600, function () {
            return static::firstOrCreate(
                ['id' => 1],
                [
                    'daily_limit' => 5,
                    'is_auto_curate_enabled' => true,
                ]
            );
        });
    }

    /**
     * Get the max allowed curated posts per day.
     */
    public static function getDailyLimit(): int
    {
        return static::getSettings()->daily_limit ?? 5;
    }

    /**
     * Check if automated curation is enabled.
     */
    public static function isAutoCurateEnabled(): bool
    {
        return static::getSettings()->is_auto_curate_enabled ?? true;
    }

    /**
     * Get the total count of AI curated blog posts published today.
     */
    public static function getTodayPublishedCount(): int
    {
        return BlogCuratedLog::query()
            ->where('status', 'published')
            ->whereDate('created_at', today())
            ->count();
    }

    /**
     * Get how many more posts can be published today.
     */
    public static function getRemainingToday(): int
    {
        $limit = static::getDailyLimit();
        if ($limit === 0) {
            return PHP_INT_MAX; // 0 means unlimited
        }

        $published = static::getTodayPublishedCount();
        return max(0, $limit - $published);
    }

    /**
     * Determine whether more articles can be curated today.
     */
    public static function canCurateMoreToday(): bool
    {
        if (! static::isAutoCurateEnabled()) {
            return false;
        }

        $limit = static::getDailyLimit();
        if ($limit === 0) {
            return true;
        }

        return static::getTodayPublishedCount() < $limit;
    }
}
