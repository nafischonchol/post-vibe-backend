<?php

namespace Tests\Feature;

use App\Enums\AccountTypeEnum;
use App\Enums\ClientTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Events\OrderPlacedEvent;
use App\Events\OrderStatusUpdatedEvent;
use App\Jobs\SendMetaCapiEventJob;
use App\Listeners\SendOrderPlacedMetaCapiListener;
use App\Listeners\SendOrderStatusUpdatedMetaCapiListener;
use App\Models\Account;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SeoSetting;
use App\Services\Marketing\MetaConversionApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaConversionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_format_user_data_hashes_sensitive_fields_correctly()
    {
        $service = new MetaConversionApiService;

        $rawData = [
            'email' => 'User@Example.Com ',
            'phone' => '01712345678',
            'name' => 'John Doe',
            'city' => 'Dhaka',
            'country' => 'bd',
            'ip' => '103.100.100.1',
            'user_agent' => 'Mozilla/5.0 Test Agent',
            'fbp' => 'fb.1.12345.67890',
            'fbc' => 'fb.1.12345.abcdef',
        ];

        $formatted = $service->formatUserData($rawData);

        // Email should be sha256 of lowercase trimmed email
        $this->assertEquals([hash('sha256', 'user@example.com')], $formatted['em']);

        // BD phone 01712345678 should normalize to 8801712345678 and be hashed
        $this->assertEquals([hash('sha256', '8801712345678')], $formatted['ph']);

        // First & Last Name
        $this->assertEquals([hash('sha256', 'john')], $formatted['fn']);
        $this->assertEquals([hash('sha256', 'doe')], $formatted['ln']);

        // City & Country
        $this->assertEquals([hash('sha256', 'dhaka')], $formatted['ct']);
        $this->assertEquals([hash('sha256', 'bd')], $formatted['country']);

        // Unhashed fields
        $this->assertEquals('103.100.100.1', $formatted['client_ip_address']);
        $this->assertEquals('Mozilla/5.0 Test Agent', $formatted['client_user_agent']);
        $this->assertEquals('fb.1.12345.67890', $formatted['fbp']);
        $this->assertEquals('fb.1.12345.abcdef', $formatted['fbc']);
    }

    public function test_meta_capi_service_sends_http_request_when_credentials_present()
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['events_received' => 1, 'messages' => []], 200),
        ]);

        SeoSetting::create([
            'meta_pixel_id' => '1234567890',
            'meta_capi_token' => 'EAACTestToken123',
        ]);

        $service = new MetaConversionApiService;

        $result = $service->sendEvent(
            eventName: 'Purchase',
            eventId: 'order_INV-1001',
            userData: [
                'email' => 'customer@test.com',
                'phone' => '01700000000',
            ],
            customData: [
                'currency' => 'BDT',
                'value' => 1500.0,
                'order_id' => 'INV-1001',
            ]
        );

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '1234567890/events')
                && $request['data'][0]['event_name'] === 'Purchase'
                && $request['data'][0]['event_id'] === 'order_INV-1001'
                && $request['data'][0]['custom_data']['value'] == 1500.0;
        });
    }

    public function test_order_placed_event_dispatches_purchase_meta_capi_job()
    {
        Queue::fake();

        $order = Order::create([
            'invoice_no' => 'INV-2026-0001',
            'client_id' => null,
            'client_snapshot' => [
                'name' => 'Sara Khan',
                'phone' => '01711112222',
                'address' => 'House 12, Road 4',
                'city' => 'Dhaka',
            ],
            'total_amount' => 1200,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'delivery_charge' => 60,
            'grand_total' => 1260,
            'paid_amount' => 0,
            'change_amount' => 0,
            'status' => OrderStatusEnum::PLACED->value,
        ]);

        event(new OrderPlacedEvent($order, [
            'ip' => '127.0.0.1',
            'user_agent' => 'TestBrowser',
            'fbp' => 'fb.1.test',
            'fbc' => 'fb.1.click',
            'event_id' => 'order_INV-2026-0001',
        ]));

        Queue::assertPushed(\Illuminate\Events\CallQueuedListener::class, function ($job) {
            return $job->class === SendOrderPlacedMetaCapiListener::class;
        });
    }

    public function test_order_status_updated_event_dispatches_proper_capi_events()
    {
        Queue::fake();

        $order = Order::create([
            'invoice_no' => 'INV-2026-0002',
            'client_id' => null,
            'client_snapshot' => [
                'name' => 'Rahim Ahmed',
                'phone' => '01811112222',
                'city' => 'Chittagong',
            ],
            'total_amount' => 2000,
            'grand_total' => 2000,
            'paid_amount' => 2000,
            'status' => OrderStatusEnum::SHIPPED->value,
        ]);

        // 1. Delivered Status -> OrderDelivered event
        event(new OrderStatusUpdatedEvent($order, OrderStatusEnum::SHIPPED, OrderStatusEnum::DELIVERED));

        Queue::assertPushed(\Illuminate\Events\CallQueuedListener::class, function ($job) {
            return $job->class === SendOrderStatusUpdatedMetaCapiListener::class;
        });

        // 2. Returned Status -> Refund event
        event(new OrderStatusUpdatedEvent($order, OrderStatusEnum::SHIPPED, OrderStatusEnum::RETURNED));

        Queue::assertPushed(\Illuminate\Events\CallQueuedListener::class, function ($job) {
            return $job->class === SendOrderStatusUpdatedMetaCapiListener::class;
        });

        // 3. Cancelled Status -> OrderCancelled event
        event(new OrderStatusUpdatedEvent($order, OrderStatusEnum::PLACED, OrderStatusEnum::CANCELLED));

        Queue::assertPushed(\Illuminate\Events\CallQueuedListener::class, function ($job) {
            return $job->class === SendOrderStatusUpdatedMetaCapiListener::class;
        });
    }

    public function test_can_track_client_event_via_api()
    {
        Queue::fake();

        $response = $this->postJson('/customer/analytics/track-event', [
            'event_name' => 'AddToCart',
            'event_id' => 'atc_product_45',
            'custom_data' => [
                'content_name' => 'Rose Serum',
                'content_ids' => ['45'],
                'content_type' => 'product',
                'value' => 850,
                'currency' => 'BDT',
            ],
            'user_data' => [
                'fbp' => 'fb.1.11111',
                'fbc' => 'fb.1.22222',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        Queue::assertPushed(SendMetaCapiEventJob::class, function ($job) {
            return $job->eventName === 'AddToCart'
                && $job->eventId === 'atc_product_45'
                && $job->customData['value'] == 850
                && $job->userData['fbp'] === 'fb.1.11111';
        });
    }

    public function test_admin_sale_with_selected_customer_dispatches_meta_capi()
    {
        Queue::fake();

        $admin = Admin::create([
            'name' => 'Admin User',
            'email' => 'admin@moima.com',
            'password' => bcrypt('password'),
            'phone' => '01711111111',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum');

        $client = Client::create([
            'name' => 'Selected Customer',
            'phone' => '01799998888',
            'type' => ClientTypeEnum::CUSTOMER,
            'status' => 'approved',
            'is_active' => true,
        ]);

        $category = Category::create(['name' => 'Skincare', 'slug' => 'skincare']);
        $product = Product::create([
            'name' => 'Serum',
            'slug' => 'serum-'.uniqid(),
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-SERUM-'.uniqid(),
            'price' => 500,
            'stock' => 10,
            'is_active' => true,
        ]);

        $account = Account::create([
            'name' => 'Cash',
            'type' => AccountTypeEnum::CASH,
            'account_number' => 'ACC-'.uniqid(),
            'balance' => 0,
            'is_active' => true,
        ]);

        $response = $this->postJson('/admin/orders', [
            'client_id' => $client->id,
            'account_id' => $account->id,
            'paid_amount' => 500,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                ],
            ],
        ]);

        $response->assertStatus(200);

        Queue::assertPushed(\Illuminate\Events\CallQueuedListener::class, function ($job) {
            return $job->class === SendOrderPlacedMetaCapiListener::class;
        });
    }

    public function test_admin_sale_with_walk_in_customer_does_not_dispatch_meta_capi()
    {
        Queue::fake();

        $admin = Admin::create([
            'name' => 'Admin User',
            'email' => 'admin2@moima.com',
            'password' => bcrypt('password'),
            'phone' => '01711111112',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum');

        $category = Category::create(['name' => 'Makeup', 'slug' => 'makeup']);
        $product = Product::create([
            'name' => 'Lipstick',
            'slug' => 'lipstick-'.uniqid(),
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-LIP-'.uniqid(),
            'price' => 300,
            'stock' => 10,
            'is_active' => true,
        ]);

        $account = Account::create([
            'name' => 'Cash Box',
            'type' => AccountTypeEnum::CASH,
            'account_number' => 'ACC-'.uniqid(),
            'balance' => 0,
            'is_active' => true,
        ]);

        // Walk-in customer has client_id = null
        $response = $this->postJson('/admin/orders', [
            'client_id' => null,
            'account_id' => $account->id,
            'paid_amount' => 300,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 1,
                    'unit_price' => 300,
                ],
            ],
        ]);

        $response->assertStatus(200);

        Queue::assertNotPushed(\Illuminate\Events\CallQueuedListener::class);
    }
}
