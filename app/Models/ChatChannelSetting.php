<?php

namespace App\Models;

use App\Enums\ChatChannelEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ChatChannelSetting extends Model
{
    use HasFactory;

    protected $table = 'chat_channel_settings';

    protected $fillable = [
        'channel',
        'is_enabled',
        'credentials',
    ];

    protected $casts = [
        'channel' => ChatChannelEnum::class,
        'is_enabled' => 'boolean',
        'credentials' => 'encrypted:array',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $model) {
            $key = $model->channel instanceof ChatChannelEnum ? $model->channel->value : (string) $model->channel;
            Cache::forget("chat_channel_setting_{$key}");
            Cache::forget('chat_channel_settings_all');
        });

        static::deleted(function (self $model) {
            $key = $model->channel instanceof ChatChannelEnum ? $model->channel->value : (string) $model->channel;
            Cache::forget("chat_channel_setting_{$key}");
            Cache::forget('chat_channel_settings_all');
        });
    }

    /**
     * Get active channel setting with credentials (cached for 1 hour).
     */
    public static function getSettingForChannel(ChatChannelEnum|string $channel): ?self
    {
        $channelVal = $channel instanceof ChatChannelEnum ? $channel->value : (string) $channel;

        return Cache::remember("chat_channel_setting_{$channelVal}", 3600, function () use ($channelVal) {
            return static::where('channel', $channelVal)->first();
        });
    }

    /**
     * Check if a specific channel is enabled.
     */
    public static function isChannelEnabled(ChatChannelEnum|string $channel): bool
    {
        $setting = static::getSettingForChannel($channel);

        return (bool) ($setting?->is_enabled ?? false);
    }

    /**
     * Get credentials for a channel safely.
     */
    public static function getCredentials(ChatChannelEnum|string $channel): array
    {
        $setting = static::getSettingForChannel($channel);

        return is_array($setting?->credentials) ? $setting->credentials : [];
    }

    /**
     * Mask sensitive strings (e.g. "EAAG..." -> "EAAG••••••••xyz").
     */
    public static function maskSecret(?string $secret): string
    {
        if (empty($secret)) {
            return '';
        }

        $len = strlen($secret);
        if ($len <= 8) {
            return '••••••••';
        }

        $prefix = substr($secret, 0, 4);
        $suffix = substr($secret, -4);

        return $prefix . '••••••••' . $suffix;
    }

    /**
     * Return masked credentials safe for Admin UI display.
     */
    public function getMaskedCredentials(): array
    {
        $raw = is_array($this->credentials) ? $this->credentials : [];
        $masked = [];

        foreach ($raw as $key => $val) {
            if (is_string($val)) {
                // Keep public identifiers (like page_id, phone_number_id) readable or partially visible
                if (in_array($key, ['page_id', 'phone_number_id', 'business_account_id'])) {
                    $masked[$key] = $val;
                } else {
                    $masked[$key] = static::maskSecret($val);
                }
            } else {
                $masked[$key] = $val;
            }
        }

        return $masked;
    }
}
