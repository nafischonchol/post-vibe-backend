<?php

namespace App\Enums;

enum AccountTypeEnum: string
{
    case CASH = 'cash';
    case BANK = 'bank';
    case MOBILE_BANKING = 'mobile_banking';
    case CREDIT_CARD = 'credit_card';
    case COURIER_HOLDING = 'courier_holding';
}
