<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChatAgentModeEnum;
use App\Enums\ChatSenderTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Events\ChatConversationUpdated;
use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Models\AiChatSetting;
use App\Models\ChatConversation;
use App\Models\Client;
use App\Models\Order;
use App\Services\AiChat\OpenRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LiveChatController extends Controller
{
    /**
     * List all chat conversations with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ChatConversation::query()
            ->with(['client', 'latestMessage', 'assignedAdmin'])
            ->withCount(['messages as unread_count' => function ($q) {
                $q->where('is_read', false)->where('sender_type', 'customer');
            }]);

        if ($request->filled('channel')) {
            $query->where('channel', $request->channel);
        }

        if ($request->filled('agent_mode')) {
            $query->where('agent_mode', $request->agent_mode);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $conversations = $query->orderByDesc('last_message_at')->paginate(25);

        $conversations->getCollection()->transform(function ($conversation) {
            $this->attachClientData($conversation);
            return $conversation;
        });

        return response()->json($conversations);
    }

    /**
     * Get details and messages for a specific conversation.
     */
    public function show(string $id): JsonResponse
    {
        $conversation = ChatConversation::with(['client', 'assignedAdmin'])->findOrFail($id);

        $this->attachClientData($conversation);

        $messages = $conversation->messages()
            ->orderBy('id', 'asc')
            ->get();

        // Mark customer messages as read
        $conversation->messages()
            ->where('is_read', false)
            ->where('sender_type', ChatSenderTypeEnum::CUSTOMER)
            ->update(['is_read' => true]);

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    /**
     * Attach client details, fraud rating, and order statistics to the conversation.
     */
    protected function attachClientData(ChatConversation $conversation): void
    {
        // If client relation is not set but customer_phone is present, try to link registered client
        if (! $conversation->client && ! empty($conversation->customer_phone)) {
            $rawPhone = $conversation->customer_phone;
            $cleanPhone = preg_replace('/[^\d]/', '', $rawPhone);
            $variants = array_unique(array_filter([
                $rawPhone,
                $cleanPhone,
                str_starts_with($cleanPhone, '880') ? '0' . substr($cleanPhone, 3) : null,
                str_starts_with($cleanPhone, '0') ? '88' . $cleanPhone : null,
                str_starts_with($cleanPhone, '0') ? substr($cleanPhone, 1) : null,
            ]));

            $matchedClient = Client::whereIn('phone', $variants)->first();
            if ($matchedClient) {
                $conversation->setRelation('client', $matchedClient);
            }
        }

        $client = $conversation->client;
        $phone = $client?->phone ?? $conversation->customer_phone;

        // If client has no client_rating yet, try to auto-populate from courier fraud report if available
        if ($client && empty($client->client_rating) && ! empty($phone)) {
            try {
                $courierService = app(\App\Services\Admin\CourierSettingService::class);
                $report = $courierService->getAndSyncFraudReport($phone, false);
                if ($report) {
                    $client->setAttribute('client_rating', $report);
                }
            } catch (\Throwable $e) {
                // Silently skip if external courier API fails or is unconfigured
            }
        }

        // Calculate order statistics (total, pending, delivered, cancelled)
        $orderStats = $this->getClientOrderStats($client, $phone);

        if ($client) {
            $client->setAttribute('order_stats', $orderStats);
        }
        $conversation->setAttribute('order_stats', $orderStats);
    }

    /**
     * Calculate order statistics for a given client and/or phone number.
     */
    protected function getClientOrderStats(?Client $client, ?string $phone): array
    {
        if (! $client && empty($phone)) {
            return [
                'total' => 0,
                'pending' => 0,
                'delivered' => 0,
                'cancelled' => 0,
            ];
        }

        $ordersQuery = Order::query()->where(function ($query) use ($client, $phone) {
            $hasCondition = false;
            if ($client) {
                $query->where('client_id', $client->id);
                $hasCondition = true;
            }
            if (! empty($phone)) {
                $rawPhone = $phone;
                $clean = preg_replace('/[^\d]/', '', $rawPhone);
                $variants = array_unique(array_filter([
                    $rawPhone,
                    $clean,
                    str_starts_with($clean, '880') ? '0' . substr($clean, 3) : null,
                    str_starts_with($clean, '0') ? '88' . $clean : null,
                    str_starts_with($clean, '0') ? substr($clean, 1) : null,
                ]));

                $phoneCallback = function ($sq) use ($variants) {
                    foreach ($variants as $v) {
                        $sq->orWhere('client_snapshot->phone', $v);
                    }
                };

                if ($hasCondition) {
                    $query->orWhere($phoneCallback);
                } else {
                    $query->where($phoneCallback);
                }
            }
        });

        $orders = $ordersQuery->get(['id', 'status']);
        $total = $orders->count();
        $pending = 0;
        $delivered = 0;
        $cancelled = 0;

        $pendingStatuses = [
            OrderStatusEnum::PLACED->value,
            OrderStatusEnum::CONFIRMED->value,
            OrderStatusEnum::PACKAGING->value,
            OrderStatusEnum::READY_TO_DELIVER->value,
            OrderStatusEnum::SHIPPED->value,
        ];

        $cancelledStatuses = [
            OrderStatusEnum::CANCELLED->value,
            OrderStatusEnum::UNREACHABLE->value,
            OrderStatusEnum::RETURNED->value,
        ];

        foreach ($orders as $order) {
            $status = $order->status instanceof OrderStatusEnum ? $order->status->value : (string) $order->status;
            if ($status === OrderStatusEnum::DELIVERED->value) {
                $delivered++;
            } elseif (in_array($status, $pendingStatuses, true)) {
                $pending++;
            } elseif (in_array($status, $cancelledStatuses, true)) {
                $cancelled++;
            }
        }

        return [
            'total' => $total,
            'pending' => $pending,
            'delivered' => $delivered,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * Admin manual reply to customer.
     */
    public function reply(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'message' => 'nullable|string|max:2000|required_without:file',
            'file' => 'nullable|file|max:20480|mimes:jpg,jpeg,png,webp,gif,svg,pdf,doc,docx,txt,odt,rtf,xls,xlsx,csv,mp4,mov,avi,mkv,webm',
        ]);

        $conversation = ChatConversation::findOrFail($id);
        $admin = $request->user();

        // When admin replies, automatically switch conversation to human mode
        if ($conversation->agent_mode !== ChatAgentModeEnum::HUMAN) {
            $conversation->agent_mode = ChatAgentModeEnum::HUMAN;
            $conversation->assigned_admin_id = $admin->id;
            $conversation->save();

            broadcast(new ChatConversationUpdated($conversation))->toOthers();
        }

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
                in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg']) => 'image',
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

        $message = $conversation->messages()->create([
            'sender_type' => ChatSenderTypeEnum::ADMIN,
            'sender_id' => $admin->id,
            'message' => $request->input('message') ?: '',
            'file_url' => $fileUrl,
            'file_name' => $fileName,
            'file_type' => $fileType,
            'file_size' => $fileSize,
            'is_read' => true,
        ]);

        $conversation->update(['last_message_at' => now()]);

        // Broadcast to customer and admin channels
        broadcast(new ChatMessageSent($message))->toOthers();

        return response()->json([
            'message' => $message,
            'conversation' => $conversation,
        ]);
    }

    /**
     * Toggle or update agent mode ('ai' vs 'human').
     */
    public function updateMode(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'agent_mode' => 'required|string|in:ai,human',
        ]);

        $conversation = ChatConversation::findOrFail($id);
        $conversation->agent_mode = $request->agent_mode;

        if ($request->agent_mode === 'human') {
            $conversation->assigned_admin_id = $request->user()->id;
        }

        $conversation->save();

        broadcast(new ChatConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => "Conversation switched to {$request->agent_mode} mode.",
            'conversation' => $conversation,
        ]);
    }

    /**
     * Get AI settings.
     */
    public function getSettings(): JsonResponse
    {
        $setting = AiChatSetting::getActiveSetting();
        return response()->json($setting);
    }

    /**
     * Update AI settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $request->validate([
            'model_name' => 'required|string|max:255',
            'system_prompt' => 'nullable|string',
            'is_ai_enabled' => 'required|boolean',
            'temperature' => 'required|numeric|min:0|max:2',
            'max_tokens' => 'required|integer|min:100|max:4000',
        ]);

        $setting = AiChatSetting::getActiveSetting();
        $setting->update($request->only([
            'model_name',
            'system_prompt',
            'is_ai_enabled',
            'temperature',
            'max_tokens',
        ]));

        return response()->json([
            'message' => 'AI Settings updated successfully.',
            'setting' => $setting,
        ]);
    }

    /**
     * Get available OpenRouter models
     */
    public function getModels(OpenRouterService $openRouter): JsonResponse
    {
        $models = $openRouter->getAvailableModels();
        return response()->json(['models' => $models]);
    }
}
