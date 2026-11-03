<?php

namespace App\Services\Admin;

use App\Enums\ClientTypeEnum;
use App\Enums\PaymentStatusEnum;
use App\Enums\PaymentTypeEnum;
use App\Enums\TransactionCategoryEnum;
use App\Enums\TransactionTypeEnum;
use App\Models\Account;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerPaymentService
{
    /**
     * Generate a unique receipt number for customer payment.
     */
    public function generatePaymentNo(): string
    {
        $prefix = 'REC-' . date('Ymd') . '-';
        do {
            $code = $prefix . strtoupper(Str::random(4));
        } while (Payment::where('payment_no', $code)->exists());

        return $code;
    }

    /**
     * Collect due payment from a customer using FIFO logic across oldest unpaid sales orders.
     */
    public function collectCustomerDue(Client $customer, Account $account, float $amount, array $details = [], ?int $userId = null): array
    {
        if (!in_array($customer->type, [ClientTypeEnum::CUSTOMER, ClientTypeEnum::BOTH])) {
            throw new \InvalidArgumentException('Selected client is not registered as a customer.');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($customer, $account, $amount, $details, $userId) {
            $paymentDate = $details['payment_date'] ?? now();
            $paymentMethod = $details['payment_method'] ?? 'cash';
            $note = $details['note'] ?? null;
            $paymentNo = $details['payment_no'] ?? $this->generatePaymentNo();
            $createdById = ($userId && \App\Models\Admin::where('id', $userId)->exists()) ? $userId : null;

            // 1. Create master Payment record (Money IN)
            $payment = Payment::create([
                'payment_no' => $paymentNo,
                'type' => PaymentTypeEnum::IN,
                'status' => PaymentStatusEnum::COMPLETED,
                'client_id' => $customer->id,
                'account_id' => $account->id,
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'payment_method' => $paymentMethod,
                'note' => $note ?: "Due payment collected from Customer: {$customer->name}",
                'created_by_id' => $createdById,
            ]);

            // 2. Create financial transaction (Money IN to business account) linked to payment
            $transaction = Transaction::create([
                'account_id' => $account->id,
                'client_id' => $customer->id,
                'amount' => $amount,
                'type' => TransactionTypeEnum::IN,
                'category' => TransactionCategoryEnum::SALE,
                'reference_type' => 'payment',
                'reference_id' => $payment->id,
                'description' => $note ?: "Due payment collected from Customer: {$customer->name}",
                'date' => $paymentDate,
                'created_by_id' => $createdById,
            ]);

            // 3. Increment account balance & increment customer balance (Negative moves towards 0)
            $account->increment('balance', $amount);
            $customer->increment('balance', $amount);

            // 4. FIFO Order Due Allocation
            $remaining = $amount;
            $targetOrderId = $details['order_id'] ?? null;

            // 4a. Target order first if specified
            if ($targetOrderId) {
                $targetOrder = Order::where('id', $targetOrderId)
                    ->where('client_id', $customer->id)
                    ->whereRaw('grand_total > paid_amount')
                    ->first();

                if ($targetOrder) {
                    $orderDue = (float) $targetOrder->grand_total - (float) $targetOrder->paid_amount;
                    $allocation = min($remaining, $orderDue);

                    $targetOrder->increment('paid_amount', $allocation);

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'allocatable_type' => 'order',
                        'allocatable_id' => $targetOrder->id,
                        'allocated_amount' => $allocation,
                    ]);

                    $remaining -= $allocation;
                }
            }

            // 4b. Allocate remaining amount to other open due orders sequentially (FIFO)
            if ($remaining > 0) {
                $dueOrdersQuery = Order::where('client_id', $customer->id)
                    ->whereRaw('grand_total > paid_amount');

                if ($targetOrderId) {
                    $dueOrdersQuery->where('id', '!=', $targetOrderId);
                }

                $dueOrders = $dueOrdersQuery->orderBy('created_at', 'asc')->get();

                foreach ($dueOrders as $order) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $orderDue = (float) $order->grand_total - (float) $order->paid_amount;
                    $allocation = min($remaining, $orderDue);

                    $order->increment('paid_amount', $allocation);

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'allocatable_type' => 'order',
                        'allocatable_id' => $order->id,
                        'allocated_amount' => $allocation,
                    ]);

                    $remaining -= $allocation;
                }
            }

            return [
                'payment' => $payment->load(['allocations.allocatable', 'account', 'client']),
                'transaction' => $transaction,
                'customer' => $customer->fresh(),
                'account' => $account->fresh(),
                'amount_collected' => $amount,
            ];
        });
    }
}
