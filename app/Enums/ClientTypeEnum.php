<?php

namespace App\Enums;

enum ClientTypeEnum: string
{
    case CUSTOMER = 'customer';
    case SUPPLIER = 'supplier';
    case BOTH = 'both';
}
