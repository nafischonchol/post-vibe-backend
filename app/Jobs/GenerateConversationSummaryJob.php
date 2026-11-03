<?php

namespace App\Jobs;

use App\Models\ChatConversation;
use App\Services\AiChat\ConversationSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateConversationSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    public function __construct(
        public ChatConversation $conversation,
        public int $windowSize = 12
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ConversationSummaryService $summaryService): void
    {
        try {
            $this->conversation->refresh();
            $summaryService->generateOrUpdateSummary($this->conversation, $this->windowSize);
        } catch (\Throwable $e) {
            Log::error("GenerateConversationSummaryJob failed for conversation {$this->conversation->id}: " . $e->getMessage(), [
                'conversation_id' => $this->conversation->id,
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
