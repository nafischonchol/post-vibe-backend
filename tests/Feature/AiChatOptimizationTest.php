<?php

namespace Tests\Feature;

use App\Enums\ChatAgentModeEnum;
use App\Enums\ChatChannelEnum;
use App\Enums\ChatSenderTypeEnum;
use App\Jobs\ProcessAiChatResponseJob;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\StoreSetup;
use App\Services\AiChat\AiChatOrchestrator;
use App\Services\AiChat\Omnichannel\WhatsAppCloudApiService;
use App\Services\AiChat\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class AiChatOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_human_support_fast_path_switches_mode_and_replies_instantly(): void
    {
        Event::fake();

        $conversation = ChatConversation::create([
            'channel' => ChatChannelEnum::WEB,
            'agent_mode' => ChatAgentModeEnum::AI,
            'status' => 'open',
        ]);

        $incoming = $conversation->messages()->create([
            'sender_type' => ChatSenderTypeEnum::CUSTOMER,
            'message' => 'আমি আপনাদের কাস্টমার কেয়ারের মানুষের সাথে কথা বলতে চাই',
        ]);

        $mockOpenRouter = Mockery::mock(OpenRouterService::class);
        $mockOpenRouter->shouldNotReceive('chatCompletion');

        $orchestrator = new AiChatOrchestrator($mockOpenRouter);
        $reply = $orchestrator->generateAiResponse($conversation);

        $conversation->refresh();

        // Verified agent_mode switched to human
        $this->assertEquals(ChatAgentModeEnum::HUMAN, $conversation->agent_mode);
        $this->assertNotNull($reply);
        $this->assertEquals(ChatSenderTypeEnum::AI, $reply->sender_type);
        $this->assertStringContainsString('কাস্টমার সাপোর্ট টিমের সাথে কানেক্ট', $reply->message);
    }

    public function test_contact_fast_path_answers_instantly_with_zero_llm_calls(): void
    {
        Event::fake();

        StoreSetup::create([
            'store_name' => 'Mohima Luxury',
            'phone' => '01700112233',
            'email' => 'care@mohima.com',
            'street_address' => 'House 15, Road 7, Banani, Dhaka',
        ]);

        $conversation = ChatConversation::create([
            'channel' => ChatChannelEnum::WEB,
            'agent_mode' => ChatAgentModeEnum::AI,
            'status' => 'open',
        ]);

        $conversation->messages()->create([
            'sender_type' => ChatSenderTypeEnum::CUSTOMER,
            'message' => 'আপনাদের হেল্পলাইন ও অফিস ঠিকানা কী?',
        ]);

        $mockOpenRouter = Mockery::mock(OpenRouterService::class);
        $mockOpenRouter->shouldNotReceive('chatCompletion');

        $orchestrator = new AiChatOrchestrator($mockOpenRouter);
        $reply = $orchestrator->generateAiResponse($conversation);

        $this->assertNotNull($reply);
        $this->assertStringContainsString('01700112233', $reply->message);
        $this->assertStringContainsString('Banani, Dhaka', $reply->message);
    }

    public function test_zero_turn_pre_execution_for_delivery_queries(): void
    {
        Event::fake();

        $conversation = ChatConversation::create([
            'channel' => ChatChannelEnum::WEB,
            'agent_mode' => ChatAgentModeEnum::AI,
            'status' => 'open',
        ]);

        $conversation->messages()->create([
            'sender_type' => ChatSenderTypeEnum::CUSTOMER,
            'message' => 'ডেলিভারি চার্জ কত?',
        ]);

        $mockOpenRouter = Mockery::mock(OpenRouterService::class);
        // Expect chatCompletion to be called ONCE with tools = [] and toolChoice = 'none'
        $mockOpenRouter->shouldReceive('chatCompletion')
            ->once()
            ->withArgs(function ($messages, $tools, $model, $toolChoice) {
                // Must be pre-executed, meaning tools are empty and data injected in system message
                return empty($tools)
                    && $toolChoice === 'none'
                    && str_contains($messages[0]['content'], 'Verified Store Data');
            })
            ->andReturn([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'ঢাকা সিটির ভেতরে ডেলিভারি চার্জ ৭০ টাকা এবং ঢাকার বাইরে ১৩০ টাকা 🚚',
                        ],
                    ],
                ],
            ]);

        $orchestrator = new AiChatOrchestrator($mockOpenRouter);
        $reply = $orchestrator->generateAiResponse($conversation);

        $this->assertNotNull($reply);
        $this->assertStringContainsString('ঢাকা সিটির ভেতরে', $reply->message);
    }

    public function test_dynamic_tool_pruning_only_passes_relevant_tools_for_order_tracking(): void
    {
        Event::fake();

        $conversation = ChatConversation::create([
            'channel' => ChatChannelEnum::WEB,
            'agent_mode' => ChatAgentModeEnum::AI,
            'status' => 'open',
        ]);

        $conversation->messages()->create([
            'sender_type' => ChatSenderTypeEnum::CUSTOMER,
            'message' => 'আমার পার্সেল কোথায় ট্র্যাক করতে চাই',
        ]);

        $mockOpenRouter = Mockery::mock(OpenRouterService::class);
        $mockOpenRouter->shouldReceive('chatCompletion')
            ->once()
            ->withArgs(function ($messages, $tools, $model, $toolChoice) {
                // Should only pass track_order tool instead of 11 tools
                $toolNames = array_map(fn($t) => $t['function']['name'] ?? '', $tools);
                return count($tools) === 1 && in_array('track_order', $toolNames, true);
            })
            ->andReturn([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'আপনার অর্ডার ট্র্যাক করতে ফোন নাম্বার বা ইনভয়েস নম্বর দিন।',
                        ],
                    ],
                ],
            ]);

        $orchestrator = new AiChatOrchestrator($mockOpenRouter);
        $orchestrator->generateAiResponse($conversation);
    }

    public function test_job_is_dispatched_to_dedicated_chat_queue(): void
    {
        $conversation = ChatConversation::create([
            'channel' => ChatChannelEnum::WEB,
            'agent_mode' => ChatAgentModeEnum::AI,
            'status' => 'open',
        ]);

        $message = $conversation->messages()->create([
            'sender_type' => ChatSenderTypeEnum::CUSTOMER,
            'message' => 'Hello',
        ]);

        $job = new ProcessAiChatResponseJob($conversation, $message);
        $this->assertEquals('chat', $job->queue);
    }

    public function test_whatsapp_text_sanitizer(): void
    {
        $service = new WhatsAppCloudApiService();

        $rawText = "### ডেলিভারি পলিসি\nআমাদের [ওয়েবসাইট](https://mohima.com) ভিজিট করুন। **১০০% অরিজিনাল**\n[SUGGESTIONS: অপশন ১ | অপশন ২]";

        $cleaned = $service->formatForWhatsApp($rawText);

        $this->assertStringNotContainsString('###', $cleaned);
        $this->assertStringNotContainsString('[SUGGESTIONS:', $cleaned);
        $this->assertStringContainsString('*ডেলিভারি পলিসি*', $cleaned);
        $this->assertStringContainsString('ওয়েবসাইট (https://mohima.com)', $cleaned);
        $this->assertStringContainsString('*১০০% অরিজিনাল*', $cleaned);
    }
}
