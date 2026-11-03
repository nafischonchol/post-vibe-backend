<?php

namespace App\Enums;

enum ChatSenderTypeEnum: string
{
    case CUSTOMER = 'customer';
    case AI = 'ai';
    case ADMIN = 'admin';
}
