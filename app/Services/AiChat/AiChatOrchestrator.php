<?php

namespace App\Services\AiChat;

use App\Enums\ChatAgentModeEnum;
use App\Enums\ChatSenderTypeEnum;
use App\Events\ChatMessageSent;
use App\Jobs\GenerateConversationSummaryJob;
use App\Models\AiChatSetting;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\StoreSetup;
use App\Services\AiChat\Tools\ActiveOffersTool;
use App\Services\AiChat\Tools\AddToCartTool;
use App\Services\AiChat\Tools\CompareProductsTool;
use App\Services\AiChat\Tools\CustomerHistoryTool;
use App\Services\AiChat\Tools\DeliveryChargeTool;
use App\Services\AiChat\Tools\OrderTrackingTool;
use App\Services\AiChat\Tools\ProductDetailTool;
use App\Services\AiChat\Tools\ProductSearchTool;
use App\Services\AiChat\Tools\ProductStockCheckTool;
use App\Services\AiChat\Tools\SupportFaqTool;
use App\Services\AiChat\Tools\UpdateCustomerProfileTool;
use Illuminate\Support\Facades\Log;

class AiChatOrchestrator
{
    protected OpenRouterService $openRouter;

    public function __construct(OpenRouterService $openRouter)
    {
        $this->openRouter = $openRouter;
    }

    protected function normalizeArrayLike(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->toArray();
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return array_values($value->toArray());
        }

