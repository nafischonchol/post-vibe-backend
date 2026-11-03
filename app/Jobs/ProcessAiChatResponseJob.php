<?php

namespace App\Jobs;

use App\Enums\ChatAgentModeEnum;
use App\Models\AiChatSetting;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\AiChat\AiChatOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAiChatResponseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 85; // stays below the queue's 90s retry_after

    public function __construct(
        public ChatConversation $conversation,
        public ChatMessage $incomingMessage
    ) {
        $this->onQueue('chat');
    }

    /**
     * Execute the job.
     */
    public function handle(AiChatOrchestrator $orchestrator): void
    {
        // Reload conversation state to ensure human takeover hasn't occurred in the meantime
        $this->conversation->refresh();

        if ($this->conversation->agent_mode !== ChatAgentModeEnum::AI) {
            Log::info("Chat conversation {$this->conversation->id} switched to human mode. Skipping AI job.");
            return;
        }

        $setting = AiChatSetting::getActiveSetting();
        if (!$setting->is_ai_enabled) {
            Log::info("AI Chat is globally disabled. Skipping AI job.");
            return;
        }

        try {
            $orchestrator->generateAiResponse($this->conversation);
        } catch (\Throwable $e) {
            Log::error("Failed to generate AI response in ProcessAiChatResponseJob: " . $e->getMessage(), [
                'conversation_id' => $this->conversation->id,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * All attempts failed (error or timeout): never leave the customer without an answer.
     */
    public function failed(?\Throwable $e): void
    {
        try {
            $hasImage = $this->incomingMessage->file_url && $this->incomingMessage->file_type === 'image';
            $text = $hasImage
                ? "দুঃখিত, ছবিটি এই মুহূর্তে পড়তে পারিনি 🙏 পণ্যের নাম বা ব্র্যান্ড লিখে পাঠান — সাথে সাথে খুঁজে দিচ্ছি। আমাদের সাপোর্ট টিমও ছবিটি দেখে উত্তর দেবে।\n\n[SUGGESTIONS: ✨ জনপ্রিয় স্কিনকেয়ার | 👨‍💼 সাপোর্টের সাথে কথা বলুন]"
                : "দুঃখিত, উত্তর দিতে একটু দেরি হচ্ছে 🙏 আবার লিখে পাঠান অথবা আমাদের সাপোর্ট টিম শীঘ্রই উত্তর দেবে।";

            $suggested = [];
            if (preg_match('/\[SUGGESTIONS:\s*(.*?)\]/s', $text, $m)) {
                $suggested = array_values(array_filter(array_map('trim', explode('|', $m[1]))));
                $text = trim(preg_replace('/\[SUGGESTIONS:\s*(.*?)\]/s', '', $text));
            }

            $message = $this->conversation->messages()->create([
                'sender_type' => \App\Enums\ChatSenderTypeEnum::AI,
                'message' => $text,
                'payload' => $suggested ? ['suggested_replies' => $suggested] : null,
                'is_read' => false,
            ]);
            broadcast(new \App\Events\ChatMessageSent($message));
        } catch (\Throwable $inner) {
            Log::error('AI chat fallback message failed: ' . $inner->getMessage());
        }
    }
}
