<?php

namespace App\Models;

use App\Enums\ChatSenderTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_conversation_id',
        'sender_type',
        'sender_id',
        'message',
        'file_url',
        'file_name',
        'file_type',
        'file_size',
        'payload',
        'tool_calls',
        'is_read',
    ];

    protected $casts = [
        'sender_type' => ChatSenderTypeEnum::class,
        'file_size' => 'integer',
        'payload' => 'array',
        'tool_calls' => 'array',
        'is_read' => 'boolean',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function getPayloadAttribute($value)
    {
        $data = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($data)) {
            return $data;
        }

        if (!empty($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as &$prod) {
                if (!empty($prod['image'])) {
                    $prod['image'] = Product::getImageUrl($prod['image']);
                }
            }
            unset($prod);
        }

        if (!empty($data['product_details']['image'])) {
            $data['product_details']['image'] = Product::getImageUrl($data['product_details']['image']);
        }

        if (!empty($data['product_details']['gallery_images']) && is_array($data['product_details']['gallery_images'])) {
            $data['product_details']['gallery_images'] = array_values(array_filter(array_map(
                fn($img) => Product::getImageUrl($img),
                $data['product_details']['gallery_images']
            )));
        }

        return $data;
    }
}
