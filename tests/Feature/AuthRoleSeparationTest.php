<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRoleSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cannot_access_admin_endpoints(): void
    {
        $client = Client::create([
            'name' => 'John Customer',
            'email' => 'john@customer.test',
            'phone' => '01711111111',
            'password' => bcrypt('secret123'),
            'status' => 'approved',
            'is_active' => true,
        ]);

        $token = $client->createToken('customer_token')->plainTextToken;

        // Customer token attempting to access admin endpoints
        $responseProfile = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/admin/profile');
        $responseProfile->assertStatus(403);
        $responseProfile->assertJsonPath('success', false);

        $responseOrders = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/admin/orders');
        $responseOrders->assertStatus(403);

        $responseUsers = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/admin/users');
        $responseUsers->assertStatus(403);
    }

    public function test_admin_cannot_access_customer_me_endpoints(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@pos.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $token = $admin->createToken('admin_token')->plainTextToken;

        // Admin token attempting to access customer profile / orders
        $responseProfile = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/customer/me/profile');
        $responseProfile->assertStatus(403);
        $responseProfile->assertJsonPath('success', false);

        $responseOrders = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/customer/me/orders');
        $responseOrders->assertStatus(403);
    }

    public function test_admin_can_access_admin_endpoints(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@pos.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $token = $admin->createToken('admin_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/admin/profile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('resources.email', 'admin@pos.test');
    }

    public function test_customer_can_access_customer_me_endpoints(): void
    {
        $client = Client::create([
            'name' => 'Jane Customer',
            'email' => 'jane@customer.test',
            'phone' => '01822222222',
            'password' => bcrypt('secret123'),
            'status' => 'approved',
            'is_active' => true,
        ]);

        $token = $client->createToken('customer_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/customer/me/profile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('resources.phone', '01822222222');
    }
}
