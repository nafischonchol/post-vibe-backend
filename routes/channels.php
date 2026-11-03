<?php

use App\Models\Admin;
use App\Models\ChatConversation;
use App\Models\Client;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.conversation.{id}', function ($user, $id) {
    if ($user instanceof Admin) {
        return true;
    }
    if ($user instanceof Client) {
        $conversation = ChatConversation::find($id);
        return $conversation && (int) $conversation->client_id === (int) $user->id;
    }
    return false;
}, ['guards' => ['admin', 'client', 'sanctum']]);

Broadcast::channel('admin.chat', function ($user) {
    return $user instanceof Admin;
}, ['guards' => ['admin', 'sanctum']]);

