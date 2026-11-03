<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AI\OpenRouterService;
use Illuminate\Http\Request;

class SeoAiGeneratorController extends Controller
{
    public function __construct(protected OpenRouterService $openRouterService) {}

    /**
     * Generate SEO bottom buying guide content and FAQs using OpenRouter AI.
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:category,brand'],
            'name' => ['required', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'in:both,en,bn'],
            'prompt' => ['nullable', 'string', 'max:1000'],
            'keywords' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $this->openRouterService->isConfigured()) {
            return responseError('OpenRouter AI is not configured or disabled. Please enable it in Settings > AI Setup.', 400);
        }

        try {
            $result = $this->openRouterService->generateSeoContent(
                type: $validated['type'],
                name: $validated['name'],
                options: [
                    'language' => $validated['language'] ?? 'both',
                    'prompt' => $validated['prompt'] ?? '',
                    'keywords' => $validated['keywords'] ?? '',
                ]
            );

            return responseSuccess($result, 'SEO content and FAQs generated successfully.');
        } catch (\Exception $e) {
            return responseError('AI Generation failed: ' . $e->getMessage(), 500);
        }
    }
}
