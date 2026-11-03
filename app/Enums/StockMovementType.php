<?php

namespace App\Enums;

enum StockMovementType: string
{
    case ADJUSTMENT = 'adjustment';
    case PURCHASE = 'purchase';
    case SALE = 'sale';
    case SALE_RETURN = 'sale_return';
    case PURCHASE_RETURN = 'purchase_return';
    case ORDER_EDIT_RESTOCK = 'order_edit_restock';
}
