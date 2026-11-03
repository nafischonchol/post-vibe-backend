<?php

namespace App\Services\Admin;

use App\Enums\ClientTypeEnum;
use App\Enums\PaymentStatusEnum;
use App\Enums\PaymentTypeEnum;
use App\Enums\TransactionCategoryEnum;
use App\Enums\TransactionTypeEnum;
use App\Models\Account;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Purchase;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierPaymentService
{
    /**
     * Generate a unique payment number for supplier due payment.
     */
    public function generatePaymentNo(): string
    {
        $prefix = 'PAY-' . date('Ymd') . '-';
        do {
            $code = $prefix . strtoupper(Str::random(4));
        } while (Payment::where('payment_no', $code)->exists());

        return $code;
    }

    /**
     * Pay due for a supplier using FIFO (First-In-First-Out) logic across oldest unpaid/partially_paid purchase invoices.
     */
    public function paySupplierDue(Client $supplier, Account $account, float $paidAmount, array $details = [], ?int $userId = null): Payment
    {
        if (!in_array($supplier->type, [ClientTypeEnum::SUPPLIER, ClientTypeEnum::BOTH])) {
            throw new \InvalidArgumentException('Selected client is not registered as a supplier.');
        }

        if ($paidAmount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if ($account->balance < $paidAmount) {
            throw new \InvalidArgumentException('Insufficient funds in the selected account.');
        }

        return DB::transaction(function () use ($supplier, $account, $paidAmount, $details, $userId) {
            $paymentDate = $details['payment_date'] ?? now();
            $paymentMethod = $details['payment_method'] ?? 'cash';
            $note = $details['note'] ?? null;
            $paymentNo = $details['payment_no'] ?? $this->generatePaymentNo();

            $createdById = ($userId && \App\Models\Admin::where('id', $userId)->exists()) ? $userId : null;

            // 1. Create master Payment record (Money OUT)
            $payment = Payment::create([
                'payment_no' => $paymentNo,
                'type' => PaymentTypeEnum::OUT,
                'status' => PaymentStatusEnum::COMPLETED,
                'client_id' => $supplier->id,
                'account_id' => $account->id,
                'amount' => $paidAmount,
                'payment_date' => $paymentDate,
                'payment_method' => $paymentMethod,
                'note' => $note ?: "Bulk due payment for Supplier {$supplier->name}",
                'created_by_id' => $createdById,
            ]);

            // 2. Create financial transaction linked to payment
            Transaction::create([
                'account_id' => $account->id,
                'client_id' => $supplier->id,
                'amount' => $paidAmount,
                'type' => TransactionTypeEnum::OUT,
                'category' => TransactionCategoryEnum::PURCHASE,
                'reference_type' => 'payment',
                'reference_id' => $payment->id,
                'description' => "Bulk due payment to Supplier: {$supplier->name}",
                'date' => $paymentDate,
                'created_by_id' => $createdById,
            ]);

            // 3. Deduct account balance & decrement supplier balance (System Dena decreases)
            $account->decrement('balance', $paidAmount);
            $supplier->decrement('balance', $paidAmount);

            $remainingPayment = $paidAmount;

            // 4. If a target purchase_id is specified, settle that purchase first
            $targetPurchaseId = $details['purchase_id'] ?? null;
            $targetPurchase = null;

            if ($targetPurchaseId) {
                $targetPurchase = Purchase::where('id', $targetPurchaseId)
                    ->where('client_id', $supplier->id)
                    ->whereIn('payment_status', ['unpaid', 'partially_paid'])
                    ->where('due_amount', '>', 0)
                    ->first();

                if ($targetPurchase) {
                    $allocation = min($remainingPayment, (float) $targetPurchase->due_amount);

                    $newPaid = (float) $targetPurchase->paid_amount + $allocation;
                    $newDue = (float) $targetPurchase->due_amount - $allocation;
                    $newPaymentStatus = ($newDue <= 0.001) ? 'paid' : 'partially_paid';

                    $targetPurchase->update([
                        'paid_amount' => $newPaid,
                        'due_amount' => max(0.00, $newDue),
                        'payment_status' => $newPaymentStatus,
                    ]);

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'allocatable_type' => 'purchase',
                        'allocatable_id' => $targetPurchase->id,
                        'allocated_amount' => $allocation,
                    ]);

                    $remainingPayment -= $allocation;
                }
            }

            // 5. Allocate remaining payment to other oldest invoices sequentially (FIFO)
            if ($remainingPayment > 0) {
                $duePurchasesQuery = Purchase::where('client_id', $supplier->id)
                    ->whereIn('payment_status', ['unpaid', 'partially_paid'])
                    ->where('due_amount', '>', 0);

                if ($targetPurchase) {
                    $duePurchasesQuery->where('id', '!=', $targetPurchase->id);
                }

                $duePurchases = $duePurchasesQuery->orderBy('purchase_date', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                foreach ($duePurchases as $purchase) {
                    if ($remainingPayment <= 0) {
                        break;
                    }

                    $allocation = min($remainingPayment, (float) $purchase->due_amount);

                    $newPaid = (float) $purchase->paid_amount + $allocation;
                    $newDue = (float) $purchase->due_amount - $allocation;

                    $newPaymentStatus = ($newDue <= 0.001) ? 'paid' : 'partially_paid';

                    $purchase->update([
                        'paid_amount' => $newPaid,
                        'due_amount' => max(0.00, $newDue),
                        'payment_status' => $newPaymentStatus,
                    ]);

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'allocatable_type' => 'purchase',
                        'allocatable_id' => $purchase->id,
                        'allocated_amount' => $allocation,
                    ]);

                    $remainingPayment -= $allocation;
                }
            }

            return $payment->load(['allocations.allocatable', 'account', 'client']);
        });
    }
}
