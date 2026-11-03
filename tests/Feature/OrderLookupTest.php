<?php

namespace Tests\Feature;

use App\Enums\OrderStatusEnum;
use App\Models\Admin;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_user_can_fetch_order_lookup_list()
    {
        $adminUser = Admin::create([
            'name' => 'Test Admin User',
            'email' => 'test@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567890',
            'is_active' => true,
        ]);

        Order::create([
            'invoice_no' => 'INV-001',
            'total_amount' => 100.00,
            'discount_amount' => 0.00,
            'grand_total' => 100.00,
            'paid_amount' => 100.00,
            'change_amount' => 0.00,
            'status' => OrderStatusEnum::PLACED->value,
        ]);

        Order::create([
            'invoice_no' => 'INV-002',
            'total_amount' => 200.00,
            'discount_amount' => 0.00,
            'grand_total' => 200.00,
            'paid_amount' => 200.00,
            'change_amount' => 0.00,
            'status' => OrderStatusEnum::CONFIRMED->value,
        ]);

        $this->actingAs($adminUser, 'sanctum');

        $response = $this->getJson('/admin/orders/lookup');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'resources' => [
                    '*' => [
                        'id',
                        'invoice_no',
                        'customer_name',
                        'status',
                        'grand_total',
                        'date',
                    ],
                ],
            ]);

        $this->assertCount(2, $response->json('resources'));
    }
}
