<?php

namespace App\Enums;

enum OrderStatusEnum: string
{
    case PLACED = 'Order Placed';
    case CONFIRMED = 'Confirmed';
    case PACKAGING = 'Packaging';
    case READY_TO_DELIVER = 'Ready To Deliver';
    case SHIPPED = 'Shipped';
    case DELIVERED = 'Delivered';
    case CANCELLED = 'Cancelled';
    case UNREACHABLE = 'Unreachable';
    case RETURNED = 'Returned';
}
