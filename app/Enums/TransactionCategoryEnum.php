<?php

namespace App\Enums;

enum TransactionCategoryEnum: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAW = 'withdraw';
    case TRANSFER = 'transfer';
    case SALE = 'sale';
    case PURCHASE = 'purchase';
    case EXPENSE = 'expense';
}
