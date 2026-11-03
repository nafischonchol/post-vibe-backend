<?php

namespace Tests\Feature;

use App\Enums\ChatAgentModeEnum;
use App\Enums\ChatChannelEnum;
use App\Enums\ChatSenderTypeEnum;
use App\Models\Admin;
use App\Models\ChatChannelSetting;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\ClientOtp;
use App\Models\ClientSocialIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OmnichannelChatTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create([
            'is_active' => true,
        ]);
    }

    public function test_admin_can_update_and_view_encrypted_channel_settings(): void
    {
        $payload = [
            'is_enabled' => true,
            'credentials' => [
                'page_access_token' => 'EAAG1234567890abcdef',
                'app_secret' => 'super_secret_hash_value',
                'verify_token' => 'mohima_fb_verify_token_123',
                'page_id' => '1029384756',
            ],
        ];

        // Update settings as admin
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/admin/live-chat/channel-settings/facebook', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.credentials.page_id', '1029384756');

        // Verify token is masked in response
        $maskedToken = $response->json('data.credentials.page_access_token');
        $this->assertStringContainsString('••••••••', $maskedToken);

        // Verify stored in DB encrypted
        $rawInDb = \DB::table('chat_channel_settings')->where('channel', 'facebook')->first();
        $this->assertNotNull($rawInDb);
        $this->assertStringNotContainsString('super_secret_hash_value', $rawInDb->credentials);

        // Verify model decrypts it automatically
        $setting = ChatChannelSetting::where('channel', 'facebook')->first();
        $this->assertEquals('super_secret_hash_value', $setting->credentials['app_secret']);
    }

    public function test_facebook_webhook_verification_challenge(): void
    {
        ChatChannelSetting::create([
            'channel' => ChatChannelEnum::FACEBOOK,
            'is_enabled' => true,
            'credentials' => [
                'verify_token' => 'fb_secret_token_123',
            ],
        ]);

        $response = $this->get('/webhooks/facebook?hub.mode=subscribe&hub.verify_token=fb_secret_token_123&hub.challenge=test_challenge_code_99');

        $response->assertStatus(200);
        $this->assertEquals('test_challenge_code_99', $response->getContent());
    }

    public function test_whatsapp_webhook_verification_challenge(): void
    {
        ChatChannelSetting::create([
            'channel' => ChatChannelEnum::WHATSAPP,
            'is_enabled' => true,
            'credentials' => [
                'verify_token' => 'wa_secret_token_456',
            ],
        ]);

        $response = $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wa_secret_token_456&hub.challenge=wa_challenge_code_88');

        $response->assertStatus(200);
        $this->assertEquals('wa_challenge_code_88', $response->getContent());
    }

    public function test_inbound_facebook_message_creates_conversation_and_message(): void
    {
        Queue::fake();

        $senderPsid = 'fb_user_123456';
        $payload = [
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'page_id_001',
                    'messaging' => [
                        [
                            'sender' => ['id' => $senderPsid],
                            'recipient' => ['id' => 'page_id_001'],
                            'message' => [
                                'mid' => 'mid_123',
                                'text' => 'Hello, I want to know about your serums!',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/webhooks/facebook', $payload);
        $response->assertStatus(200);

        // Verify conversation was created
        $conversation = ChatConversation::where('channel', ChatChannelEnum::FACEBOOK)
            ->where('external_user_id', $senderPsid)
            ->first();

        $this->assertNotNull($conversation);
        $this->assertEquals(ChatAgentModeEnum::AI, $conversation->agent_mode);

        // Verify message was created
        $message = ChatMessage::where('chat_conversation_id', $conversation->id)->first();
        $this->assertNotNull($message);
        $this->assertEquals('Hello, I want to know about your serums!', $message->message);
        $this->assertEquals(ChatSenderTypeEnum::CUSTOMER, $message->sender_type);
    }

    public function test_inbound_whatsapp_message_normalizes_phone_and_links_existing_client(): void
    {
        Queue::fake();

        // Existing client with 11-digit BD phone in database
        $client = Client::create([
            'name' => 'Fahim Rahman',
            'phone' => '01711223344',
            'email' => 'fahim@example.com',
        ]);

        // WhatsApp delivers international E.164 without plus: 8801711223344
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'contacts' => [
                                    ['profile' => ['name' => 'Fahim R.']],
                                ],
                                'messages' => [
                                    [
                                        'from' => '8801711223344',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'Amar order status kobe pabo?',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/webhooks/whatsapp', $payload);
        $response->assertStatus(200);

        // Verify conversation resolved directly to existing client
        $conversation = ChatConversation::where('channel', ChatChannelEnum::WHATSAPP)
            ->where('external_user_id', '8801711223344')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertEquals($client->id, $conversation->client_id);
        $this->assertEquals('01711223344', $conversation->customer_phone);
        $this->assertTrue($conversation->is_phone_verified);
    }

    public function test_conversational_order_otp_flow_and_permanent_client_linking(): void
    {
        Queue::fake();
        Http::fake();

        $psid = 'fb_buyer_999';

        // 1. Customer initiates order on Facebook Messenger by giving details
        $orderMessagePayload = [
            'object' => 'page',
            'entry' => [
                [
                    'messaging' => [
                        [
                            'sender' => ['id' => $psid],
                            'message' => [
                                'text' => "Ami order korte chai. Name: Tanvir Ahmed, Phone: 01799887766, Address: House 10, Road 4, Dhanmondi, Dhaka",
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $res1 = $this->postJson('/webhooks/facebook', $orderMessagePayload);
        $res1->assertStatus(200);

        $conversation = ChatConversation::where('channel', ChatChannelEnum::FACEBOOK)
            ->where('external_user_id', $psid)
            ->first();

        $this->assertNotNull($conversation);
        $this->assertEquals('awaiting_otp', $conversation->conversation_state);
        $this->assertEquals('01799887766', $conversation->customer_phone);

        // Verify OTP was generated in DB
        $otp = ClientOtp::where('phone', '01799887766')
            ->where('purpose', 'order')
            ->latest('id')
            ->first();

        $this->assertNotNull($otp);
        $code = $otp->otp_code;

        // 2. Customer submits the OTP code in Messenger
        $otpSubmissionPayload = [
            'object' => 'page',
            'entry' => [
                [
                    'messaging' => [
                        [
                            'sender' => ['id' => $psid],
                            'message' => [
                                'text' => $code,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $res2 = $this->postJson('/webhooks/facebook', $otpSubmissionPayload);
        $res2->assertStatus(200);

        $conversation->refresh();

        // State reset to idle
        $this->assertEquals('idle', $conversation->conversation_state);
        $this->assertTrue($conversation->is_phone_verified);

        // Client created in database
        $client = Client::where('phone', '01799887766')->first();
        $this->assertNotNull($client);
        $this->assertEquals($client->id, $conversation->client_id);

        // Social identity permanently linked
        $identity = ClientSocialIdentity::where('channel', ChatChannelEnum::FACEBOOK)
            ->where('external_id', $psid)
            ->first();

        $this->assertNotNull($identity);
        $this->assertEquals($client->id, $identity->client_id);

        // 3. Subsequent message from same PSID is immediately recognized
        $nextMessagePayload = [
            'object' => 'page',
            'entry' => [
                [
                    'messaging' => [
                        [
                            'sender' => ['id' => $psid],
                            'message' => [
                                'text' => 'Hi, thanks for confirming!',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/webhooks/facebook', $nextMessagePayload);

        $activeConv = ChatConversation::where('channel', ChatChannelEnum::FACEBOOK)
            ->where('external_user_id', $psid)
            ->first();

        $this->assertEquals($client->id, $activeConv->client_id);
    }
}