        return [];
    }

    protected function compactPayloadForBroadcast(array $payload): array
    {
        if (empty($payload)) {
            return $payload;
        }

        $compact = $payload;

        if (!empty($compact['products'])) {
            $compact['products'] = array_slice($this->normalizeArrayLike($compact['products']), 0, 3);
        }

        if (!empty($compact['product_details']['variants'])) {
            $compact['product_details']['variants'] = array_slice(
                $this->normalizeArrayLike($compact['product_details']['variants']),
                0,
                3
            );
        }

        if (!empty($compact['faqs'])) {
            $compact['faqs'] = array_slice($this->normalizeArrayLike($compact['faqs']), 0, 5);
        }

        if (!empty($compact['orders'])) {
            $compact['orders'] = array_slice($this->normalizeArrayLike($compact['orders']), 0, 2);
        }

        return $compact;
    }

    /**
     * Handle incoming message in a conversation.
     * If agent_mode is 'ai', generates AI reply and broadcasts it.
     */
    public function handleCustomerMessage(ChatConversation $conversation, ChatMessage $incomingMessage): ?ChatMessage
    {
        $conversation->update(['last_message_at' => now()]);

        if ($conversation->agent_mode !== ChatAgentModeEnum::AI) {
            return null;
        }

        $setting = AiChatSetting::getActiveSetting();
        if (!$setting->is_ai_enabled) {
            return null;
        }

        return $this->generateAiResponse($conversation);
    }

    /**
     * Detect customer message intent to route tool calling deterministically.
     */
    public function detectIntent(?ChatMessage $message): string
    {
        if (!$message) {
            return 'general';
        }

        if ($message->file_url && $message->file_type === 'image') {
            $text = mb_strtolower(trim($message->message ?? ''));
            if (preg_match('/nite chai|nibo|kinbo|কিনবো|নিবো|নিতে চাই|কিনতে চাই|কিনতে|চাই|want to buy|want|buy|order|লাগবে|দেন|দিন|পাঠান|chai|need|purchase/iu', $text)) {
                return 'image_purchase';
            }
            if (preg_match('/benefit|ki\s*kaj|ki\s*kore|kaj\s*ki|কি\s*কাজ|কি\s*করে|কাজ\s*কি|upokarita|উপকারিতা|kivabe|কিভাবে|keno|কেন|কেনো|use\s*korbo|how\s*to|কাজ\s*করে/iu', $text)) {
                return 'image_benefit';
            }
            return 'image_search';
        }

        $text = mb_strtolower(trim($message->message ?? ''));
        if ($text === '') {
            return 'general';
        }

        // -1. Human Support / Agent Takeover Intent
        if (preg_match('/(মানুষ|মানুষের|manush|agent|human|representative|support\s*team|support\s*agent|কথা\s*বলতে\s*চাই|কথা\s*বলব|কথা\s*বলবো|talk\s*to\s*human|customer\s*care)/iu', $text)) {
            return 'human_support';
        }

        // 0. Contact / phone / WhatsApp / address request
        if (preg_match('/phone|fon|number|nambar|nombor|নাম্বার|নম্বর|ফোন|whats\s*app|হোয়াটসঅ্যাপ|hotline|হটলাইন|contact|যোগাযোগ|address|ঠিকানা|email|ইমেইল|office|অফিস/iu', $text)
            && !preg_match('/order|অর্ডার|invoice|track|ট্র্যাক|parcel|পার্সেল/iu', $text)) {
            return 'contact';
        }

        // 1. Delivery Time / Duration Intent
        if (preg_match('/kobe pabo|koto dine|kotodine|koto din|kotodin|কত দিনে|কবে পাব|কয়দিনে পাব|সময় লাগবে|delivery time|diner modde/iu', $text) && !preg_match('/charge|খরচ|চার্জ|টাকা|rate/iu', $text)) {
            return 'delivery_time';
        }

        // 2. Delivery Charge & Shipping Fees Intent
        if (preg_match('/delivery|shipping|charge|ডেলিভারি|শিপিং|চার্জ|খরচ|কুরিয়ার|courier|cod|cash on delivery/iu', $text)) {
            return 'delivery_charge';
        }

        // 3. Active Offers / Discounts / Coupons
        if (preg_match('/offer|discount|coupon|promo|voucher|chhar|ছাড়|অফার|ডিসকাউন্ট|কুপন|first order/iu', $text)) {
            return 'offer';
        }

        // 4. Add to Cart / Buying Intent
        if (preg_match('/cart|কার্ট|🛒/iu', $text) || (preg_match('/nibo|kinbo|order|কিনবো|নিবো|অর্ডার/iu', $text) && preg_match('/add|যোগ|কার্ট|এইটা|aita|eta/iu', $text))) {
            return 'add_to_cart';
        }

        // 5. Order Tracking Intent
        if (preg_match('/track|tracking|parcel|ট্র্যাক|পার্সেল|অর্ডার স্ট্যাটাস|invoice/iu', $text) && !preg_match('/delivery charge|ডেলিভারি চার্জ/iu', $text)) {
            return 'order_track';
        }

        // 6. Stock Check Intent for specific product
        if (preg_match('/stock ache|stock e ache|available ache|stock koto|stock ase|available ase|স্টক আছে|স্টকে আছে|কয়টা আছে|কয়টি আছে/iu', $text)) {
            return 'stock_check';
        }

        // 7. Product Comparison Intent
        if (preg_match('/compare|parthokko|পার্থক্য|তুলনা|konta bhalo|konta nibo|konta better|konta kinbo|vs|versus|difference/iu', $text)) {
            return 'compare_products';
        }

        // 8. Reorder / Past Purchase Intent
        if (preg_match('/abar|আবার|last order|আগের|আগেরবার|পুরনো|reorder|re-order|ager bar|previous order/iu', $text)
            && preg_match('/nibo|kinbo|chai|চাই|কিনবো|নিবো|order|লাগবে|nite chai/iu', $text)) {
            return 'reorder';
        }

        // 9. Store Policies & Support FAQ Intent
        if (preg_match('/authentic|original|অরিজিনাল|নকল|fake|asol|return|refund|রিটার্ন|ফেরত|damage|ভাঙা|unboxing|payment|পেমেন্ট|bkash|nagad|bank transfer|free delivery|free shipping|policy|পলিসি|check|পার্সেল দেখে/iu', $text)) {
            return 'support_faq';
        }

        // 10. Specific Product Benefit / Review / Inquiry Intent
        if (preg_match('/kemon|কেমন|benefit|ki\s*kaj|ki\s*kore|kaj\s*ki|কি\s*কাজ|কি\s*করে|কাজ\s*কি|upokarita|উপকারিতা|kivabe|কিভাবে|use\s*korbo|how\s*to/iu', $text)) {
            return 'product_benefit';
        }

        // 11. Simple Greeting Intent
        $isPureGreeting = preg_match('/^(hi|hello|hey|salam|assalamu alaikum|kemon achen|হাই|হ্যালো|সালাম|আসসালামু আলাইকুম|কেমন আছেন)[!?. ]*$/iu', $text);
        if ($isPureGreeting) {
            return 'greeting';
        }

        return 'product_search';
    }

    /**
     * Generate AI response using OpenRouter and tools
     */
    public function generateAiResponse(ChatConversation $conversation): ?ChatMessage
    {
        $setting = AiChatSetting::getActiveSetting();
        $client = $conversation->client;

        // Fetch latest customer message to detect intent early
        $latestCustomerMsg = $conversation->messages()
            ->where('sender_type', ChatSenderTypeEnum::CUSTOMER)
            ->latest('id')
            ->first();

        $intent = $this->detectIntent($latestCustomerMsg);

        // FAST-PATH 1: Instant Human Support Hand-off (Zero LLM call)
        if ($intent === 'human_support') {
            $conversation->update(['agent_mode' => ChatAgentModeEnum::HUMAN]);
            broadcast(new \App\Events\ChatConversationUpdated($conversation))->toOthers();

            $replyText = "অবশ্যই! আপনাকে আমাদের কাস্টমার সাপোর্ট টিমের সাথে কানেক্ট করে দেওয়া হয়েছে 👨‍💼\nআমাদের একজন প্রতিনিধি শীঘ্রই আপনার সাথে কথা বলবেন। আপনার যেকোনো প্রশ্ন বা সমস্যা এখানে লিখে রাখতে পারেন 🙏\n\n[SUGGESTIONS: 📦 আমার অর্ডার ট্র্যাক করুন | 🛒 অফারগুলো দেখুন | 🚚 ডেলিভারি চার্জ কত?]";

            $handoffMsg = $conversation->messages()->create([
                'sender_type' => ChatSenderTypeEnum::AI,
                'message' => $replyText,
                'payload' => ['suggested_replies' => ['📦 আমার অর্ডার ট্র্যাক করুন', '🛒 অফারগুলো দেখুন', '🚚 ডেলিভারি চার্জ কত?']],
                'is_read' => false,
            ]);

            $conversation->update(['last_message_at' => now()]);
            broadcast(new ChatMessageSent($handoffMsg));
            return $handoffMsg;
        }

        // FAST-PATH 2: Instant Contact / Hotline / Showroom response (Zero LLM call)
        if ($intent === 'contact') {
            $store = StoreSetup::first();
            $contactLines = array_filter([
                $store?->phone ? "📞 **হটলাইন / কল / WhatsApp:** {$store->phone}" : null,
                $store?->email ? "✉️ **ইমেইল:** {$store->email}" : null,
                $store?->street_address ? "🏢 **শোরুম / অফিস ঠিকানা:** {$store->street_address}" : null,
                $store?->facebook ? "🌐 **ফেসবুক পেজ:** {$store->facebook}" : null,
            ]);
            $storeFormatted = $contactLines ? implode("\n", $contactLines) : "📞 হটলাইন: আমাদের ফেসবুক পেজ বা হেল্পলাইনে যোগাযোগ করুন।";

            $replyText = "মোহিমার সাথে যোগাযোগের অফিশিয়াল তথ্য নিচে দেওয়া হলো 🌸\n\n{$storeFormatted}\n\nসকাল ১০টা থেকে রাত ১০টা পর্যন্ত যেকোনো তথ্যের জন্য আমাদের সাপোর্ট টিম সক্রিয় রয়েছে ✨\n\n[SUGGESTIONS: ✨ জনপ্রিয় স্কিনকেয়ার | 🚚 ডেলিভারি চার্জ কত? | 📦 অর্ডার ট্র্যাক করুন]";

            $contactMsg = $conversation->messages()->create([
                'sender_type' => ChatSenderTypeEnum::AI,
                'message' => $replyText,
                'payload' => ['suggested_replies' => ['✨ জনপ্রিয় স্কিনকেয়ার', '🚚 ডেলিভারি চার্জ কত?', '📦 অর্ডার ট্র্যাক করুন']],
                'is_read' => false,
            ]);

            $conversation->update(['last_message_at' => now()]);
            broadcast(new ChatMessageSent($contactMsg));
            return $contactMsg;
        }

        // 1. Build Customer Context
        $customerInfo = 'Guest/Browsing (Not logged in)';
        if ($client) {
            $totalOrders = $client->orders()->count();
            $cartCount = (int) \App\Models\Cart::where('client_id', $client->id)->sum('quantity');
            $customerInfo = "Name: {$client->name}, Phone: {$client->phone}, Total Completed Orders: {$totalOrders}, Current Items in Cart: {$cartCount}";

            $cartPreview = \App\Models\Cart::where('client_id', $client->id)
                ->with(['product'])
                ->limit(3)
                ->get()
                ->map(fn($c) => $c->product?->name)
                ->filter()
                ->join(', ');

            if ($cartPreview) {
                $customerInfo .= "\n- Products currently in customer's cart: {$cartPreview}";
            }
        } elseif ($conversation->customer_name) {
            $customerInfo = "Name: {$conversation->customer_name}, Phone: {$conversation->customer_phone}";
        }

        // 1b. Official store contact (Business Profile)
        $store = StoreSetup::first();
        $contactLines = array_filter([
            $store?->phone ? "Hotline / Call / WhatsApp: {$store->phone} (WhatsApp link: https://wa.me/" . preg_replace('/\D+/', '', $store->phone) . ")" : null,
            $store?->email ? "Email: {$store->email}" : null,
            $store?->street_address ? "Showroom / Office address: {$store->street_address}" : null,
            $store?->facebook ? "Facebook: {$store->facebook}" : null,
            $store?->instagram ? "Instagram: {$store->instagram}" : null,
        ]);
        $storeContact = $contactLines ? "- " . implode("\n- ", $contactLines) : '- Contact us via our official hotline or message us on Facebook.';

        // 2. Structured System Prompt
        $systemPrompt = "You are 'Mohima Beauty AI', the expert Korean beauty & luxury skincare advisor of Mohima (authentic imported K-Beauty & skincare in Bangladesh).

## CORE OPERATIONAL RULES (Must follow in order)
1. LANGUAGE & TONE:
   - Mirror customer's language style (Bangla, Banglish, or English).
   - Be polite, warm, helpful, and trustworthy. Use gentle beauty emojis (✨, 🌸, 🧴, 💧, 🌿, 🛒).

2. PRODUCT INTENT = MUST SHOW PRODUCTS:
   - Whenever customer mentions any product, skin concern (acne, dark spots, hyperpigmentation, dry skin, oily skin, damaged barrier, wrinkles), or asks for recommendations:
   - MANDATORY: Call `search_products` immediately to fetch 1 to 4 matching product cards.
   - NEVER ask empty clarifying questions before showing products. Show products FIRST, then provide guidance.
   - For budget requests ('kom damer moddhe'), call `search_products` with sort_by='price_low_to_high'.
   - For best/popular requests ('sera ponno', 'best seller'), call `search_products` with sort_by='popular'.

3. LIVE STOCK & AVAILABILITY:
   - When customer asks if a product is in stock or available ('stock ache ki?', 'available ache?'):
   - Call `check_product_stock` or `search_products` first.
   - NEVER guess or state stock status from memory. Answer strictly based on tool data.

4. DELIVERY TIME VS SHIPPING CHARGES:
   - When customer asks about DELIVERY TIME / DURATION ('koto dine pabo?', 'kobe pabo?', 'delivery time koto?'):
     * Call `get_delivery_charge` with inquiry_type='delivery_time'.
     * Answer ONLY the delivery timeline (Inside Dhaka: 1-2 business days, Outside Dhaka: 2-4 business days).
     * DO NOT mention delivery charge numbers unless customer asked about cost!
   - When customer asks about DELIVERY CHARGES / FEES ('delivery charge koto?', 'charge koto?'):
     * Call `get_delivery_charge` with inquiry_type='delivery_charge'.

5. ADD TO CART & BUYING:
   - When customer says 'aita cart e add koro', 'nibo', 'kinbo', 'order', or clicks a cart chip:
   - MANDATORY: Call `add_to_cart` immediately with product_name and/or product_id.

6. OFFERS & DISCOUNTS:
   - When customer asks about offers, discounts, coupons, or promos ('kono offer ache?', 'discount pabo?'):
   - Call `get_active_offers`. NEVER invent fake coupons.

7. IMAGE PRODUCT RECOGNITION & PURCHASE:
   - When customer sends a product image:
     * Read the exact brand, product name, and volume/size from the image and call `search_products` with limit=1 or 2.
   - When customer sends an image WITH buying intent ('nite chai', 'nibo', 'kinbo', 'order', 'want to buy', 'need'):
     * Identify the EXACT 1 product shown in the image.
     * Highlight 2 to 3 key benefits from its description and specifications.
     * State the price in BDT (৳) and live in-stock status warmly.
     * Urge the customer directly to add to cart or confirm the order.

8. SUGGESTION CHIPS (MANDATORY FORMAT):
   - End every reply with 2 to 4 interactive chips on a new line in this exact format:
     [SUGGESTIONS: অপশন ১ | অপশন ২ | অপশন ৩]

9. PRODUCT COMPARISON:
   - When customer asks to compare products, asks for differences between products, or asks 'konta bhalo/konta nibo':
   - Call `compare_products` immediately to fetch side-by-side specs, active ingredients, and live prices.

10. 100% AUTHENTICITY & PARCEL CHECK GUARANTEE:
   - All products at Mohima are 100% genuine imported from South Korea / official brands.
   - Customers enjoy full Cash on Delivery with the freedom to inspect/check the parcel before paying.

11. OFFICIAL STORE CONTACT:
{$storeContact}

12. TEXT FORMATTING & CLEAN PRESENTATION:
   - Always format text cleanly using standard Markdown.
   - For bullet lists, use standard bullets (`-` or `•`) or clean numbered lists (`1.`, `2.`).
   - NEVER use deeply nested asterisks (e.g. avoid raw `* মূল্য`, `* স্টক` list repetition).
   - Since product cards with image, price, discount, and stock are automatically displayed beneath the message, DO NOT repeat bulky lists of prices and stock numbers in the text. Instead, warmly introduce the recommendations and explain why each product suits the customer's skin.
   - Use bold (`**পণ্য/মূল বিষয়**`) for emphasis.

13. OMNICHANNEL (FACEBOOK & WHATSAPP) DIRECT ORDERING:
   - When a customer wants to place an order directly on Facebook Messenger or WhatsApp ('অর্ডার করতে চাই', 'কিনবো', 'নিবো', 'order'):
     * If the customer has not yet provided delivery details, warmly ask for:
       'অর্ডারটি নিশ্চিত করতে অনুগ্রহ করে আপনার:
       ১. পুরো নাম
       ২. ১১ ডিজিটের মোবাইল নাম্বার
       ৩. সম্পূর্ণ ডেলিভারি ঠিকানা (জেলা, থানা, বাসা নং/রোড) লিখে পাঠান।'
     * Inform them that a verification code will be sent to their mobile for confirmation.

## Customer Context:
{$customerInfo}";

        if (!empty($conversation->conversation_summary)) {
            $systemPrompt .= "\n\n## Earlier Conversation Context & Memory (Rolling Summary):\n{$conversation->conversation_summary}";
        }

        // 2b. Zero-Turn Tool Pre-Execution (Fast-Path for deterministic intents)
        $preExecutedData = null;
        $payloadData = [];

        if ($intent === 'delivery_time' || $intent === 'delivery_charge') {
            $preExecutedData = DeliveryChargeTool::execute(['inquiry_type' => $intent]);
        } elseif ($intent === 'offer') {
            $preExecutedData = ActiveOffersTool::execute([]);
            if (!empty($preExecutedData['products'])) {
                $payloadData['products'] = $preExecutedData['products'];
            }
        } elseif ($intent === 'support_faq') {
            $preExecutedData = SupportFaqTool::execute(['query' => $latestCustomerMsg?->message]);
        }

        if ($preExecutedData !== null) {
            $systemPrompt .= "\n\n## Verified Store Data (Use this directly to answer customer warmly, do NOT call any tools):\n" . json_encode($preExecutedData, JSON_UNESCAPED_UNICODE);
        }

        // 3. Build Messages with Context-Sensitive Dynamic Few-Shots
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Only inject product few-shot if customer is inquiring about products
        if (in_array($intent, ['product_search', 'image_search', 'image_purchase', 'image_benefit', 'product_benefit', 'general'], true)) {
            $messages[] = ['role' => 'user', 'content' => 'acne prone skin er jonno valo serum lagbe'];
            $messages[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'few_shot_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'search_products',
                            'arguments' => json_encode(['query' => 'acne serum']),
                        ],
                    ],
                ],
            ];
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => 'few_shot_1',
                'name' => 'search_products',
                'content' => json_encode([
                    'products' => [
                        [
                            'id' => 12,
                            'name' => 'COSRX Niacinamide 15% Serum 20ml',
                            'price' => 1250,
                            'stock' => 30,
                            'in_stock' => true,
                        ],
                    ],
                ]),
            ];
            $messages[] = [
                'role' => 'assistant',
                'content' => "ব্রণপ্রবণ ত্বকের জন্য আমাদের সবচেয়ে বিশ্বস্ত ও কার্যকর সিরামটি নিচে দেওয়া হলো! এটি অতিরিক্ত তেল নিয়ন্ত্রণ করে ও ব্রণের দাগ হালকা করতে চমৎকার কাজ করে ✨\n\n[SUGGESTIONS: 🛒 কার্টে যোগ করুন | 🧴 কিভাবে ব্যবহার করবেন? | 🚚 ডেলিভারি চার্জ কত?]",
            ];
        }

        // 4. Fetch past messages for history (active sliding window of 12 messages)
        $windowSize = 12;
        $pastMessages = $conversation->messages()
            ->orderBy('id', 'desc')
            ->limit($windowSize)
            ->get()
            ->reverse();

        $previousAiMsg = $pastMessages->where('sender_type', ChatSenderTypeEnum::AI)
            ->filter(fn($m) => !empty($m->payload['products']))
            ->last();

        foreach ($pastMessages as $msg) {
            $role = match ($msg->sender_type) {
                ChatSenderTypeEnum::CUSTOMER => 'user',
                ChatSenderTypeEnum::AI => 'assistant',
                ChatSenderTypeEnum::ADMIN => 'assistant',
            };

            $content = $msg->message ?: '';

            if ($role === 'assistant' && !empty($msg->payload['products'])) {
                $productCards = [];
                foreach ($msg->payload['products'] as $prod) {
                    $pid = $prod['id'] ?? '';
                    $pname = $prod['name'] ?? '';
                    $pprice = $prod['price'] ?? '';
                    $pstock = $prod['stock'] ?? 0;
                    $productCards[] = "- Product ID {$pid}: {$pname} (Price: ৳{$pprice}, Stock: {$pstock})";
                }
                if (!empty($productCards)) {
                    $content .= "\n\n[Displayed Product Cards to Customer:\n" . implode("\n", $productCards) . "]";
                }
            }

            if ($role === 'user' && $msg->id === $latestCustomerMsg?->id && $previousAiMsg && !empty($previousAiMsg->payload['products'])) {
                $lastProds = $previousAiMsg->payload['products'];
                if (preg_match('/কার্টে যোগ|add to cart|🛒|nibo|kinbo|order/iu', $content)) {
                    $prodList = implode('; ', array_map(fn($p) => "Product ID {$p['id']}: {$p['name']}", $lastProds));
                    $content .= "\n\n[System Context: Customer action relates to the previously displayed product(s): {$prodList}. Call add_to_cart for this product!]";
                } elseif (preg_match('/কম দাম|budget|kom dam/iu', $content)) {
                    $content .= "\n\n[System Context: Customer requested budget-friendly/lower price alternatives. Call search_products with sort_by='price_low_to_high'.]";
                }
            }

            if ($msg->file_url && $msg->file_type === 'image' && $msg->id !== $latestCustomerMsg?->id) {
                $messageContent = trim(($content ?: '') . "\n[Customer sent a product image earlier]");
            } elseif ($msg->file_url && $msg->file_type === 'image') {
                $textPart = $content ?: 'Customer sent this product image to check availability and details.';
                $messageContent = [
                    [
                        'type' => 'text',
                        'text' => $textPart,
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $msg->file_url,
                        ],
                    ],
                ];
            } else {
                if ($msg->file_url) {
                    $content = trim($content . "\n" . "[Attached {$msg->file_type} file: {$msg->file_name}]");
                }
                $messageContent = $content;
            }

            $messages[] = [
                'role' => $role,
                'content' => $messageContent,
            ];
        }

        // 5. Dynamic Tool Pruning & Routing
        if ($preExecutedData !== null) {
            // Data is already pre-injected into prompt; no tools needed
            $tools = [];
            $toolChoice = 'none';
        } else {
            // Only load the minimal required tools based on intent
            $tools = match ($intent) {
                'stock_check' => [ProductStockCheckTool::getDefinition(), ProductSearchTool::getDefinition()],
                'compare_products' => [CompareProductsTool::getDefinition()],
                'order_track' => [OrderTrackingTool::getDefinition()],
                'add_to_cart' => [AddToCartTool::getDefinition(), ProductSearchTool::getDefinition()],
                'reorder' => [CustomerHistoryTool::getDefinition(), AddToCartTool::getDefinition()],
                'image_search', 'image_purchase', 'image_benefit', 'product_benefit', 'product_search' => [ProductSearchTool::getDefinition(), ProductDetailTool::getDefinition()],
                default => [ProductSearchTool::getDefinition(), ProductDetailTool::getDefinition(), AddToCartTool::getDefinition(), OrderTrackingTool::getDefinition()],
            };

            $toolChoice = match ($intent) {
                'add_to_cart' => ['type' => 'function', 'function' => ['name' => 'add_to_cart']],
                'order_track' => ['type' => 'function', 'function' => ['name' => 'track_order']],
                'stock_check' => ['type' => 'function', 'function' => ['name' => 'check_product_stock']],
                'image_search', 'image_purchase', 'image_benefit' => ['type' => 'function', 'function' => ['name' => 'search_products']],
                'compare_products' => ['type' => 'function', 'function' => ['name' => 'compare_products']],
                'reorder' => ['type' => 'function', 'function' => ['name' => 'get_customer_history']],
                'product_search' => 'required',
                default => 'auto',
            };
        }

        // 7. First LLM Call
        $response = $this->openRouter->chatCompletion($messages, $tools, null, $toolChoice);

        if (!$response || (isset($response['error']) && !isset($response['choices']))) {
            $errorMessage = 'দুঃখিত, আমাদের এআই অ্যাসিস্ট্যান্ট এই মুহূর্তে সামান্য ব্যস্ত রয়েছে। অনুগ্রহ করে কিছুক্ষণ পর আবার চেষ্টা করুন অথবা আমাদের ইনবক্সে মেসেজ রাখুন 🙏';
            $aiMessage = $conversation->messages()->create([
                'sender_type' => ChatSenderTypeEnum::AI,
                'message' => $errorMessage,
            ]);
            try {
                broadcast(new ChatMessageSent($aiMessage));
            } catch (\Throwable $e) {
                Log::warning("Failed to broadcast AI error message: " . $e->getMessage());
            }
            return $aiMessage;
        }

        $choice = $response['choices'][0] ?? null;
        if (!$choice) {
            return null;
        }

        $responseMessage = $choice['message'] ?? [];
        $toolCalls = $responseMessage['tool_calls'] ?? [];
        $payloadData = [];

        // 8. Multi-turn Tool Calling Loop (Max 2 turns)
        $maxToolTurns = 2;
        $currentTurn = 0;
        $allToolCalls = [];

        $messages = array_map(function ($m) {
            if (is_array($m['content'] ?? null)) {
                $text = collect($m['content'])->where('type', 'text')->pluck('text')->implode(' ');
                $m['content'] = trim($text . "\n[Product image already analysed — use the search results]");
            }
            return $m;
        }, $messages);

        while (!empty($toolCalls) && $currentTurn < $maxToolTurns) {
            $currentTurn++;
            $messages[] = $responseMessage;

            foreach ($toolCalls as $toolCall) {
                $allToolCalls[] = $toolCall;
                $funcName = $toolCall['function']['name'] ?? '';
                $rawArgs = $toolCall['function']['arguments'] ?? '{}';
                $args = json_decode($rawArgs, true) ?: [];
                $toolCallId = $toolCall['id'] ?? uniqid('call_');

                $toolResult = [];
                if ($funcName === 'search_products') {
                    if (in_array($intent, ['image_search', 'image_purchase', 'image_benefit', 'product_benefit'])) {
                        $args['limit'] = min((int) ($args['limit'] ?? 1), ($intent === 'product_benefit' ? 1 : 2));
                    }
                    $toolResult = ProductSearchTool::execute($args);
                    if (!empty($toolResult['products'])) {
                        $payloadData['products'] = $toolResult['products'];
                    }
                } elseif ($funcName === 'check_product_stock') {
                    $toolResult = ProductStockCheckTool::execute($args);
                } elseif ($funcName === 'get_delivery_charge') {
                    if (empty($args['inquiry_type'])) {
                        if ($intent === 'delivery_time') {
                            $args['inquiry_type'] = 'delivery_time';
                        } elseif ($intent === 'delivery_charge') {
                            $args['inquiry_type'] = 'delivery_charge';
                        }
                    }
                    $toolResult = DeliveryChargeTool::execute($args);
                } elseif ($funcName === 'get_product_details') {
                    $toolResult = ProductDetailTool::execute($args);
                    if (!empty($toolResult['id'])) {
                        $payloadData['product_details'] = [
                            'id' => $toolResult['id'],
                            'name' => $toolResult['name'],
                            'bangla_name' => $toolResult['bangla_name'] ?? '',
                            'slug' => $toolResult['slug'] ?? '',
                            'slug_url' => $toolResult['slug_url'] ?? '',
                            'image' => $toolResult['image'] ?? null,
                            'brand' => $toolResult['brand'] ?? null,
                            'category' => $toolResult['category'] ?? null,
                            'total_stock' => $toolResult['total_stock'] ?? 0,
                            'variants' => array_slice(
                                is_array($toolResult['variants'] ?? null)
                                    ? $toolResult['variants']
                                    : (is_object($toolResult['variants'] ?? null) && method_exists($toolResult['variants'], 'toArray') ? $toolResult['variants']->toArray() : []),
                                0,
                                3
                            ),
                        ];
                    }
                } elseif ($funcName === 'track_order') {
                    $toolResult = OrderTrackingTool::execute($args, $conversation->client_id);
                    if (!empty($toolResult['order'])) {
                        $payloadData['order'] = $toolResult['order'];
                    } elseif (!empty($toolResult['orders'])) {
                        $payloadData['orders'] = $this->normalizeArrayLike($toolResult['orders']);
                    }
                } elseif ($funcName === 'add_to_cart') {
                    $toolResult = AddToCartTool::execute($args, $conversation->client_id, $conversation);
                    if (!empty($toolResult['success'])) {
                        $payloadData['cart_action'] = 'added';
                        $payloadData['cart_item'] = $toolResult;
                    }
                } elseif ($funcName === 'get_customer_history') {
                    $toolResult = CustomerHistoryTool::execute($args, $conversation->client_id);
                    if (!empty($toolResult['is_authenticated'])) {
                        $payloadData['customer_history'] = $toolResult;
                    }
                } elseif ($funcName === 'get_active_offers') {
                    $toolResult = ActiveOffersTool::execute($args);
                    $payloadData['offers_checked'] = true;
                    if (!empty($toolResult['products'])) {
                        $payloadData['products'] = $this->normalizeArrayLike($toolResult['products']);
                    }
                } elseif ($funcName === 'compare_products') {
                    $toolResult = CompareProductsTool::execute($args);
                    if (!empty($toolResult['products'])) {
                        $payloadData['products'] = array_slice($this->normalizeArrayLike($toolResult['products']), 0, 3);
                    }
                } elseif ($funcName === 'update_customer_profile') {
                    $toolResult = UpdateCustomerProfileTool::execute($args, $conversation->client_id);
                    if (!empty($toolResult['success'])) {
                        $payloadData['profile_updated'] = true;
                        $payloadData['customer_profile'] = $toolResult['profile'] ?? null;
                    }
                } elseif ($funcName === 'get_support_faq') {
                    $toolResult = SupportFaqTool::execute($args);
                    if (!empty($toolResult['faqs'])) {
                        $payloadData['faqs'] = array_slice($this->normalizeArrayLike($toolResult['faqs']), 0, 5);
                    }
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'name' => $funcName,
                    'content' => json_encode($toolResult),
                ];
            }

            $needsSecondTool = in_array($intent, ['product_benefit', 'image_benefit', 'greeting', 'general'], true);
            $availableToolsNext = ($currentTurn < $maxToolTurns && $needsSecondTool) ? $tools : [];
            $nextResponse = $this->openRouter->chatCompletion($messages, $availableToolsNext, null, 'auto');

            $nextChoice = $nextResponse['choices'][0] ?? null;
            $responseMessage = $nextChoice['message'] ?? [];
            $toolCalls = $responseMessage['tool_calls'] ?? [];

            if (empty($toolCalls)) {
                if (isset($responseMessage['content']) && trim($responseMessage['content']) !== '') {
                    $finalContent = $responseMessage['content'];
                }
                break;
            }
        }

        if (empty($allToolCalls)) {
            $finalContent = $responseMessage['content'] ?? 'আসসালামু আলাইকুম! Mohima-তে আপনাকে স্বাগতম। আপনার স্কিনের যত্নে কিভাবে সাহায্য করতে পারি? ✨';
        } elseif (empty($finalContent)) {
            $finalContent = $responseMessage['content'] ?? "আপনার অনুরোধকৃত পণ্যের বিস্তারিত তথ্য নিচে দেওয়া হলো ✨\n\n[SUGGESTIONS: 🛒 কার্টে যোগ করুন | 🚚 ডেলিভারি চার্জ কত? | 🌸 সেরা স্কিনকেয়ার দেখুন]";
        }

        $toolCalls = $allToolCalls;

        if (!empty($payloadData['product_details']['id']) && !empty($payloadData['products'])) {
            $targetId = $payloadData['product_details']['id'];
            $matchingProds = array_values(array_filter($this->normalizeArrayLike($payloadData['products']), fn($p) => ($p['id'] ?? null) == $targetId));
            if (!empty($matchingProds)) {
                $payloadData['products'] = $matchingProds;
            } else {
                $payloadData['products'] = array_slice($this->normalizeArrayLike($payloadData['products']), 0, 1);
            }
        }

        $payloadData = $this->compactPayloadForBroadcast($payloadData);

        $finalContent = preg_replace('/\[Displayed Product Cards to Customer:[^\]]*\]/s', '', $finalContent);
        $finalContent = trim($finalContent);

        // 9. Extract Interactive Suggestion Chips
        $suggestedReplies = [];
        if (preg_match('/\[SUGGESTIONS:\s*(.*?)\]/is', $finalContent, $matches)) {
            $rawSuggestions = $matches[1] ?? '';
            $chips = array_filter(array_map('trim', explode('|', $rawSuggestions)));
            if (!empty($chips)) {
                $suggestedReplies = array_values(array_slice($chips, 0, 4));
            }
            $finalContent = trim(preg_replace('/\[SUGGESTIONS:\s*(.*?)\]/is', '', $finalContent));
        }

        if (!empty($suggestedReplies)) {
            $payloadData['suggested_replies'] = $suggestedReplies;
        }

        // 10. Save AI Response
        $aiMessage = $conversation->messages()->create([
            'sender_type' => ChatSenderTypeEnum::AI,
            'message' => $finalContent,
            'payload' => !empty($payloadData) ? $payloadData : null,
            'tool_calls' => !empty($toolCalls) ? $toolCalls : null,
            'is_read' => false,
        ]);

        $conversation->update(['last_message_at' => now()]);

        // 11. Broadcast via Laravel Reverb
        try {
            $broadcastPayload = $this->compactPayloadForBroadcast($aiMessage->payload ?? []);
            $aiMessageForBroadcast = clone $aiMessage;
            $aiMessageForBroadcast->payload = !empty($broadcastPayload) ? $broadcastPayload : null;
            broadcast(new ChatMessageSent($aiMessageForBroadcast));
        } catch (\Throwable $e) {
            Log::warning("Failed to broadcast ChatMessageSent event: " . $e->getMessage());
            try {
                $fallbackMessage = clone $aiMessage;
                $fallbackMessage->payload = null;
                broadcast(new ChatMessageSent($fallbackMessage));
            } catch (\Throwable $fallbackErr) {
                Log::error("Failed fallback broadcast: " . $fallbackErr->getMessage());
            }
        }

        // 12. Trigger background rolling summary job if unsummarized messages have accumulated
        if ($conversation->shouldUpdateSummary($windowSize, 5)) {
            GenerateConversationSummaryJob::dispatch($conversation, $windowSize);
        }

        return $aiMessage;
    }
}
