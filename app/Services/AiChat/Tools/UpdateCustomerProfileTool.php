<?php

namespace App\Services\AiChat\Tools;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateCustomerProfileTool
{
    public static function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_customer_profile',
                'description' => 'Save or update the customer\'s skincare and beauty profile preferences (skin type, skin concerns, preferred brands, budget preference, allergies/sensitivities, notes). Call this whenever a customer explicitly mentions their skin characteristics (e.g. "amar skin oily", "acne problem ache", "COSRX brand bhalo lage", "budget kom").',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'skin_type' => [
                            'type' => 'string',
                            'enum' => ['oily', 'dry', 'combination', 'sensitive', 'normal'],
                            'description' => 'Customer skin type if mentioned.',
                        ],
                        'concerns' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Customer skin concerns (e.g. ["acne", "dark_spots", "pores", "dullness", "pigmentation", "barrier_repair"]).',
                        ],
                        'preferred_brands' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Brands customer likes or specifically requested (e.g. ["COSRX", "Beauty of Joseon", "The Ordinary", "Anua", "Round Lab"]).',
                        ],
                        'budget_tier' => [
                            'type' => 'string',
                            'enum' => ['budget', 'mid_range', 'premium'],
                            'description' => 'Customer budget preference: budget, mid_range, or premium.',
                        ],
                        'allergies' => [
                            'type' => 'string',
                            'description' => 'Any ingredient sensitivities mentioned (e.g. "fragrance allergy", "niacinamide sensitivity").',
                        ],
                        'notes' => [
                            'type' => 'string',
                            'description' => 'Short memorable note about customer preferences (e.g. "prefers lightweight gel moisturizers").',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function execute(array $args, ?int $clientId = null): array
    {
        Log::debug("UpdateCustomerProfileTool executed", ['args' => $args, 'client_id' => $clientId]);

        if (!$clientId) {
            return [
                'success' => false,
                'message' => 'Guest customer. Preferences recorded in active conversation session.',
            ];
        }

        // Cache customer preferences for 30 days
        $cacheKey = "client_beauty_profile_{$clientId}";
        $existing = Cache::get($cacheKey, []);
        $merged = array_merge($existing, array_filter($args));
        Cache::put($cacheKey, $merged, 86400 * 30);

        return [
            'success' => true,
            'message' => 'Customer beauty profile updated successfully.',
            'profile' => $merged,
        ];
    }
}
