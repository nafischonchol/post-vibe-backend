<?php

namespace Tests\Feature;

use App\Jobs\SyncClientCourierRatingJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientRatingSyncTest extends TestCase
{
    use RefreshDatabase;

    private function authenticateAdmin(): Admin
    {
        $adminUser = Admin::create([
            'name' => 'Admin User',
            'email' => 'admin@mohima.test',
            'password' => bcrypt('password'),
            'phone' => '01700000000',
            'is_active' => true,
        ]);

        $this->actingAs($adminUser, 'sanctum');

        return $adminUser;
    }

    public function test_check_fraud_updates_matching_client_rating_column(): void
    {
        $this->authenticateAdmin();

        $client = Client::create([
            'name' => 'John Doe',
            'phone' => '01712345678',
        ]);

        $this->assertNull($client->client_rating);

        $response = $this->getJson('/admin/courier/check-fraud?phone=01712345678');
        $response->assertStatus(200);

        $client->refresh();
        $this->assertNotNull($client->client_rating);
        $this->assertEquals('01712345678', $client->client_rating['phone']);
        $this->assertArrayHasKey('summary', $client->client_rating);
    }

    public function test_order_index_returns_client_rating_in_order_list(): void
    {
        $this->authenticateAdmin();

        $client = Client::create([
            'name' => 'Jane Doe',
            'phone' => '01799999999',
            'client_rating' => [
                'phone' => '01799999999',
                'summary' => [
                    'risk_level' => 'low',
                    'risk_label' => 'Safe Customer',
                    'success_rate' => 100,
                ],
            ],
        ]);

        $order = Order::create([
            'invoice_no' => 'INV-TEST-001',
            'client_id' => $client->id,
            'client_snapshot' => [
                'name' => 'Jane Doe',
                'phone' => '01799999999',
            ],
            'total_amount' => 1000,
            'grand_total' => 1000,
            'paid_amount' => 1000,
            'status' => 'Order Placed',
        ]);

        $response = $this->getJson('/admin/orders');
        $response->assertStatus(200);

        $data = $response->json('resources');
        $this->assertNotEmpty($data);
        $first = $data[0];
        $this->assertArrayHasKey('customer_rating', $first);
        $this->assertEquals('low', $first['customer_rating']['summary']['risk_level']);
    }

    public function test_sync_job_dispatched_when_new_client_is_created(): void
    {
        Queue::fake();

        Client::create([
            'name' => 'New Customer',
            'phone' => '01755555555',
        ]);

        Queue::assertPushed(SyncClientCourierRatingJob::class, function ($job) {
            return $job->phone === '01755555555';
        });
    }

    public function test_sync_job_dispatched_when_new_order_is_created(): void
    {
        Queue::fake();

        Order::create([
            'invoice_no' => 'INV-TEST-002',
            'client_snapshot' => [
                'name' => 'Order Customer',
                'phone' => '01766666666',
            ],
            'total_amount' => 500,
            'grand_total' => 500,
            'paid_amount' => 500,
            'status' => 'Order Placed',
        ]);

        Queue::assertPushed(SyncClientCourierRatingJob::class, function ($job) {
            return $job->phone === '01766666666';
        });
    }
}
