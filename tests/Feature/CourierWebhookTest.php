<?php

namespace Tests\Feature;

use App\Enums\OrderStatusEnum;
use App\Events\OrderStatusUpdatedEvent;
use App\Models\Account;
use App\Models\Order;
use App\Models\OrderParcel;
use Bonik\Courier\Events\CourierWebhookReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CourierWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_courier_webhook_updates_parcel_intermediate_status_without_changing_order_status()
    {
        $account = Account::create([
            'name' => 'Cash Account',
            'type' => \App\Enums\AccountTypeEnum::CASH->value,
            'account_number' => 'CASH-001',
            'balance' => 0,
            'is_active' => true,
        ]);

        $order = Order::create([
            'invoice_no' => 'INV-2001',
            'total_amount' => 500.00,
            'grand_total' => 500.00,
            'paid_amount' => 0.00,
            'change_amount' => 0.00,
            'account_id' => $account->id,
            'status' => OrderStatusEnum::READY_TO_DELIVER->value,
            'parcel_booking_status' => 'completed',
            'latest_courier_provider' => 'steadfast',
        ]);

        $parcel = OrderParcel::create([
            'order_id' => $order->id,
            'courier_provider' => 'steadfast',
            'tracking_code' => 'ST123456',
            'consignment_id' => '10001',
            'invoice_id' => 'INV-2001',
            'status' => 'pending',
            'raw_status' => 'in_review',
        ]);

        // Trigger in_transit webhook
        event(new CourierWebhookReceived('steadfast', [
            'tracking_code' => 'ST123456',
            'consignment_id' => '10001',
            'invoice_id' => 'INV-2001',
            'status' => 'in_transit',
            'raw_status' => 'dispatched',
            'history_entry' => ['note' => 'Dispatched from hub'],
        ]));

        $parcel->refresh();
        $order->refresh();

        $this->assertEquals('in_transit', $parcel->status);
        $this->assertEquals('dispatched', $parcel->raw_status);
        $this->assertEquals(OrderStatusEnum::READY_TO_DELIVER, $order->status);
    }

    public function test_courier_webhook_updates_order_status_to_delivered()
    {
        Event::fake([OrderStatusUpdatedEvent::class]);

        $account = Account::create([
            'name' => 'Cash Account',
            'type' => \App\Enums\AccountTypeEnum::CASH->value,
            'account_number' => 'CASH-002',
            'balance' => 0,
            'is_active' => true,
        ]);

        $order = Order::create([
            'invoice_no' => 'INV-2002',
            'total_amount' => 1200.00,
            'grand_total' => 1200.00,
            'paid_amount' => 0.00,
            'change_amount' => 0.00,
            'account_id' => $account->id,
            'status' => OrderStatusEnum::READY_TO_DELIVER->value,
            'parcel_booking_status' => 'completed',
            'latest_courier_provider' => 'steadfast',
        ]);

        $parcel = OrderParcel::create([
            'order_id' => $order->id,
            'courier_provider' => 'steadfast',
            'tracking_code' => 'ST789012',
            'consignment_id' => '10002',
            'invoice_id' => 'INV-2002',
            'status' => 'in_transit',
            'raw_status' => 'dispatched',
        ]);

        // Trigger delivered webhook
        event(new CourierWebhookReceived('steadfast', [
            'tracking_code' => 'ST789012',
            'consignment_id' => '10002',
            'invoice_id' => 'INV-2002',
            'status' => 'delivered',
            'raw_status' => 'delivered',
            'history_entry' => ['note' => 'Delivered to customer'],
        ]));

        $parcel->refresh();
        $order->refresh();

        $this->assertEquals('delivered', $parcel->status);
        $this->assertEquals(OrderStatusEnum::DELIVERED, $order->status);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status' => OrderStatusEnum::DELIVERED->value,
        ]);

        Event::assertDispatched(OrderStatusUpdatedEvent::class, function ($event) use ($order) {
            return $event->order->id === $order->id && $event->newStatus === OrderStatusEnum::DELIVERED;
        });
    }

    public function test_courier_webhook_updates_order_status_to_returned()
    {
        Event::fake([OrderStatusUpdatedEvent::class]);

        $account = Account::create([
            'name' => 'Cash Account',
            'type' => \App\Enums\AccountTypeEnum::CASH->value,
            'account_number' => 'CASH-003',
            'balance' => 0,
            'is_active' => true,
        ]);

        $order = Order::create([
            'invoice_no' => 'INV-2003',
            'total_amount' => 800.00,
            'grand_total' => 800.00,
            'paid_amount' => 0.00,
            'change_amount' => 0.00,
            'account_id' => $account->id,
            'status' => OrderStatusEnum::READY_TO_DELIVER->value,
            'parcel_booking_status' => 'completed',
            'latest_courier_provider' => 'steadfast',
        ]);

        $parcel = OrderParcel::create([
            'order_id' => $order->id,
            'courier_provider' => 'steadfast',
            'tracking_code' => 'ST456789',
            'consignment_id' => '10003',
            'invoice_id' => 'INV-2003',
            'status' => 'in_transit',
            'raw_status' => 'dispatched',
        ]);

        // Trigger returned webhook
        event(new CourierWebhookReceived('steadfast', [
            'tracking_code' => 'ST456789',
            'consignment_id' => '10003',
            'invoice_id' => 'INV-2003',
            'status' => 'returned',
            'raw_status' => 'returned',
            'history_entry' => ['note' => 'Customer rejected parcel'],
        ]));

        $parcel->refresh();
        $order->refresh();

        $this->assertEquals('returned', $parcel->status);
        $this->assertEquals(OrderStatusEnum::RETURNED, $order->status);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status' => OrderStatusEnum::RETURNED->value,
        ]);

        Event::assertDispatched(OrderStatusUpdatedEvent::class, function ($event) use ($order) {
            return $event->order->id === $order->id && $event->newStatus === OrderStatusEnum::RETURNED;
        });
    }

    public function test_courier_webhook_on_delivered_creates_cod_payment_in_holding_account()
    {
        Event::fake([OrderStatusUpdatedEvent::class]);

        $holdingAccount = Account::create([
            'name' => 'SteadFast Holding Account',
            'type' => \App\Enums\AccountTypeEnum::COURIER_HOLDING->value,
            'account_number' => 'ST-HOLD-001',
            'balance' => 0,
            'is_active' => true,
        ]);

        $order = Order::create([
            'invoice_no' => 'INV-2004',
            'total_amount' => 1500.00,
            'grand_total' => 1500.00,
            'paid_amount' => 0.00,
            'change_amount' => 0.00,
            'status' => OrderStatusEnum::READY_TO_DELIVER->value,
            'parcel_booking_status' => 'completed',
            'latest_courier_provider' => 'steadfast',
        ]);

        $parcel = OrderParcel::create([
            'order_id' => $order->id,
            'courier_provider' => 'steadfast',
            'tracking_code' => 'ST999999',
            'consignment_id' => '10004',
            'invoice_id' => 'INV-2004',
            'status' => 'in_transit',
            'raw_status' => 'dispatched',
        ]);

        // Trigger delivered webhook with collected COD amount
        event(new CourierWebhookReceived('steadfast', [
            'tracking_code' => 'ST999999',
            'consignment_id' => '10004',
            'invoice_id' => 'INV-2004',
            'status' => 'delivered',
            'raw_status' => 'delivered',
            'cod_amount' => 1500.00,
            'history_entry' => ['note' => 'Delivered and cash collected'],
        ]));

        $order->refresh();
        $holdingAccount->refresh();

        $this->assertEquals(OrderStatusEnum::DELIVERED, $order->status);
        $this->assertEquals(1500.00, (float) $order->paid_amount);
        $this->assertEquals(1500.00, (float) $holdingAccount->balance);

        // Check Payment and Allocation
        $this->assertDatabaseHas('payments', [
            'account_id' => $holdingAccount->id,
            'amount' => 1500.00,
            'type' => 'in',
            'payment_method' => 'cod',
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('payment_allocations', [
            'allocatable_type' => 'order',
            'allocatable_id' => $order->id,
            'allocated_amount' => 1500.00,
        ]);

        // Duplicate webhook trigger should NOT duplicate payment (idempotency guard)
        event(new CourierWebhookReceived('steadfast', [
            'tracking_code' => 'ST999999',
            'consignment_id' => '10004',
            'invoice_id' => 'INV-2004',
            'status' => 'delivered',
            'raw_status' => 'delivered',
        ]));

        $holdingAccount->refresh();
        $this->assertEquals(1500.00, (float) $holdingAccount->balance);
        $this->assertEquals(1, \App\Models\Payment::where('account_id', $holdingAccount->id)->count());
    }
}
