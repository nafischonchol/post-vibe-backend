<?php

namespace App\Services\AiChat;

use App\Enums\ChatSenderTypeEnum;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\Log;

class ConversationSummaryService
{
    public function __construct(
        protected OpenRouterService $openRouter
    ) {}

    /**
     * Generate or incrementally update the rolling conversation summary for older messages.
     *
     * @param ChatConversation $conversation
     * @param int $windowSize Number of latest messages kept in the active chat prompt
     * @return string|null The updated summary text
     */
    public function generateOrUpdateSummary(ChatConversation $conversation, int $windowSize = 12): ?string
    {
        $cutoffMessage = $conversation->messages()
            ->orderBy('id', 'desc')
            ->skip($windowSize)
            ->first();

        if (!$cutoffMessage) {
            return $conversation->conversation_summary;
        }

        // Fetch messages that need to be summarized (older than window, newer than last summary)
        $query = $conversation->messages()
            ->where('id', '<=', $cutoffMessage->id)
            ->orderBy('id', 'asc');

        if ($conversation->last_summarized_message_id) {
            $query->where('id', '>', $conversation->last_summarized_message_id);
        }

        $messagesToSummarize = $query->get();

        if ($messagesToSummarize->isEmpty()) {
            return $conversation->conversation_summary;
        }

        // Format message transcript
        $transcriptLines = [];
        foreach ($messagesToSummarize as $msg) {
            $speaker = match ($msg->sender_type) {
                ChatSenderTypeEnum::CUSTOMER => 'Customer',
                ChatSenderTypeEnum::AI => 'AI Sales Assistant',
                ChatSenderTypeEnum::ADMIN => 'Human Admin Agent',
            };

            $text = trim($msg->message ?: '');
            if ($msg->file_type) {
                $text .= " [Attached {$msg->file_type}: {$msg->file_name}]";
            }

            if (!empty($msg->payload['products'])) {
                $productNames = array_column($msg->payload['products'], 'name');
                if (!empty($productNames)) {
                    $text .= " [Displayed Products: " . implode(', ', array_slice($productNames, 0, 3)) . "]";
                }
            }

            if (!empty($msg->payload['cart_action'])) {
                $text .= " [Action: Product added to cart]";
            }

            $transcriptLines[] = "{$speaker}: {$text}";
        }

        $transcript = implode("\n", $transcriptLines);

        $systemPrompt = "You are a conversation summarization engine for 'Mohima' (premier Korean beauty, skincare, and authentic cosmetics retailer).
Your task is to maintain a concise, compact, structured memory summary of the customer's conversation so that older context is never lost.

Keep the summary short (under 150 words) in clean bullet points covering:
• Customer profile / Skin concerns (e.g. acne, dry skin, hyperpigmentation, barrier repair, sensitivity)
• Products discussed, recommended, accepted, or rejected
• Customer budget, brand preferences, or delivery location (inside/outside Dhaka)
• Specific orders / invoices / tracking references mentioned
• Any pending customer questions or unresolved issues

DO NOT include conversational filler. Output ONLY the structured bulleted summary.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        $previousSummary = trim($conversation->conversation_summary ?: '');
        if ($previousSummary !== '') {
            $userContent = "Existing Conversation Summary so far:\n{$previousSummary}\n\nNew Earlier Messages to incorporate into this summary:\n{$transcript}\n\nProduce the updated, unified concise bulleted summary:";
        } else {
            $userContent = "Conversation Messages to summarize:\n{$transcript}\n\nProduce the concise bulleted summary:";
        }

        $messages[] = ['role' => 'user', 'content' => $userContent];

        try {
            $response = $this->openRouter->chatCompletion($messages, []);
            $newSummary = trim($response['choices'][0]['message']['content'] ?? '');

            if ($newSummary !== '') {
                $conversation->update([
                    'conversation_summary' => $newSummary,
                    'last_summarized_message_id' => $cutoffMessage->id,
                    'summary_updated_at' => now(),
                ]);

                Log::info("Generated rolling conversation summary for ChatConversation ID: {$conversation->id}", [
                    'last_summarized_message_id' => $cutoffMessage->id,
                    'summary_length' => strlen($newSummary),
                ]);

                return $newSummary;
            }
        } catch (\Throwable $e) {
            Log::error("Failed to generate conversation summary for conversation {$conversation->id}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        }

        return $conversation->conversation_summary;
    }
}
