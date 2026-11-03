<?php

namespace App\Services\AiChat;

use App\Models\AiChatSetting;
use App\Models\AiSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    protected string $apiKey;
    protected string $defaultModel;
    protected string $siteUrl;
    protected string $siteName;

    public function __construct()
    {
        $dbKey = class_exists(AiSetting::class) ? AiSetting::getActiveApiKey() : null;
        $this->apiKey = $dbKey ?: (string) config('services.openrouter.api_key', env('OPENROUTER_API_KEY', ''));
        $this->defaultModel = (string) config('services.openrouter.default_model', 'google/gemini-2.5-flash');
        $this->siteUrl = (string) config('services.openrouter.site_url', 'http://localhost:3091');
        $this->siteName = (string) config('services.openrouter.site_name', 'Mohima');
    }

    /**
     * Send chat completion request to OpenRouter
     */
    public function chatCompletion(array $messages, array $tools = [], ?string $model = null, string|array|null $toolChoice = null, ?int $maxTokens = null): ?array
    {
        $dbKey = class_exists(AiSetting::class) ? AiSetting::getActiveApiKey() : null;
        $apiKey = $dbKey ?: $this->apiKey;

        if (empty($apiKey)) {
            Log::warning('OpenRouter API key is not configured.');
            return [
                'error' => 'API key is not configured in backend .env or AI Settings',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'AI সার্ভিসটি বর্তমানে অফলাইনে রয়েছে। অনুগ্রহ করে অ্যাডমিন সেটিংস থেকে OpenRouter API Key কনফিগার করুন।',
                        ],
                    ],
                ],
            ];
        }

        $activeSetting = AiChatSetting::getActiveSetting();
        $selectedModel = $model ?: ($activeSetting->model_name ?: $this->defaultModel);

        $payload = [
            'model' => $selectedModel,
            'messages' => $messages,
            'temperature' => (float) $activeSetting->temperature,
            'max_tokens' => $maxTokens ?: (int) $activeSetting->max_tokens,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $toolChoice ?: 'auto';
        }

        // Speed: route to the lowest-latency provider and skip hidden "thinking" tokens
        // (chat replies are short, tool-driven answers that don't need long reasoning).
        $payload['provider'] = ['sort' => 'latency'];
        $payload['reasoning'] = ['effort' => 'none'];

        try {
            $send = fn (array $body) => Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'HTTP-Referer' => $this->siteUrl,
                'X-Title' => $this->siteName,
                'Content-Type' => 'application/json',
            ])
            ->timeout(45)
            ->post('https://openrouter.ai/api/v1/chat/completions', $body);

            $started = microtime(true);
            $response = $send($payload);

            // Some models cannot turn reasoning off and reject the request — retry with defaults.
            if ($response->clientError()) {
                Log::info('OpenRouter rejected speed options for ' . $selectedModel . ', retrying with defaults: ' . $response->status());
                unset($payload['reasoning'], $payload['provider']);
                $response = $send($payload);
            }

            Log::info(sprintf('OpenRouter %s responded in %.1fs (status %d)', $selectedModel, microtime(true) - $started, $response->status()));

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('OpenRouter API error: ' . $response->status() . ' - ' . $response->body());
            return [
                'error' => 'OpenRouter returned status ' . $response->status(),
                'details' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('OpenRouter request exception: ' . $e->getMessage());
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch list of available models from OpenRouter API with caching
     */
    public function getAvailableModels(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('openrouter_available_models', 3600 * 12, function () {
            $curated = [
                [
                    'id' => 'google/gemini-2.5-flash',
                    'name' => 'Google: Gemini 2.5 Flash',
                    'provider' => 'Google',
                    'context_length' => 1000000,
                    'description' => 'Fastest, economical, excellent function calling & multilingual support.',
                    'recommended' => true,
                    'tag' => 'Best All-Round',
                ],
                [
                    'id' => 'openai/gpt-4o-mini',
                    'name' => 'OpenAI: GPT-4o Mini',
                    'provider' => 'OpenAI',
                    'context_length' => 128000,
                    'description' => 'Fast, compact, and high performance for customer support.',
                    'recommended' => true,
                    'tag' => 'Popular & Fast',
                ],
                [
                    'id' => 'anthropic/claude-3.5-sonnet',
                    'name' => 'Anthropic: Claude 3.5 Sonnet',
                    'provider' => 'Anthropic',
                    'context_length' => 200000,
                    'description' => 'Superior reasoning, natural conversational tone, exceptional Bengali understanding.',
                    'recommended' => true,
                    'tag' => 'Highest Quality',
                ],
                [
                    'id' => 'deepseek/deepseek-chat',
                    'name' => 'DeepSeek: DeepSeek V3',
                    'provider' => 'DeepSeek',
                    'context_length' => 64000,
                    'description' => 'Extremely cost-efficient model with strong general capabilities.',
                    'recommended' => true,
                    'tag' => 'Ultra Low Cost',
                ],
                [
                    'id' => 'openai/gpt-4o',
                    'name' => 'OpenAI: GPT-4o',
                    'provider' => 'OpenAI',
                    'context_length' => 128000,
                    'description' => 'Flagship OpenAI model with versatile reasoning and tool use.',
                    'recommended' => false,
                    'tag' => 'Flagship',
                ],
                [
                    'id' => 'google/gemini-2.5-pro',
                    'name' => 'Google: Gemini 2.5 Pro',
                    'provider' => 'Google',
                    'context_length' => 2000000,
                    'description' => 'High-capacity model capable of deep context comprehension.',
                    'recommended' => false,
                    'tag' => 'Deep Reasoning',
                ],
            ];

            try {
                $response = Http::timeout(10)->get('https://openrouter.ai/api/v1/models');
                if ($response->successful()) {
                    $remoteModels = $response->json('data') ?? [];
                    $curatedIds = array_column($curated, 'id');

                    $formattedRemote = [];
                    foreach ($remoteModels as $m) {
                        $id = $m['id'] ?? '';
                        if (in_array($id, $curatedIds)) continue;

                        $providerParts = explode('/', $id);
                        $provider = ucfirst($providerParts[0] ?? 'Other');

                        $promptPrice = isset($m['pricing']['prompt']) ? (float) $m['pricing']['prompt'] * 1000000 : 0;
                        $completionPrice = isset($m['pricing']['completion']) ? (float) $m['pricing']['completion'] * 1000000 : 0;

                        $formattedRemote[] = [
                            'id' => $id,
                            'name' => $m['name'] ?? $id,
                            'provider' => $provider,
                            'context_length' => $m['context_length'] ?? 0,
                            'description' => $m['description'] ?? '',
                            'prompt_price_per_1m' => round($promptPrice, 4),
                            'completion_price_per_1m' => round($completionPrice, 4),
                            'recommended' => false,
                        ];
                    }

                    return array_merge($curated, $formattedRemote);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch remote OpenRouter models: ' . $e->getMessage());
            }

            return $curated;
        });
    }
}
