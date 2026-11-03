<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierFraudCheckTest extends TestCase
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

    public function test_unauthenticated_request_is_blocked(): void
    {
        $response = $this->getJson('/admin/courier/check-fraud?phone=01712345678');
        $response->assertStatus(401);
    }

    public function test_invalid_phone_number_returns_422(): void
    {
        $this->authenticateAdmin();

        $response = $this->getJson('/admin/courier/check-fraud?phone=12345');
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_valid_phone_number_returns_courier_history(): void
    {
        $this->authenticateAdmin();

        $response = $this->getJson('/admin/courier/check-fraud?phone=01711111111');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'resources' => [
                    'phone',
                    'summary' => [
                        'total',
                        'success',
                        'cancel',
                        'success_rate',
                        'cancel_rate',
                        'risk_level',
                        'risk_label',
                    ],
                    'couriers',
                ],
            ]);
    }
}
