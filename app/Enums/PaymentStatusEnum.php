<?php

namespace App\Enums;

enum PaymentStatusEnum: string
{
    case COMPLETED = 'completed';
    case VOIDED = 'voided';
}
