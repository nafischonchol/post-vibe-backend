<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_with_otp()
    {
        ClientOtp::create([
            'phone' => '01700000001',
            'otp_code' => '123456',
            'purpose' => 'auth',
            'expires_at' => now()->addMinutes(5),
            'is_used' => false,
        ]);

        $payload = [
            'name' => 'Jane Doe',
            'phone' => '01700000001',
            'otp' => '123456',
            'password' => 'secret123',
        ];

        $response = $this->postJson('/customer/auth/register-otp', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('resources.client.phone', '01700000001')
            ->assertJsonPath('resources.client.name', 'Jane Doe');

        $this->assertDatabaseHas('clients', [
            'phone' => '01700000001',
            'name' => 'Jane Doe',
            'status' => 'approved',
        ]);
    }

    public function test_approved_client_can_login()
    {
        $client = Client::create([
            'name' => 'Approved Customer',
            'username' => 'approveduser',
            'email' => 'approved@example.com',
            'phone' => '01700000002',
            'password' => Hash::make('password123'),
            'address' => 'Test Address',
            'status' => 'approved',
            'is_active' => true,
        ]);

        $response = $this->postJson('/customer/login', [
            'login' => 'approveduser',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'resources' => [
                    'token',
                    'client' => ['id', 'name', 'email', 'username', 'phone', 'address', 'status'],
                ],
            ]);
    }

    public function test_pending_client_cannot_login()
    {
        Client::create([
            'name' => 'Pending Customer',
            'username' => 'pendinguser',
            'email' => 'pending@example.com',
            'phone' => '01700000003',
            'password' => Hash::make('password123'),
            'address' => 'Test Address',
            'status' => 'pending',
            'is_active' => false,
        ]);

        $response = $this->postJson('/customer/login', [
            'login' => 'pendinguser',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
