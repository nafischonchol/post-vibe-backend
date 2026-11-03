<?php

namespace App\Actions\Order;

use App\Enums\OrderStatusEnum;
use App\Enums\PaymentStatusEnum;
use App\Enums\PaymentTypeEnum;
use App\Enums\TransactionCategoryEnum;
use App\Enums\TransactionTypeEnum;
use App\Events\OrderPlacedEvent;
use App\Http\Requests\Admin\Order\StoreOrderRequest;
use App\Models\Account;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProductVariant;
use App\Models\Transaction;
use App\Traits\HandlesOrderCreation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateOrderAction
{
    use HandlesOrderCreation;

    public function execute(StoreOrderRequest $request): Order
    {
        $userId = Auth::id();

        $items = $request->input('items');
        $accountId = $request->input('account_id');
        $discountAmount = (float) ($request->input('discount_amount') ?? 0);
        $taxRate = (float) ($request->input('tax_rate') ?? 0);
        $paidAmount = (float) $request->input('paid_amount');

        $order = DB::transaction(function () use ($request, $userId, $items, $accountId, $discountAmount, $taxRate, $paidAmount) {
            // Calculate totals
            $totalAmount = 0;
            $variantData = [];

            foreach ($items as $item) {
                $variant = ProductVariant::where('id', $item['product_variant_id'])

                    ->lockForUpdate()
                    ->firstOrFail();

                if ($variant->stock < $item['quantity']) {
                    throw new \RuntimeException("Insufficient stock for variant SKU: {$variant->sku}");
                }

                $lineTotal = (float) $item['unit_price'] * (int) $item['quantity'];
                $totalAmount += $lineTotal;

                $variantData[] = [
                    'variant' => $variant,
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                    'line_total' => $lineTotal,
                ];
            }

            $taxAmount = $taxRate > 0 ? ($totalAmount - $discountAmount) * ($taxRate / 100) : 0;
            $grandTotal = max(0, $totalAmount - $discountAmount + $taxAmount);
            $changeAmount = max(0, $paidAmount - $grandTotal);

            // Generate invoice no: INV{DDMMYYYY}-{NN}
            $invoiceNo = $this->generateInvoiceNo();

            // Build customer snapshot
            $clientId = $request->input('client_id');
            if ($paidAmount < $grandTotal && ! $clientId) {
                throw ValidationException::withMessages([
                    'paid_amount' => ['Walk-in customers cannot have a due balance.'],
                ]);
            }
            $clientSnapshot = null;
            if ($clientId) {
                $client = Client::where('id', $clientId)->firstOrFail();
                $clientSnapshot = [
                    'name' => $client->name,
                    'phone' => $client->phone,
                    'email' => $client->email,
                    'address' => $client->address,
                ];
            }

            // Create order
            $order = Order::create([
                'invoice_no' => $invoiceNo,
                'client_snapshot' => $clientSnapshot,
                'client_id' => $request->input('client_id', null),
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'change_amount' => $changeAmount,
                'status' => OrderStatusEnum::PLACED->value,
                'created_by_id' => $userId,
            ]);

            // Record initial status history
            $order->statusHistories()->create([
                'status' => OrderStatusEnum::PLACED,
                'note' => 'Order created.',
                'changed_by_id' => $userId,
            ]);

            // Create order items + reduce stock + record stock movement
            $this->createOrderItemsAndDeductStock($order, $variantData, $userId, 'Sale order');

            // Record payment + allocation + transaction + increment account balance
            if ($paidAmount > 0) {
                $account = Account::lockForUpdate()->findOrFail($accountId);

                $prefix = 'REC-' . date('Ymd') . '-';
                do {
                    $paymentNo = $prefix . strtoupper(Str::random(4));
                } while (Payment::where('payment_no', $paymentNo)->exists());

                $payment = Payment::create([
                    'payment_no' => $paymentNo,
                    'type' => PaymentTypeEnum::IN,
                    'status' => PaymentStatusEnum::COMPLETED,
                    'client_id' => $clientId,
                    'account_id' => $accountId,
                    'amount' => $paidAmount,
                    'payment_date' => now(),
                    'payment_method' => $request->input('payment_method', 'cash'),
                    'note' => "Payment received for {$invoiceNo}",
                    'created_by_id' => $userId,
                ]);

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'allocatable_type' => 'order',
                    'allocatable_id' => $order->id,
                    'allocated_amount' => min($paidAmount, $grandTotal),
                ]);

                Transaction::create([
                    'account_id' => $accountId,
                    'client_id' => $clientId,
                    'amount' => $paidAmount,
                    'type' => TransactionTypeEnum::IN,
                    'category' => TransactionCategoryEnum::SALE,
                    'reference_id' => $payment->id,
                    'reference_type' => 'payment',
                    'description' => "Payment received for {$invoiceNo}",
                    'date' => now(),
                    'created_by_id' => $userId,
                ]);

                $account->increment('balance', $paidAmount);
            }

            // Update client balance for customer order due (Negative = System Paona, Positive = System Dena)
            if ($clientId && isset($client)) {
                $netSaleDue = $grandTotal - $paidAmount;
                if ($netSaleDue > 0) {
                    $client->decrement('balance', $netSaleDue);
                } elseif ($netSaleDue < 0) {
                    $client->increment('balance', abs($netSaleDue));
                }
            }

            return $order;
        });

        if (! empty($order->client_id)) {
            $trackingContext = [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'action_source' => 'system_generated',
                'event_id' => 'order_'.$order->invoice_no,
            ];

            event(new OrderPlacedEvent($order, $trackingContext));
        }

        return $order;
    }
}
