<?php

namespace App\Models;

use App\Enums\ChatAgentModeEnum;
use App\Enums\ChatChannelEnum;
use App\Enums\ChatConversationStatusEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'channel',
        'client_id',
        'external_user_id',
        'customer_name',
        'customer_phone',
        'agent_mode',
        'status',
        'assigned_admin_id',
        'last_message_at',
        'conversation_summary',
        'last_summarized_message_id',
        'summary_updated_at',
        'conversation_state',
        'pending_order_data',
        'is_phone_verified',
    ];

    protected $casts = [
        'channel' => ChatChannelEnum::class,
        'agent_mode' => ChatAgentModeEnum::class,
        'status' => ChatConversationStatusEnum::class,
        'last_message_at' => 'datetime',
        'last_summarized_message_id' => 'integer',
        'summary_updated_at' => 'datetime',
        'pending_order_data' => 'array',
        'is_phone_verified' => 'boolean',
    ];

    public function isInOrderFlow(): bool
    {
        return in_array($this->conversation_state, ['collecting_order_info', 'awaiting_otp'], true);
    }

    public function resetOrderFlow(): void
    {
        $this->update([
            'conversation_state' => 'idle',
            'pending_order_data' => null,
        ]);
    }

    /**
     * Determine if conversation has enough older unsummarized messages to warrant generating/refreshing summary.
     */
    public function shouldUpdateSummary(int $windowSize = 12, int $minNewMessages = 5): bool
    {
        $totalMessages = $this->messages()->count();
        if ($totalMessages <= $windowSize) {
            return false;
        }

        // Get the ID of the message at cutoff (the oldest message inside the active window)
        $cutoffMessage = $this->messages()
            ->orderBy('id', 'desc')
            ->skip($windowSize)
            ->first();

        if (!$cutoffMessage) {
            return false;
        }

        if (!$this->last_summarized_message_id) {
            // Never summarized before; check if older messages count meets min threshold
            $olderCount = $this->messages()->where('id', '<=', $cutoffMessage->id)->count();
            return $olderCount >= $minNewMessages;
        }

        // Check how many unsummarized messages exist between last summarized message and the window cutoff
        $unsummarizedCount = $this->messages()
            ->where('id', '>', $this->last_summarized_message_id)
            ->where('id', '<=', $cutoffMessage->id)
            ->count();

        return $unsummarizedCount >= $minNewMessages;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_conversation_id');
    }

    public function latestMessage()
    {
        return $this->hasOne(ChatMessage::class, 'chat_conversation_id')->latestOfMany();
    }
}
