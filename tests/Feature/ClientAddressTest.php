<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientAddressTest extends TestCase
{
    use RefreshDatabase;

    private function createCustomer(): Client
    {
        return Client::create([
            'name' => 'Test Customer',
            'contact_name' => 'Test Customer',
            'username' => 'testcustomer',
            'email' => 'customer@test.com',
            'phone' => '01711223344',
            'password' => bcrypt('password'),
            'status' => 'approved',
        ]);
    }

    public function test_can_list_customer_addresses()
    {
        $client = $this->createCustomer();

        ClientAddress::create([
            'client_id' => $client->id,
            'name' => 'Home Address',
            'phone' => '01711223344',
            'address' => 'House 1, Road 2',
            'city' => 'Dhaka',
            'label' => 'HOME',
            'is_default' => true,
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->getJson('/customer/me/addresses');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'resources');
    }

    public function test_can_create_customer_address()
    {
        $client = $this->createCustomer();

        $payload = [
            'name' => 'Office Address',
            'phone' => '01899887766',
            'address' => 'Level 4, Gulshan',
            'city' => 'Dhaka',
            'label' => 'WORK',
            'is_default' => true,
        ];

        $response = $this->actingAs($client, 'sanctum')
            ->postJson('/customer/me/addresses', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('client_addresses', [
            'client_id' => $client->id,
            'name' => 'Office Address',
            'is_default' => true,
        ]);
    }

    public function test_can_update_customer_address()
    {
        $client = $this->createCustomer();

        $address = ClientAddress::create([
            'client_id' => $client->id,
            'name' => 'Old Name',
            'phone' => '01711223344',
            'address' => 'Old Street',
            'city' => 'Dhaka',
            'label' => 'HOME',
            'is_default' => true,
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->putJson("/customer/me/addresses/{$address->id}", [
                'name' => 'Updated Name',
                'phone' => '01711223344',
                'address' => 'New Street 10',
                'city' => 'Dhaka',
                'label' => 'HOME',
                'is_default' => true,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('client_addresses', [
            'id' => $address->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_can_delete_customer_address()
    {
        $client = $this->createCustomer();

        $address = ClientAddress::create([
            'client_id' => $client->id,
            'name' => 'Temp Address',
            'phone' => '01711223344',
            'address' => 'Temp Street',
            'city' => 'Dhaka',
            'label' => 'HOME',
            'is_default' => true,
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->deleteJson("/customer/me/addresses/{$address->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('client_addresses', [
            'id' => $address->id,
        ]);
    }
}
