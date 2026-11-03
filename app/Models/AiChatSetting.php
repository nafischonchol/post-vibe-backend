<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiChatSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_name',
        'system_prompt',
        'is_ai_enabled',
        'temperature',
        'max_tokens',
    ];

    protected $casts = [
        'is_ai_enabled' => 'boolean',
        'temperature' => 'float',
        'max_tokens' => 'integer',
    ];

    public static function getActiveSetting(): self
    {
        return static::firstOrCreate([], [
            'model_name' => config('services.openrouter.default_model', 'google/gemini-2.5-flash'),
            'system_prompt' => "You are an intelligent, friendly AI beauty advisor and shopping assistant for 'Mohima' (a premier Korean beauty & authentic luxury skincare store in Bangladesh).
Your goals:
1. Help customers find authentic skincare and beauty products tailored to their skin type (oily, dry, sensitive, acne-prone, combination), skin concerns, and budget.
2. Answer customer questions about products, availability, ingredients, and prices in Bengali (বাংলা), Banglish, or English depending on how the customer speaks.
3. Help customers track their orders and courier delivery status when asked.
4. If the customer requests speaking to a human agent, politely inform them you can connect them to customer support.
5. Use available tools (search_products, get_product_details, get_client_orders, etc.) whenever product data or order data is needed. Never make up fake product prices or stock.
6. Currency is Bangladeshi Taka (৳ BDT). All products at Mohima are 100% authentic and imported.",
            'is_ai_enabled' => true,
            'temperature' => 0.3,
            'max_tokens' => 1000,
        ]);
    }
}
