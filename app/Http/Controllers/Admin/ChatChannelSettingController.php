<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChatChannelEnum;
use App\Http\Controllers\Controller;
use App\Models\ChatChannelSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatChannelSettingController extends Controller
{
    /**
     * Get omnichannel settings with masked credentials.
     */
    public function index(): JsonResponse
    {
        $channels = [ChatChannelEnum::FACEBOOK, ChatChannelEnum::WHATSAPP];
        $settings = [];

        foreach ($channels as $channel) {
            $record = ChatChannelSetting::where('channel', $channel->value)->first();

            $settings[$channel->value] = [
                'channel' => $channel->value,
                'is_enabled' => (bool) ($record?->is_enabled ?? false),
                'credentials' => $record ? $record->getMaskedCredentials() : [],
                'updated_at' => $record?->updated_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $settings,
            'resources' => $settings,
        ]);
    }

    /**
     * Update settings & encrypted credentials for a channel.
     */
    public function update(Request $request, string $channel): JsonResponse
    {
        $validChannels = [ChatChannelEnum::FACEBOOK->value, ChatChannelEnum::WHATSAPP->value];
        if (!in_array($channel, $validChannels, true)) {
            return response()->json(['message' => 'Invalid channel specified.'], 422);
        }

        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
            'credentials' => 'nullable|array',
        ]);

        $setting = ChatChannelSetting::firstOrNew(['channel' => $channel]);

        $currentCreds = is_array($setting->credentials) ? $setting->credentials : [];
        $newCreds = $validated['credentials'] ?? [];

        // Preserve existing secret values if user sent masked values or didn't change them
        foreach ($newCreds as $key => $val) {
            if (is_string($val) && str_contains($val, '••••••••')) {
                // Keep the existing value
                $newCreds[$key] = $currentCreds[$key] ?? '';
            }
        }

        // Merge newly entered values over existing credentials
        $mergedCreds = array_merge($currentCreds, $newCreds);

        $setting->is_enabled = $validated['is_enabled'];
        $setting->credentials = $mergedCreds;
        $setting->save();

        $resultData = [
            'channel' => $setting->channel instanceof ChatChannelEnum ? $setting->channel->value : (string) $setting->channel,
            'is_enabled' => $setting->is_enabled,
            'credentials' => $setting->getMaskedCredentials(),
        ];

        return response()->json([
            'success' => true,
            'message' => ucfirst($channel) . ' channel settings updated successfully.',
            'data' => $resultData,
            'resources' => $resultData,
        ]);
    }
}
