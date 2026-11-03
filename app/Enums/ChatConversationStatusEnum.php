<?php

namespace App\Enums;

enum ChatConversationStatusEnum: string
{
    case OPEN = 'open';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';
}
