<?php

namespace Tests\Feature;

use App\Enums\AccountTypeEnum;
use App\Enums\ClientTypeEnum;
use App\Models\Account;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Admin $adminUser;
    private Client $customer;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = Admin::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'phone' => '01700000000',
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser, 'sanctum');

        $this->customer = Client::create([
            'name' => 'John Customer',
            'phone' => '01900000000',
            'email' => 'john@customer.com',
            'type' => ClientTypeEnum::CUSTOMER,
            'balance' => -5000.00, // System-er Paona 5,000 TK
            'is_active' => true,
        ]);

        $this->account = Account::create([
            'name' => 'Main Cash Account',
            'type' => AccountTypeEnum::CASH,
            'balance' => 10000.00,
            'is_active' => true,
        ]);

        Order::create([
            'invoice_no' => 'ORD-001',
            'client_id' => $this->customer->id,
            'total_amount' => 5000.00,
            'grand_total' => 5000.00,
            'paid_amount' => 0.00,
            'status' => \App\Enums\OrderStatusEnum::PLACED->value,
        ]);
    }

    public function test_can_fetch_customer_due_orders()
    {
        $response = $this->getJson("/admin/customers/{$this->customer->id}/due-orders");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.total_due', 5000);
        $response->assertJsonPath('data.due_orders_count', 1);
    }

    public function test_can_collect_customer_due_payment_and_updates_balances()
    {
        $payload = [
            'account_id' => $this->account->id,
            'amount' => 3000.00,
            'payment_method' => 'cash',
            'note' => 'Partial due collection',
        ];

        $response = $this->postJson("/admin/customers/{$this->customer->id}/collect-due", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Account balance increased from 10,000 -> 13,000
        $this->assertDatabaseHas('accounts', [
            'id' => $this->account->id,
            'balance' => 13000.00,
        ]);

        // Customer balance increased from -5,000 -> -2,000
        $this->assertDatabaseHas('clients', [
            'id' => $this->customer->id,
            'balance' => -2000.00,
        ]);

        // Order paid_amount updated to 3,000
        $this->assertDatabaseHas('orders', [
            'client_id' => $this->customer->id,
            'paid_amount' => 3000.00,
        ]);

        // Payment record created
        $this->assertDatabaseHas('payments', [
            'client_id' => $this->customer->id,
            'account_id' => $this->account->id,
            'amount' => 3000.00,
            'type' => 'in',
            'status' => 'completed',
        ]);

        // PaymentAllocation record created
        $this->assertDatabaseHas('payment_allocations', [
            'allocatable_type' => 'order',
            'allocated_amount' => 3000.00,
        ]);
    }
}
