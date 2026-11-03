<?php

namespace App\Services\AiChat\Omnichannel;

use App\Actions\Order\CreateOrderAction;
use App\Enums\ChatChannelEnum;
use App\Enums\ChatSenderTypeEnum;
use App\Helpers\PhoneNumberHelper;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\ClientOtp;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationalOrderService
{
    public function __construct(
        protected ChatIdentityService $identityService,
        protected SmsService $smsService
    ) {}

    /**
     * Check if an incoming message should be handled by the Conversational Order state machine.
     * Returns a ChatMessage reply if handled, or null to continue normal AI processing.
     */
    public function handleIncomingOrderStep(ChatConversation $conversation, ChatMessage $incomingMessage): ?ChatMessage
    {
        $text = trim($incomingMessage->message ?? '');

        // 1. If currently waiting for OTP verification
        if ($conversation->conversation_state === 'awaiting_otp') {
            return $this->handleOtpSubmission($conversation, $text);
        }

        // 2. Check if the message contains order details (Name, Phone, Address)
        $extractedInfo = $this->extractOrderDetails($text);
        if ($extractedInfo && !empty($extractedInfo['phone'])) {
            return $this->initiateOrderVerification($conversation, $extractedInfo);
        }

        return null;
    }

    /**
     * Extract name, phone, and address from free text message using heuristics & regex.
     */
    public function extractOrderDetails(string $text): ?array
    {
        // Must contain a valid BD mobile number
        if (!preg_match('/(?:\+?88)?(01[3-9]\d{8})\b/', $text, $matches)) {
            return null;
        }

        $phone = PhoneNumberHelper::toLocalBd($matches[1]);
        if (!$phone) {
            return null;
        }

        // Check for indicators of an order/address submission
        $hasOrderIntent = preg_match('/(নাম|ঠিকানা|এড্রেস|address|name|order|অর্ডার|ডেলিভারি|ধানমন্ডি|মিরপুর|উত্তরা|ঢাকা|রোড|বাড়ি|বাসা|thana|district)/iu', $text);
        if (!$hasOrderIntent && strlen(trim($text)) > 25) {
            // If just a raw text with phone and some address content
            $hasOrderIntent = true;
        }

        if (!$hasOrderIntent) {
            return null;
        }

        $name = null;
        if (preg_match('/(?:নাম|name)[:\s\-]+([^\n,]+)/iu', $text, $nameMatches)) {
            $name = trim($nameMatches[1]);
        }

        $address = null;
        if (preg_match('/(?:ঠিকানা|address|এড্রেস)[:\s\-]+([^\n]+)/iu', $text, $addrMatches)) {
            $address = trim($addrMatches[1]);
        } else {
            // Fallback: use text excluding the phone number
            $address = trim(preg_replace('/(?:\+?88)?01[3-9]\d{8}\b/', '', $text));
        }

        return [
            'phone' => $phone,
            'name' => $name,
            'address' => $address,
            'raw_text' => $text,
        ];
    }

    /**
     * Initiate phone verification by generating OTP and sending SMS.
     */
    public function initiateOrderVerification(ChatConversation $conversation, array $details): ChatMessage
    {
        $phone = $details['phone'];
        $name = $details['name'] ?: ($conversation->customer_name ?: 'সম্মানিত গ্রাহক');

        // Generate OTP
        $otp = ClientOtp::generateOtp($phone, 'order');
        $otpCode = $otp->otp_code;

        // Send SMS
        $smsText = "আপনার মোহিমা (Mohima) অর্ডারটি নিশ্চিত করতে ভেরিফিকেশন কোড: {$otpCode}। কারো সাথে শেয়ার করবেন না।";
        $this->smsService->send($phone, $smsText);

        // Update conversation state
        $conversation->update([
            'customer_name' => $name,
            'customer_phone' => $phone,
            'conversation_state' => 'awaiting_otp',
            'pending_order_data' => $details,
        ]);

        $maskedPhone = substr($phone, 0, 3) . '•••••' . substr($phone, -3);
        $replyText = "ধন্যবাদ {$name}! আপনার অর্ডার ও ডেলিভারি নিশ্চিত করতে আপনার {$maskedPhone} নাম্বারে ৪-৬ ডিজিটের একটি ভেরিফিকেশন কোড পাঠানো হয়েছে।\n\nঅনুগ্রহ করে কোডটি এখানে লিখে পাঠান।";

        return $conversation->messages()->create([
            'sender_type' => ChatSenderTypeEnum::AI,
            'message' => $replyText,
            'payload' => [
                'type' => 'awaiting_otp',
                'phone' => $maskedPhone,
            ],
            'is_read' => false,
        ]);
    }

    /**
     * Handle OTP verification submission from customer.
     */
    public function handleOtpSubmission(ChatConversation $conversation, string $text): ChatMessage
    {
        // Extract 4-6 digits
        if (!preg_match('/\b(\d{4,6})\b/', $text, $m)) {
            return $conversation->messages()->create([
                'sender_type' => ChatSenderTypeEnum::AI,
                'message' => "অনুগ্রহ করে আপনার ফোনে পাঠানো ভেরিফিকেশন কোডটি (৪-৬ ডিজিটের সংখ্যা) সঠিকভাবে লিখুন।",
                'is_read' => false,
            ]);
        }

        $otpInput = $m[1];
        $pendingData = $conversation->pending_order_data ?? [];
        $phone = $pendingData['phone'] ?? $conversation->customer_phone;

        if (!$phone) {
            $conversation->resetOrderFlow();
            return $conversation->messages()->create([
                'sender_type' => ChatSenderTypeEnum::AI,
                'message' => "দুঃখিত, কোনো সক্রিয় অর্ডার রিকোয়েস্ট পাওয়া যায়নি। অনুগ্রহ করে আপনার নাম, ফোন ও ঠিকানা আবার লিখুন।",
                'is_read' => false,
            ]);
        }

        $isValid = ClientOtp::verifyOtp($phone, $otpInput, 'order')
            || ClientOtp::verifyOtp($phone, $otpInput, 'auth');

        if (!$isValid) {
            return $conversation->messages()->create([
                'sender_type' => ChatSenderTypeEnum::AI,
                'message' => "❌ ভেরিফিকেশন কোডটি সঠিক নয় অথবা এর মেয়াদ শেষ হয়ে গেছে। অনুগ্রহ করে আবার কোডটি লিখুন অথবা নতুন করে অর্ডার করুন।",
                'is_read' => false,
            ]);
        }

        // OTP is verified! Resolve or create Client
        $client = Client::where('phone', $phone)->first();
        $customerName = $pendingData['name'] ?? $conversation->customer_name ?? 'Facebook Customer';
        $customerAddress = $pendingData['address'] ?? null;

        if (!$client) {
            $client = Client::create([
                'phone' => $phone,
                'name' => $customerName,
                'address' => $customerAddress,
                'phone_verified_at' => now(),
            ]);
        } else {
            if ($customerAddress && empty($client->address)) {
                $client->update(['address' => $customerAddress]);
            }
        }

        // Link identity permanently
        $this->identityService->linkClientToIdentity(
            $client,
            $conversation->channel,
            $conversation->external_user_id,
            ['name' => $customerName]
        );

        // Reset state
        $conversation->update([
            'client_id' => $client->id,
            'is_phone_verified' => true,
            'conversation_state' => 'idle',
            'pending_order_data' => null,
        ]);

        $successText = "✅ অভিনন্দন {$customerName}! আপনার মোবাইল নাম্বারটি সফলভাবে ভেরিফাই ও লিঙ্ক হয়েছে।\n\nআমাদের সাপোর্ট টিম আপনার দেওয়া ঠিকানায় অর্ডারের বিস্তারিত তথ্য কনফার্ম করতে দ্রুত যোগাযোগ করবে।\n\n[SUGGESTIONS: 📦 আমার অর্ডার স্ট্যাটাস | 🚚 ডেলিভারি চার্জ কত? | ✨ আরও প্রোডাক্ট দেখুন]";

        $suggested = ['📦 আমার অর্ডার স্ট্যাটাস', '🚚 ডেলিভারি চার্জ কত?', '✨ আরও প্রোডাক্ট দেখুন'];

        return $conversation->messages()->create([
            'sender_type' => ChatSenderTypeEnum::AI,
            'message' => $successText,
            'payload' => [
                'suggested_replies' => $suggested,
                'is_verified' => true,
            ],
            'is_read' => false,
        ]);
    }
}
