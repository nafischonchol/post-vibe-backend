<?php

namespace App\Services\Admin;

use App\Enums\ClientTypeEnum;
use App\Enums\PaymentStatusEnum;
use App\Enums\PaymentTypeEnum;
use App\Enums\StockMovementType;
use App\Enums\TransactionCategoryEnum;
use App\Enums\TransactionTypeEnum;
use App\Jobs\UpdateProductPricesFromPurchaseJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseChallan;
use App\Models\PurchaseChallanItem;
use App\Models\PurchaseItem;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseService
{
    /**
     * Generate a unique invoice number for purchase.
     */
    public function generateInvoiceNo(): string
    {
        $prefix = 'PUR-' . date('Ymd') . '-';
        do {
            $code = $prefix . strtoupper(Str::random(4));
        } while (Purchase::where('invoice_no', $code)->exists());

        return $code;
    }

    /**
     * Generate a unique payment number for purchase payment.
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
     * Generate a unique challan number for purchase receipt.
     */
    public function generateChallanNo(Purchase $purchase): string
    {
        $existingCount = $purchase->challans()->count();
        $sequence = $existingCount + 1;
        return 'CHAL-' . $purchase->invoice_no . '-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Create a new purchase with items and optional initial payment.
     */
    public function createPurchase(array $data, ?int $userId = null): Purchase
    {
        return DB::transaction(function () use ($data, $userId) {
            $itemsData = $data['items'] ?? [];
            if (empty($itemsData)) {
                throw new \InvalidArgumentException('Purchase must contain at least one item.');
            }

            // 1. Calculate total amount
            $totalAmount = 0.0;
            $totalOrderedQty = 0;
            $totalReceivedQty = 0;
            $itemsToCreate = [];

            foreach ($itemsData as $item) {
                $variant = ProductVariant::with('product')->findOrFail($item['product_variant_id']);
                $qty = (int) $item['quantity'];
                if ($qty <= 0) {
                    throw new \InvalidArgumentException('Item quantity must be greater than 0.');
                }

                // If received_quantity is specified, use it. Otherwise, if status is 'completed' or 'received', default to full qty.
                // If status was 'pending' and received_quantity not provided, default to 0.
                if (isset($item['received_quantity'])) {
                    $receivedQty = (int) $item['received_quantity'];
                } elseif (isset($data['status']) && in_array($data['status'], ['completed', 'received'])) {
                    $receivedQty = $qty;
                } elseif (isset($data['status']) && $data['status'] === 'pending') {
                    $receivedQty = 0;
                } else {
                    $receivedQty = $qty;
                }

                if ($receivedQty < 0) {
                    throw new \InvalidArgumentException('Received quantity cannot be negative.');
                }
                if ($receivedQty > $qty) {
                    throw new \InvalidArgumentException("Received quantity ({$receivedQty}) cannot exceed ordered quantity ({$qty}) for " . ($variant->product->name ?? 'product') . ".");
                }

                $costPrice = (float) $item['cost_price'];
                $sellingPrice = isset($item['selling_price']) && is_numeric($item['selling_price']) ? (float) $item['selling_price'] : null;
                $itemTotal = $qty * $costPrice;
                $totalAmount += $itemTotal;
                $totalOrderedQty += $qty;
                $totalReceivedQty += $receivedQty;

                $itemsToCreate[] = [
                    'variant' => $variant,
                    'quantity' => $qty,
                    'received_quantity' => $receivedQty,
                    'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                    'total' => $itemTotal,
                ];
            }

            // Determine stock status based on received quantities
            if (isset($data['status']) && $data['status'] === 'cancelled') {
                $status = 'cancelled';
            } elseif ($totalReceivedQty >= $totalOrderedQty) {
                $status = 'completed';
            } elseif ($totalReceivedQty > 0) {
                $status = 'partial';
            } else {
                $status = 'pending';
            }

            // 2. Handle payment amounts
            $initialPaid = isset($data['paid_amount']) ? (float) $data['paid_amount'] : 0.0;
            if ($initialPaid < 0) {
                throw new \InvalidArgumentException('Paid amount cannot be negative.');
            }

            // Current purchase allocation is capped at total purchase amount
            $currentPurchasePaid = min($initialPaid, $totalAmount);
            $dueAmount = max(0.00, $totalAmount - $currentPurchasePaid);

            if ($currentPurchasePaid >= $totalAmount) {
                $paymentStatus = 'paid';
            } elseif ($currentPurchasePaid > 0) {
                $paymentStatus = 'partially_paid';
            } else {
                $paymentStatus = 'unpaid';
            }

            $client = Client::findOrFail($data['client_id']);

            if ($client->type === ClientTypeEnum::CUSTOMER || $client->type?->value === 'customer') {
                $client->update([
                    'type' => ClientTypeEnum::BOTH,
                ]);
            }

            // Update client balance (Positive = System Payable / Dena, Negative = System Receivable / Paona)
            $netDueChange = $totalAmount - $initialPaid;
            if ($netDueChange > 0) {
                $client->increment('balance', $netDueChange);
            } elseif ($netDueChange < 0) {
                $client->decrement('balance', abs($netDueChange));
            }

            $invoiceNo = $data['invoice_no'] ?? $this->generateInvoiceNo();
            $purchaseDate = $data['purchase_date'] ?? now();

            $createdById = ($userId && \App\Models\Admin::where('id', $userId)->exists()) ? $userId : null;

            // 3. Create Purchase model
            $purchase = Purchase::create([
                'invoice_no' => $invoiceNo,
                'client_id' => $client->id,
                'supplier_name' => $client->name,
                'purchase_date' => $purchaseDate,
                'total_amount' => $totalAmount,
                'paid_amount' => $currentPurchasePaid,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
                'status' => $status,
                'note' => $data['note'] ?? null,
                'created_by_id' => $createdById,
            ]);

            // 4. Create Purchase items & record stock movements for received quantities
            $initialChallan = null;
            if ($totalReceivedQty > 0) {
                $initialChallan = PurchaseChallan::create([
                    'purchase_id' => $purchase->id,
                    'challan_no' => $this->generateChallanNo($purchase),
                    'received_date' => $purchase->purchase_date ?? now()->toDateString(),
                    'total_quantity' => $totalReceivedQty,
                    'note' => 'Initial stock receipt upon purchase invoice creation',
                    'received_by_id' => $createdById,
                ]);
            }

            $priceUpdates = [];
            foreach ($itemsToCreate as $item) {
                /** @var ProductVariant $variant */
                $variant = $item['variant'];

                $purchaseItem = PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'product_name' => $variant->product->name ?? 'Product',
                    'variant_title' => $variant->title,
                    'variant_sku' => $variant->sku,
                    'cost_price' => $item['cost_price'],
                    'selling_price' => $item['selling_price'],
                    'quantity' => $item['quantity'],
                    'received_quantity' => $item['received_quantity'],
                    'total' => $item['total'],
                ]);

                // Record Stock Movement and Challan Item only for received_quantity
                if ($item['received_quantity'] > 0) {
                    if ($initialChallan) {
                        PurchaseChallanItem::create([
                            'purchase_challan_id' => $initialChallan->id,
                            'purchase_item_id' => $purchaseItem->id,
                            'product_id' => $variant->product_id,
                            'product_variant_id' => $variant->id,
                            'quantity' => $item['received_quantity'],
                        ]);
                    }

                    StockMovementService::record(
                        $variant,
                        StockMovementType::PURCHASE,
                        $item['received_quantity'],
                        $purchase,
                        "Purchase Invoice #{$invoiceNo} (Initial Receive: {$item['received_quantity']}/{$item['quantity']})",
                        $createdById ? (string) $createdById : null
                    );
                }

                $priceUpdates[] = [
                    'product_variant_id' => $variant->id,
                    'cost_price' => $item['cost_price'],
                    'selling_price' => $item['selling_price'],
                ];
            }

            // Update product variant purchase and selling prices
            if (! empty($priceUpdates)) {
                UpdateProductPricesFromPurchaseJob::dispatchSync($priceUpdates);
            }

            // 5. Handle initial payment if provided
            if ($initialPaid > 0) {
                if (empty($data['account_id'])) {
                    throw new \InvalidArgumentException('Account ID is required when initial payment is made.');
                }

                $account = Account::findOrFail($data['account_id']);
                if ($account->balance < $initialPaid) {
                    throw new \InvalidArgumentException('Insufficient funds in selected account for initial payment.');
                }

                $paymentNote = "Payment for Purchase Invoice #{$invoiceNo}";
                if ($initialPaid > $totalAmount) {
                    $paymentNote .= " & previous due settlement";
                }

                $payment = Payment::create([
                    'payment_no' => $this->generatePaymentNo(),
                    'type' => PaymentTypeEnum::OUT,
                    'status' => PaymentStatusEnum::COMPLETED,
                    'client_id' => $client->id,
                    'account_id' => $account->id,
                    'amount' => $initialPaid,
                    'payment_date' => $purchaseDate,
                    'payment_method' => $data['payment_method'] ?? 'cash',
                    'note' => $paymentNote,
                    'created_by_id' => $createdById,
                ]);

                // 5a. Allocate to current purchase first
                if ($currentPurchasePaid > 0) {
                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'allocatable_type' => 'purchase',
                        'allocatable_id' => $purchase->id,
                        'allocated_amount' => $currentPurchasePaid,
                    ]);
                }

                // 5b. Allocate remaining payment to previous due purchases via FIFO
                $remainingPayment = $initialPaid - $currentPurchasePaid;

                if ($remainingPayment > 0) {
                    $previousDuePurchases = Purchase::where('client_id', $client->id)
                        ->where('id', '!=', $purchase->id)
                        ->whereIn('payment_status', ['unpaid', 'partially_paid'])
                        ->where('due_amount', '>', 0)
                        ->orderBy('purchase_date', 'asc')
                        ->orderBy('id', 'asc')
                        ->get();

                    foreach ($previousDuePurchases as $prevPurchase) {
                        if ($remainingPayment <= 0) {
                            break;
                        }

                        $allocation = min($remainingPayment, (float) $prevPurchase->due_amount);

                        $newPaid = (float) $prevPurchase->paid_amount + $allocation;
                        $newDue = (float) $prevPurchase->due_amount - $allocation;
                        $newStatus = ($newDue <= 0.001) ? 'paid' : 'partially_paid';

                        $prevPurchase->update([
                            'paid_amount' => $newPaid,
                            'due_amount' => max(0.00, $newDue),
                            'payment_status' => $newStatus,
                        ]);

                        PaymentAllocation::create([
                            'payment_id' => $payment->id,
                            'allocatable_type' => 'purchase',
                            'allocatable_id' => $prevPurchase->id,
                            'allocated_amount' => $allocation,
                        ]);

                        $remainingPayment -= $allocation;
                    }
                }

                Transaction::create([
                    'account_id' => $account->id,
                    'client_id' => $client->id,
                    'amount' => $initialPaid,
                    'type' => TransactionTypeEnum::OUT,
                    'category' => TransactionCategoryEnum::PURCHASE,
                    'reference_type' => 'payment',
                    'reference_id' => $payment->id,
                    'description' => $paymentNote,
                    'date' => $purchaseDate,
                    'created_by_id' => $createdById,
                ]);

                $account->decrement('balance', $initialPaid);
            }

            // 6. Automatically allocate from previous unallocated Advance Payments if this purchase still has remaining due
            if ((float) $purchase->due_amount > 0) {
                $excludePaymentId = isset($payment) ? $payment->id : 0;
                $unallocatedPayments = Payment::where('client_id', $client->id)
                    ->where('type', PaymentTypeEnum::OUT)
                    ->where('status', PaymentStatusEnum::COMPLETED)
                    ->where('id', '!=', $excludePaymentId)
                    ->whereRaw('amount > (SELECT COALESCE(SUM(allocated_amount), 0) FROM payment_allocations WHERE payment_allocations.payment_id = payments.id)')
                    ->orderBy('payment_date', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                $advanceSettledTotal = 0.0;
                $currentDue = (float) $purchase->due_amount;

                foreach ($unallocatedPayments as $advPayment) {
                    if ($currentDue <= 0.001) {
                        break;
                    }

                    $alreadyAllocated = (float) $advPayment->allocations()->sum('allocated_amount');
                    $unallocatedAmount = max(0.00, (float) $advPayment->amount - $alreadyAllocated);

                    if ($unallocatedAmount > 0) {
                        $allocation = min($currentDue, $unallocatedAmount);

                        PaymentAllocation::create([
                            'payment_id' => $advPayment->id,
                            'allocatable_type' => 'purchase',
                            'allocatable_id' => $purchase->id,
                            'allocated_amount' => $allocation,
                        ]);

                        $advanceSettledTotal += $allocation;
                        $currentDue -= $allocation;
                    }
                }

                if ($advanceSettledTotal > 0) {
                    $newPaid = (float) $purchase->paid_amount + $advanceSettledTotal;
                    $newDue = max(0.00, (float) $purchase->total_amount - $newPaid);
                    $newStatus = ($newDue <= 0.001) ? 'paid' : 'partially_paid';

                    $purchase->update([
                        'paid_amount' => $newPaid,
                        'due_amount' => $newDue,
                        'payment_status' => $newStatus,
                    ]);
                }
            }

            return $purchase->load([
                'client',
                'items.product',
                'items.variant',
                'allocations.payment.account',
                'challans.items.variant',
                'challans.items.purchaseItem',
                'challans.receivedBy',
                'createdBy',
            ]);
        });
    }

    /**
     * Receive pending items for a purchase invoice and generate a Challan.
     */
    public function receivePurchaseItems(
        Purchase $purchase,
        array $itemsToReceive,
        ?int $userId = null,
        ?string $note = null,
        ?string $receivedDate = null,
        ?string $customChallanNo = null
    ): Purchase {
        return DB::transaction(function () use ($purchase, $itemsToReceive, $userId, $note, $receivedDate, $customChallanNo) {
            $createdById = ($userId && \App\Models\Admin::where('id', $userId)->exists()) ? $userId : null;
            $invoiceNo = $purchase->invoice_no;

            if ($purchase->status === 'cancelled') {
                throw new \InvalidArgumentException('Cannot receive items for a cancelled purchase invoice.');
            }

            $allPurchaseItems = $purchase->items()->with('variant')->get()->keyBy('id');
            $totalUnitsToReceive = 0;
            $validatedReceiveList = [];

            foreach ($itemsToReceive as $entry) {
                $itemId = (int) ($entry['purchase_item_id'] ?? 0);
                $qtyToReceive = (int) ($entry['receive_quantity'] ?? 0);

                if ($qtyToReceive <= 0) {
                    continue;
                }

                /** @var PurchaseItem|null $item */
                $item = $allPurchaseItems->get($itemId);
                if (!$item) {
                    throw new \InvalidArgumentException("Purchase item #{$itemId} not found in this purchase invoice.");
                }

                $currentReceived = (int) $item->received_quantity;
                $orderedQty = (int) $item->quantity;
                $remaining = max(0, $orderedQty - $currentReceived);

                if ($qtyToReceive > $remaining) {
                    throw new \InvalidArgumentException("Receive quantity ({$qtyToReceive}) exceeds remaining pending quantity ({$remaining}) for {$item->product_name}.");
                }

                $totalUnitsToReceive += $qtyToReceive;
                $validatedReceiveList[] = [
                    'item' => $item,
                    'qty' => $qtyToReceive,
                    'current_received' => $currentReceived,
                ];
            }

            if (empty($validatedReceiveList)) {
                throw new \InvalidArgumentException('No valid item quantities to receive.');
            }

            // Create Challan record
            $challanNo = !empty($customChallanNo) ? trim($customChallanNo) : $this->generateChallanNo($purchase);
            $challan = PurchaseChallan::create([
                'purchase_id' => $purchase->id,
                'challan_no' => $challanNo,
                'received_date' => $receivedDate ?? now()->toDateString(),
                'total_quantity' => $totalUnitsToReceive,
                'note' => $note,
                'received_by_id' => $createdById,
            ]);

            foreach ($validatedReceiveList as $val) {
                /** @var PurchaseItem $item */
                $item = $val['item'];
                $qtyToReceive = $val['qty'];
                $currentReceived = $val['current_received'];

                $item->update([
                    'received_quantity' => $currentReceived + $qtyToReceive,
                ]);

                PurchaseChallanItem::create([
                    'purchase_challan_id' => $challan->id,
                    'purchase_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $qtyToReceive,
                ]);

                $variant = $item->variant;
                if ($variant) {
                    $reason = $note
                        ? "Received Challan #{$challanNo} (Invoice #{$invoiceNo}): {$note}"
                        : "Received Challan #{$challanNo} (Invoice #{$invoiceNo}) - {$qtyToReceive} units";

                    StockMovementService::record(
                        $variant,
                        StockMovementType::PURCHASE,
                        $qtyToReceive,
                        $purchase,
                        $reason,
                        $createdById ? (string) $createdById : null
                    );

                    $variant->update([
                        'purchase_price' => $item->cost_price,
                    ]);
                }
            }

            // Re-calculate overall purchase stock status
            $refreshedItems = $purchase->items()->get();
            $totalOrdered = $refreshedItems->sum('quantity');
            $totalReceived = $refreshedItems->sum('received_quantity');

            if ($totalReceived >= $totalOrdered) {
                $newStatus = 'completed';
            } elseif ($totalReceived > 0) {
                $newStatus = 'partial';
            } else {
                $newStatus = 'pending';
            }

            $purchase->update([
                'status' => $newStatus,
            ]);

            return $purchase->load([
                'client',
                'items.product',
                'items.variant',
                'allocations.payment.account',
                'challans.items.variant',
                'challans.items.purchaseItem',
                'challans.receivedBy',
                'createdBy',
            ]);
        });
    }
}

