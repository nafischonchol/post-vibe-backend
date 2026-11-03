<?php

namespace Tests\Feature;

use App\Enums\OrderStatusEnum;
use App\Events\OrderPlacedEvent;
use App\Jobs\SendTikTokEventJob;
use App\Listeners\SendOrderPlacedTikTokEventListener;
use App\Models\Order;
use App\Models\SeoSetting;
use App\Services\Marketing\TikTokEventsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TikTokEventsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_format_user_data_hashes_sensitive_fields_for_tiktok()
    {
        $service = new TikTokEventsApiService;

        $rawData = [
            'email' => 'User@Example.Com ',
            'phone' => '01712345678',
            'ip' => '103.100.100.1',
            'user_agent' => 'Mozilla/5.0 TikTok Agent',
            'ttclid' => 'ttclid_123456789',
            'external_id' => '99',
        ];

        $formatted = $service->formatUserData($rawData);

        // Email should be sha256 of lowercase trimmed email
        $this->assertEquals(hash('sha256', 'user@example.com'), $formatted['email']);

        // BD phone 01712345678 should normalize to 8801712345678, prefixed with + and hashed
        $this->assertEquals(hash('sha256', '+8801712345678'), $formatted['phone_number']);

        // Unhashed fields
        $this->assertEquals('103.100.100.1', $formatted['ip']);
        $this->assertEquals('Mozilla/5.0 TikTok Agent', $formatted['user_agent']);
        $this->assertEquals('ttclid_123456789', $formatted['ttclid']);

        // External ID
        $this->assertEquals(hash('sha256', '99'), $formatted['external_id']);
    }

    public function test_tiktok_events_api_service_sends_http_request_when_credentials_present()
    {
        Http::fake([
            'https://business-api.tiktok.com/*' => Http::response([
                'code' => 0,
                'message' => 'OK',
                'data' => [],
            ], 200),
        ]);

        SeoSetting::create([
            'tiktok_pixel_id' => 'CTIKTOK123456789',
            'tiktok_access_token' => 'tiktok_access_token_abc123',
        ]);

        $service = new TikTokEventsApiService;

        $result = $service->sendEvent(
            eventName: 'CompletePayment',
            eventId: 'order_INV-2001',
            userData: [
                'email' => 'customer@test.com',
                'phone' => '01700000000',
            ],
            properties: [
                'currency' => 'BDT',
                'value' => 2500.0,
                'order_id' => 'INV-2001',
                'contents' => [
                    [
                        'content_id' => '101',
                        'content_name' => 'Glow Cream',
                        'price' => 2500.0,
                        'quantity' => 1,
                    ],
                ],
            ]
        );

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'open_api/v1.3/event/track/')
                && $request->header('Access-Token')[0] === 'tiktok_access_token_abc123'
                && $request['event_source_id'] === 'CTIKTOK123456789'
                && $request['data'][0]['event'] === 'CompletePayment'
                && $request['data'][0]['event_id'] === 'order_INV-2001'
                && $request['data'][0]['properties']['value'] == 2500.0;
        });
    }

    public function test_order_placed_event_dispatches_tiktok_event_job()
    {
        Queue::fake();

        $order = Order::create([
            'invoice_no' => 'INV-2026-TT-001',
            'client_id' => null,
            'client_snapshot' => [
                'name' => 'Karim Chowdhury',
                'phone' => '01811112222',
                'address' => 'Banani, Dhaka',
                'city' => 'Dhaka',
            ],
            'total_amount' => 1500,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'delivery_charge' => 60,
            'grand_total' => 1560,
            'paid_amount' => 0,
            'change_amount' => 0,
            'status' => OrderStatusEnum::PLACED->value,
        ]);

        event(new OrderPlacedEvent($order, [
            'ip' => '127.0.0.1',
            'user_agent' => 'TestBrowser',
            'ttclid' => 'ttclid_sample',
            'event_id' => 'order_INV-2026-TT-001',
        ]));

        Queue::assertPushed(\Illuminate\Events\CallQueuedListener::class, function ($job) {
            return $job->class === SendOrderPlacedTikTokEventListener::class;
        });
    }

    public function test_can_track_tiktok_client_event_via_api()
    {
        Queue::fake();

        $response = $this->postJson('/customer/analytics/track-event', [
            'event_name' => 'AddToCart',
            'event_id' => 'atc_product_88',
            'custom_data' => [
                'content_name' => 'Hydrating Toner',
                'contents' => [
                    [
                        'content_id' => '88',
                        'content_name' => 'Hydrating Toner',
                        'price' => 1200,
                        'quantity' => 1,
                    ],
                ],
                'value' => 1200,
                'currency' => 'BDT',
            ],
            'user_data' => [
                'ttclid' => 'ttclid_test_999',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        Queue::assertPushed(SendTikTokEventJob::class, function ($job) {
            return $job->eventName === 'AddToCart'
                && $job->eventId === 'atc_product_88'
                && $job->properties['value'] == 1200
                && $job->userData['ttclid'] === 'ttclid_test_999';
        });
    }
}
