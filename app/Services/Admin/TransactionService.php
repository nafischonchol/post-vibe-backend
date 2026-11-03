<?php

namespace App\Services\Admin;

use App\Enums\TransactionCategoryEnum;
use App\Enums\TransactionTypeEnum;
use App\Models\Account;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    /**
     * Helper to get common transaction attributes.
     */
    protected function getBaseAttributes(Account $account, float $amount, TransactionTypeEnum $type, TransactionCategoryEnum $category, ?string $description = null): array
    {
        return [
            'account_id' => $account->id,
            'amount' => $amount,
            'type' => $type,
            'category' => $category,
            'description' => $description,
            'date' => now(),
            'created_by_id' => Auth::check() ? Auth::id() : null,
        ];
    }

    /**
     * Add funds (deposit) to an account.
     */
    public function deposit(Account $account, float $amount, ?string $description = null): Transaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        return DB::transaction(function () use ($account, $amount, $description) {
            $transaction = Transaction::create($this->getBaseAttributes(
                $account,
                $amount,
                TransactionTypeEnum::IN,
                TransactionCategoryEnum::DEPOSIT,
                $description
            ));

            $account->increment('balance', $amount);

            return $transaction;
        });
    }

    /**
     * Withdraw funds from an account.
     */
    public function withdraw(Account $account, float $amount, ?string $description = null): Transaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        if ($account->balance < $amount) {
            throw new \InvalidArgumentException('Insufficient funds in the account.');
        }

        return DB::transaction(function () use ($account, $amount, $description) {
            $transaction = Transaction::create($this->getBaseAttributes(
                $account,
                $amount,
                TransactionTypeEnum::OUT,
                TransactionCategoryEnum::WITHDRAW,
                $description
            ));

            $account->decrement('balance', $amount);

            return $transaction;
        });
    }

    /**
     * Transfer funds between two accounts.
     */
    public function transfer(Account $fromAccount, Account $toAccount, float $amount, ?string $description = null): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        if ($fromAccount->id === $toAccount->id) {
            throw new \InvalidArgumentException('Source and destination accounts must be different.');
        }

        if ($fromAccount->balance < $amount) {
            throw new \InvalidArgumentException('Insufficient funds in the source account.');
        }

        return DB::transaction(function () use ($fromAccount, $toAccount, $amount, $description) {
            // Outbound transaction for source account
            $outAttributes = $this->getBaseAttributes(
                $fromAccount,
                $amount,
                TransactionTypeEnum::OUT,
                TransactionCategoryEnum::TRANSFER,
                $description
            );
            $outAttributes['related_account_id'] = $toAccount->id;
            $outTransaction = Transaction::create($outAttributes);

            // Inbound transaction for destination account
            $inAttributes = $this->getBaseAttributes(
                $toAccount,
                $amount,
                TransactionTypeEnum::IN,
                TransactionCategoryEnum::TRANSFER,
                $description
            );
            $inAttributes['related_account_id'] = $fromAccount->id;
            $inTransaction = Transaction::create($inAttributes);

            // Update balances
            $fromAccount->decrement('balance', $amount);
            $toAccount->increment('balance', $amount);

            return [
                'out_transaction' => $outTransaction,
                'in_transaction' => $inTransaction,
            ];
        });
    }

    /**
     * Record payment received for a sales order.
     */
    public function recordOrderPayment(Order $order, Account $account, float $amount, ?string $description = null): Transaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        return DB::transaction(function () use ($order, $account, $amount, $description) {
            $attributes = $this->getBaseAttributes(
                $account,
                $amount,
                TransactionTypeEnum::IN,
                TransactionCategoryEnum::SALE,
                $description ?: "Payment received for Order #{$order->id}"
            );
            $attributes['reference_id'] = $order->id;
            $attributes['reference_type'] = Order::class;

            $transaction = Transaction::create($attributes);

            $account->increment('balance', $amount);

            return $transaction;
        });
    }

    /**
     * Record payment made for a purchase.
     */
    public function recordPurchasePayment(Purchase $purchase, Account $account, float $amount, ?string $description = null): Transaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        if ($account->balance < $amount) {
            throw new \InvalidArgumentException('Insufficient funds in the account for this purchase.');
        }

        return DB::transaction(function () use ($purchase, $account, $amount, $description) {
            $attributes = $this->getBaseAttributes(
                $account,
                $amount,
                TransactionTypeEnum::OUT,
                TransactionCategoryEnum::PURCHASE,
                $description ?: "Payment made for Purchase #{$purchase->id}"
            );
            $attributes['reference_id'] = $purchase->id;
            $attributes['reference_type'] = Purchase::class;

            $transaction = Transaction::create($attributes);

            $account->decrement('balance', $amount);

            return $transaction;
        });
    }

    /**
     * Record general or model-linked expense.
     */
    public function recordExpense(Account $account, float $amount, string $description, ?Model $expense = null): Transaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        if ($account->balance < $amount) {
            throw new \InvalidArgumentException('Insufficient funds in the account for this expense.');
        }

        return DB::transaction(function () use ($account, $amount, $description, $expense) {
            $attributes = $this->getBaseAttributes(
                $account,
                $amount,
                TransactionTypeEnum::OUT,
                TransactionCategoryEnum::EXPENSE,
                $description
            );

            if ($expense !== null) {
                $attributes['reference_id'] = $expense->getKey();
                $attributes['reference_type'] = get_class($expense);
            }

            $transaction = Transaction::create($attributes);

            $account->decrement('balance', $amount);

            return $transaction;
        });
    }
}
