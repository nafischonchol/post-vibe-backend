<?php

namespace App\Services\Admin;

use App\Models\AiSetting;
use App\Contracts\AiServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AiSettingService
{
    public function __construct(protected AiServiceInterface $aiService) {}

    /**
     * Preset recommended models on OpenRouter.
     */
    public static function getPresetModels(): array
    {
        return [
            [
                'id' => 'google/gemini-2.5-flash',
                'name' => 'Google Gemini 2.5 Flash (Recommended)',
                'badge' => 'Fast & Affordable',
                'description' => 'Best speed-to-cost ratio for generating SEO slugs, blog articles, and product descriptions.',
            ],
            [
                'id' => 'openai/gpt-4o-mini',
                'name' => 'OpenAI GPT-4o Mini',
                'badge' => 'High Accuracy',
                'description' => 'Excellent reasoning and structured JSON output for blog writing.',
            ],
            [
                'id' => 'anthropic/claude-3.5-haiku',
                'name' => 'Anthropic Claude 3.5 Haiku',
                'badge' => 'Creative & Natural',
                'description' => 'Great for natural, human-like beauty blog copywriting and Bangla phrasing.',
            ],
            [
                'id' => 'meta-llama/llama-3.3-70b-instruct',
                'name' => 'Meta Llama 3.3 70B Instruct',
                'badge' => 'Open Weights',
                'description' => 'Powerful open model with high throughput and low pricing.',
            ],
            [
                'id' => 'deepseek/deepseek-chat',
                'name' => 'DeepSeek V3',
                'badge' => 'Ultra Low-Cost',
                'description' => 'Extremely cheap per token for batch tasks and summaries.',
            ],
        ];
    }

    /**
     * Get the current AI settings.
     */
    public function getSettings()
    {
        $setting = AiSetting::firstOrCreate(
            ['provider' => 'openrouter'],
            [
                'default_model' => 'google/gemini-2.0-flash-001',
                'is_enabled' => true,
            ]
        );

        $hasApiKey = ! empty(AiSetting::getActiveApiKey());
        $maskedKey = AiSetting::getMaskedApiKey();

        return responseSuccess([
            'id' => $setting->id,
            'provider' => $setting->provider,
            'has_api_key' => $hasApiKey,
            'masked_api_key' => $maskedKey,
            'default_model' => $setting->default_model,
            'is_enabled' => (bool) $setting->is_enabled,
            'site_url' => $setting->site_url ?: config('services.openrouter.site_url'),
            'site_name' => $setting->site_name ?: config('services.openrouter.site_name'),
            'preset_models' => self::getPresetModels(),
            'updated_at' => $setting->updated_at?->toISOString(),
        ]);
    }

    /**
     * Update AI settings.
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'api_key' => ['nullable', 'string', 'max:500'],
            'default_model' => ['required', 'string', 'max:255'],
            'is_enabled' => ['required', 'boolean'],
            'site_url' => ['nullable', 'string', 'max:255'],
            'site_name' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return responseError($validator->errors()->first(), 422);
        }

        $setting = AiSetting::firstOrCreate(['provider' => 'openrouter']);

        $data = [
            'default_model' => trim($request->input('default_model')),
            'is_enabled' => $request->boolean('is_enabled', true),
        ];

        if ($request->filled('site_url')) {
            $data['site_url'] = trim($request->input('site_url'));
        }
        if ($request->filled('site_name')) {
            $data['site_name'] = trim($request->input('site_name'));
        }

        // Only update API key if a new, unmasked key is provided
        $incomingKey = trim((string) $request->input('api_key'));
        if ($incomingKey !== '' && ! str_contains($incomingKey, '••••')) {
            $data['api_key'] = $incomingKey;
        }

        $setting->update($data);

        return responseSuccess([
            'message' => 'AI settings updated successfully.',
            'settings' => [
                'id' => $setting->id,
                'provider' => $setting->provider,
                'has_api_key' => ! empty(AiSetting::getActiveApiKey()),
                'masked_api_key' => AiSetting::getMaskedApiKey(),
                'default_model' => $setting->default_model,
                'is_enabled' => (bool) $setting->is_enabled,
            ],
        ]);
    }

    /**
     * Test OpenRouter connection with either saved or incoming credentials.
     */
    public function testConnection(Request $request)
    {
        $incomingKey = trim((string) $request->input('api_key'));
        $keyToTest = ($incomingKey !== '' && ! str_contains($incomingKey, '••••'))
            ? $incomingKey
            : null;

        $modelToTest = $request->input('default_model');

        $result = $this->aiService->testConnection($keyToTest, $modelToTest);

        if ($result['success']) {
            return responseSuccess($result);
        }

        return responseError($result['message'], 400, $result);
    }
}
