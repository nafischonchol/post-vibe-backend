<?php

namespace App\Enums;

enum TransactionTypeEnum: string
{
    case IN = '+';
    case OUT = '-';
}
