<?php

namespace App\Listeners;

use App\Enums\AccountTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\PaymentStatusEnum;
use App\Enums\PaymentTypeEnum;
use App\Enums\TransactionCategoryEnum;
use App\Enums\TransactionTypeEnum;
use App\Events\OrderStatusUpdatedEvent;
use App\Models\Account;
use App\Models\OrderParcel;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Transaction;
use Bonik\Courier\Events\CourierWebhookReceived;
use Bonik\Courier\Supports\ParcelStatusMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateParcelStatusListener
{
    public function handle(CourierWebhookReceived $event): void
    {
        $providerKey = strtolower($event->provider);
        $data = $event->webhookData;

        $trackingCode = $data['tracking_code'] ?? null;
        $consignmentId = $data['consignment_id'] ?? null;
        $invoiceId = $data['invoice_id'] ?? null;
        $normalizedStatus = $data['status'] ?? 'pending';

        // Find order_parcel entry matching tracking_code, consignment_id, or invoice_id
        $query = OrderParcel::with('order')->where('courier_provider', $providerKey);

        if ($trackingCode) {
            $query->where('tracking_code', $trackingCode);
        } elseif ($consignmentId) {
            $query->where('consignment_id', $consignmentId);
        } elseif ($invoiceId) {
            $query->where('invoice_id', $invoiceId);
        } else {
            return;
        }

        $parcel = $query->latest('id')->first();

        if (! $parcel) {
            return;
        }

        // Always track every intermediate status in order_parcels
        $parcel->update([
            'status' => $normalizedStatus,
            'raw_status' => $data['raw_status'] ?? $parcel->raw_status,
            'response_payload' => $data['history_entry'] ?? $data,
        ]);

        // If delivered or returned, update the main orders table status
        $order = $parcel->order;
        if (! $order) {
            return;
        }

        $targetOrderStatus = match ($normalizedStatus) {
            ParcelStatusMapper::STATUS_DELIVERED => OrderStatusEnum::DELIVERED,
            ParcelStatusMapper::STATUS_RETURNED => OrderStatusEnum::RETURNED,
            default => null,
        };

        if ($targetOrderStatus && $order->status !== $targetOrderStatus) {
            $oldStatus = $order->status;

            DB::transaction(function () use ($order, $targetOrderStatus, $providerKey, $normalizedStatus, $data) {
                $order->update([
                    'status' => $targetOrderStatus,
                ]);

                $rawStatus = $data['raw_status'] ?? $normalizedStatus;
                $order->statusHistories()->create([
                    'status' => $targetOrderStatus,
                    'note' => "Status updated to '{$targetOrderStatus->value}' via {$providerKey} courier webhook (Courier status: {$rawStatus}).",
                    'changed_by_id' => null,
                ]);

                // On DELIVERED: Process COD collection into Courier Holding Account
                if ($targetOrderStatus === OrderStatusEnum::DELIVERED) {
                    $this->processCodCollection($order, $providerKey, $data);
                }
            });

            $trackingContext = [
                'source' => 'courier_webhook',
                'provider' => $providerKey,
            ];

            event(new OrderStatusUpdatedEvent($order, $oldStatus, $targetOrderStatus, $trackingContext));
        }
    }

    /**
     * Process COD collection payment into Courier Holding Account upon delivery.
     */
    private function processCodCollection($order, string $providerKey, array $data): void
    {
        // Idempotency check: don't process if COD payment already recorded for this order
        $alreadyAllocated = PaymentAllocation::where('allocatable_type', 'order')
            ->where('allocatable_id', $order->id)
            ->whereHas('payment', function ($q) {
                $q->where('payment_method', 'cod');
            })
            ->exists();

        if ($alreadyAllocated) {
            return;
        }

        // Determine collection amount (remaining due on order, or from webhook if provided)
        $dueAmount = max(0, (float) $order->grand_total - (float) $order->paid_amount);
        $collectedAmount = isset($data['cod_amount']) && is_numeric($data['cod_amount'])
            ? (float) $data['cod_amount']
            : (isset($data['collected_amount']) && is_numeric($data['collected_amount']) ? (float) $data['collected_amount'] : $dueAmount);

        if ($collectedAmount <= 0) {
            return;
        }

        // Resolve Courier Holding Account
        $holdingAccount = Account::where('type', AccountTypeEnum::COURIER_HOLDING)
            ->where('name', 'LIKE', '%' . ucfirst($providerKey) . '%')
            ->first()
            ?? Account::where('type', AccountTypeEnum::COURIER_HOLDING)->first();

        if (! $holdingAccount) {
            return;
        }

        $prefix = 'REC-COD-' . date('Ymd') . '-';
        do {
            $paymentNo = $prefix . strtoupper(Str::random(4));
        } while (Payment::where('payment_no', $paymentNo)->exists());

        $payment = Payment::create([
            'payment_no' => $paymentNo,
            'type' => PaymentTypeEnum::IN,
            'status' => PaymentStatusEnum::COMPLETED,
            'client_id' => $order->client_id,
            'account_id' => $holdingAccount->id,
            'amount' => $collectedAmount,
            'payment_date' => now(),
            'payment_method' => 'cod',
            'note' => "COD collection via " . ucfirst($providerKey) . " for Order #{$order->invoice_no}",
            'created_by_id' => null,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'allocatable_type' => 'order',
            'allocatable_id' => $order->id,
            'allocated_amount' => $collectedAmount,
        ]);

        Transaction::create([
            'account_id' => $holdingAccount->id,
            'client_id' => $order->client_id,
            'amount' => $collectedAmount,
            'type' => TransactionTypeEnum::IN,
            'category' => TransactionCategoryEnum::SALE,
            'reference_type' => 'payment',
            'reference_id' => $payment->id,
            'description' => "COD collection via " . ucfirst($providerKey) . " for Order #{$order->invoice_no}",
            'date' => now(),
            'created_by_id' => null,
        ]);

        $holdingAccount->increment('balance', $collectedAmount);
        $order->increment('paid_amount', $collectedAmount);
    }
}
