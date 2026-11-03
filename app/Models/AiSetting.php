<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AiSetting extends Model
{
    use HasFactory;

    protected $table = 'ai_settings';

    protected $fillable = [
        'provider',
        'api_key',
        'default_model',
        'is_enabled',
        'site_url',
        'site_name',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(function () {
            Cache::forget('active_ai_setting');
        });

        static::deleted(function () {
            Cache::forget('active_ai_setting');
        });
    }

    /**
     * Get the active setting instance (cached for 1 hour).
     */
    public static function getActiveSetting(): ?self
    {
        return Cache::remember('active_ai_setting', 3600, function () {
            return static::where('provider', 'openrouter')->first();
        });
    }

    /**
     * Resolve the effective API key strictly from the database setting.
     */
    public static function getActiveApiKey(): ?string
    {
        $setting = static::getActiveSetting();

        return ($setting && ! empty($setting->api_key)) ? $setting->api_key : null;
    }

    /**
     * Resolve the effective default model strictly from database setting.
     */
    public static function getActiveModel(): string
    {
        $setting = static::getActiveSetting();

        return ($setting && ! empty($setting->default_model))
            ? $setting->default_model
            : 'google/gemini-2.0-flash-001';
    }

    /**
     * Check if AI functionality is globally enabled.
     */
    public static function isAiEnabled(): bool
    {
        $setting = static::getActiveSetting();
        if ($setting !== null) {
            return (bool) $setting->is_enabled;
        }

        return true;
    }

    /**
     * Return a masked version of the active API key for display in Admin UI.
     */
    public static function getMaskedApiKey(): ?string
    {
        $key = static::getActiveApiKey();
        if (! $key) {
            return null;
        }

        $length = strlen($key);
        if ($length <= 8) {
            return '••••••••';
        }

        $prefix = substr($key, 0, 7); // e.g. "sk-or-v"
        $suffix = substr($key, -4);   // last 4 chars

        return $prefix . '••••••••' . $suffix;
    }
}
