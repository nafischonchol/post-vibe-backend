<?php

namespace App\Http\Controllers\Customer;

use App\Enums\ChatAgentModeEnum;
use App\Enums\ChatChannelEnum;
use App\Enums\ChatConversationStatusEnum;
use App\Enums\ChatSenderTypeEnum;
use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Services\AiChat\AiChatOrchestrator;
use App\Services\AiChat\OpenRouterService;
use App\Services\AiChat\Tools\ProductSearchTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ChatController extends Controller
{
    public function __construct(
        protected AiChatOrchestrator $orchestrator
    ) {}

    /**
     * Get or create the active chat conversation for the authenticated customer.
     */
    public function getConversation(Request $request): JsonResponse
    {
        $client = $request->user();

        $conversation = ChatConversation::firstOrCreate(
            [
                'client_id' => $client->id,
                'channel' => ChatChannelEnum::WEB,
                'status' => ChatConversationStatusEnum::OPEN,
            ],
            [
                'customer_name' => $client->name,
                'customer_phone' => $client->phone,
                'agent_mode' => ChatAgentModeEnum::AI,
                'last_message_at' => now(),
            ]
        );

        $messages = $conversation->messages()
            ->orderBy('id', 'asc')
            ->limit(50)
            ->get();

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'agent_mode' => $conversation->agent_mode?->value,
                'status' => $conversation->status->value ?? (string) $conversation->status,
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * Fetch messages for the conversation.
     */
    public function getMessages(Request $request): JsonResponse
    {
        $client = $request->user();

        $conversation = ChatConversation::where('client_id', $client->id)
            ->where('channel', ChatChannelEnum::WEB)
            ->where('status', ChatConversationStatusEnum::OPEN)
            ->first();

        if (!$conversation) {
            return response()->json(['messages' => []]);
        }

        $messages = $conversation->messages()
            ->orderBy('id', 'asc')
            ->limit(100)
            ->get();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Send a message from the customer with optional file attachment.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'nullable|string|max:800|required_without:file',
            'file' => 'nullable|file|max:20480|mimes:jpg,jpeg,png,webp,gif,svg,pdf,doc,docx,txt,odt,rtf,xls,xlsx,csv,mp4,mov,avi,mkv,webm',
        ], [
            'message.max' => 'মেসেজ সর্বোচ্চ ৮০০ অক্ষরের মধ্যে হতে হবে।',
            'message.required_without' => 'অনুগ্রহ করে মেসেজ লিখুন অথবা ফাইল আপলোড করুন।',
            'file.max' => 'ফাইল সাইজ সর্বোচ্চ ২০ মেগাবাইট (20MB) হতে পারে।',
            'file.mimes' => 'শুধুমাত্র ইমেজ, PDF, ডক, এক্সেল এবং ভিডিও ফাইল গ্রহণযোগ্য।',
        ]);

        $client = $request->user();

        $conversation = ChatConversation::firstOrCreate(
            [
                'client_id' => $client->id,
                'channel' => ChatChannelEnum::WEB,
                'status' => ChatConversationStatusEnum::OPEN,
            ],
            [
                'customer_name' => $client->name,
                'customer_phone' => $client->phone,
                'agent_mode' => ChatAgentModeEnum::AI,
                'last_message_at' => now(),
            ]
        );

        $fileUrl = null;
        $fileName = null;
        $fileType = null;
        $fileSize = null;

        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            $fileName = $uploadedFile->getClientOriginalName();
            $fileSize = $uploadedFile->getSize();
            $mime = $uploadedFile->getMimeType();
            $ext = strtolower($uploadedFile->getClientOriginalExtension());

            $fileType = match (true) {
                str_starts_with($mime, 'image/') => 'image',
                $ext === 'pdf' => 'pdf',
                in_array($ext, ['doc', 'docx', 'txt', 'odt', 'rtf']) => 'document',
                in_array($ext, ['xls', 'xlsx', 'csv']) => 'spreadsheet',
                in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm']) => 'video',
                default => 'file',
            };

            $disk = config('filesystems.default') === 'r2' && env('CLOUDFLARE_R2_ACCESS_KEY_ID') ? 'r2' : 'public';
            $path = $uploadedFile->store('chat_attachments', $disk);
            $fileUrl = Storage::disk($disk)->url($path);
        }

        // Save customer message
        $customerMessage = $conversation->messages()->create([
            'sender_type' => ChatSenderTypeEnum::CUSTOMER,
            'sender_id' => $client->id,
            'message' => $request->input('message') ?: '',
            'file_url' => $fileUrl,
            'file_name' => $fileName,
            'file_type' => $fileType,
            'file_size' => $fileSize,
            'is_read' => false,
        ]);

        $conversation->update(['last_message_at' => now()]);

        // Broadcast customer message to Reverb (Admin receives it in real-time)
        broadcast(new ChatMessageSent($customerMessage))->toOthers();

        // Human hand-off timeout: if no admin replied in the last 5 minutes, AI resumes
        if ($conversation->agent_mode === ChatAgentModeEnum::HUMAN) {
            $lastAdminReply = $conversation->messages()
                ->where('sender_type', ChatSenderTypeEnum::ADMIN)
                ->latest('id')
                ->first();

            if (!$lastAdminReply || $lastAdminReply->created_at->lt(now()->subMinutes(5))) {
                $conversation->update(['agent_mode' => ChatAgentModeEnum::AI]);
                broadcast(new \App\Events\ChatConversationUpdated($conversation))->toOthers();
            }
        }

        // Dispatch AI processing job asynchronously in queue if conversation is in AI mode
        if ($conversation->agent_mode === ChatAgentModeEnum::AI) {
            \App\Jobs\ProcessAiChatResponseJob::dispatch($conversation, $customerMessage);
        }

        return response()->json([
            'customer_message' => $customerMessage,
            'status' => 'queued',
            'agent_mode' => $conversation->agent_mode?->value,
        ]);
    }

    /**
     * Instant image lookup: fast vision call identifies the product from photo
     */
    public function identifyImage(Request $request, OpenRouterService $openRouter): JsonResponse
    {
        $request->validate(['message_id' => 'required|integer']);
        $client = $request->user();

        $message = \App\Models\ChatMessage::where('id', $request->integer('message_id'))
            ->whereHas('conversation', fn ($q) => $q->where('client_id', $client->id))
            ->first();

        if (!$message || !$message->file_url || $message->file_type !== 'image') {
            return response()->json(['query' => null, 'products' => []]);
        }

        $started = microtime(true);
        $visionMessages = [
            [
                'role' => 'system',
                'content' => 'You identify skincare / cosmetics products in photos for a Korean beauty and cosmetics store. Reply with ONLY the brand and product name as printed on the package (e.g. "COSRX Advanced Snail 96 Mucin Power Essence"). No sentences. If no product is visible reply NONE.',
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Which product is this?' . ($message->message ? ' Customer note: ' . $message->message : '')],
                    ['type' => 'image_url', 'image_url' => ['url' => $message->file_url, 'detail' => 'low']],
                ],
            ],
        ];

        $response = $openRouter->chatCompletion($visionMessages, [], config('services.openrouter.vision_model', 'google/gemini-2.5-flash-lite'), null, 60);
        if (empty($response['choices'][0]['message']['content'])) {
            $response = $openRouter->chatCompletion($visionMessages, [], null, null, 60);
        }

        $query = trim((string) ($response['choices'][0]['message']['content'] ?? ''), " \t\n\r\0\x0B\"'`*.");
        $query = trim(preg_replace('/\s+/u', ' ', $query));
        if ($query === '' || strtoupper($query) === 'NONE' || mb_strlen($query) > 120) {
            Log::info(sprintf('Chat image identify: nothing found (%.1fs)', microtime(true) - $started));
            return response()->json(['query' => null, 'products' => []]);
        }

        $products = ProductSearchTool::execute(['query' => $query, 'limit' => 4])['products'] ?? [];

        if (empty($products)) {
            $short = implode(' ', array_slice(preg_split('/\s+/u', $query), 0, 3));
            if ($short !== $query) {
                $products = ProductSearchTool::execute(['query' => $short, 'limit' => 4])['products'] ?? [];
            }
        }

        Log::info(sprintf('Chat image identify "%s": %d products in %.1fs', $query, count($products), microtime(true) - $started));

        return response()->json([
            'query' => $query,
            'products' => array_values(array_map(fn ($p) => collect($p)->except('specifications')->all(), $products)),
        ]);
    }
}
