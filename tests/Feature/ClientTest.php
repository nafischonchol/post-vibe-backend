<?php

namespace Tests\Feature;

use App\Enums\ClientTypeEnum;
use App\Models\Admin;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    private function authenticateAdmin()
    {
        $adminUser = Admin::create([
            'name' => 'John Admin',
            'email' => 'john@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567891',
            'is_active' => true,
        ]);

        $this->actingAs($adminUser, 'sanctum');

        return $adminUser;
    }

    public function test_can_list_clients()
    {
        $adminUser = $this->authenticateAdmin();

        Client::create([
            'name' => 'Customer A',
            'phone' => '12345678',
            'email' => 'a@customer.com',
            'type' => ClientTypeEnum::CUSTOMER,
            'balance' => 100.50,
            'address' => 'Test Address A',
        ]);

        Client::create([
            'name' => 'Supplier B',
            'phone' => '87654321',
            'email' => 'b@supplier.com',
            'type' => ClientTypeEnum::SUPPLIER,
            'balance' => -500.00,
            'address' => 'Test Address B',
        ]);

        $response = $this->getJson('/admin/clients');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'resources');

        $response->assertJsonStructure([
            'resources' => [
                '*' => [
                    'id',
                    'name',
                    'phone',
                    'email',
                    'type',
                    'balance',
                    'address',
                    'is_active',
                    'created_at',
                    'updated_at',
                ],
            ],
        ]);

        $clientNames = collect($response->json('resources'))->pluck('name');
        $this->assertTrue($clientNames->contains('Customer A'));
        $this->assertTrue($clientNames->contains('Supplier B'));
    }

    public function test_can_lookup_clients()
    {
        $adminUser = $this->authenticateAdmin();

        Client::create([
            'name' => 'Active Customer',
            'phone' => '01711223344',
            'email' => 'active@customer.com',
            'type' => ClientTypeEnum::CUSTOMER,
            'balance' => 100.00,
            'is_active' => true,
        ]);

        Client::create([
            'name' => 'Inactive Customer',
            'phone' => '01899887766',
            'type' => ClientTypeEnum::CUSTOMER,
            'is_active' => false,
        ]);

        $response = $this->getJson('/admin/clients/lookup');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'resources');
        $response->assertJsonStructure([
            'resources' => [
                '*' => [
                    'id',
                    'name',
                    'phone',
                    'email',
                    'address',
                    'balance',
                ],
            ],
        ]);

        $this->assertEquals('Active Customer', $response->json('resources.0.name'));
    }

    public function test_can_create_client()
    {
        $adminUser = $this->authenticateAdmin();

        $response = $this->postJson('/admin/clients', [
            'name' => 'New Customer',
            'phone' => '01711122233',
            'email' => 'new@customer.com',
            'type' => 'customer',
            'balance' => 1250.75,
            'address' => 'Customer Residence',
            'is_active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('clients', [
            'name' => 'New Customer',
            'type' => 'customer',
            'balance' => 1250.75,
            'is_active' => false,
        ]);
    }

    public function test_can_update_client()
    {
        $adminUser = $this->authenticateAdmin();

        $client = Client::create([
            'name' => 'Original Name',
            'type' => ClientTypeEnum::CUSTOMER,
            'balance' => 100.00,
            'is_active' => true,
        ]);

        $response = $this->putJson("/admin/clients/{$client->id}", [
            'name' => 'Updated Name',
            'type' => 'both',
            'balance' => 150.00,
            'phone' => '01811122233',
            'email' => 'updated@client.com',
            'address' => 'New Address',
            'is_active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'name' => 'Updated Name',
            'type' => 'both',
            'balance' => 150.00,
            'is_active' => false,
        ]);
    }

    public function test_cannot_delete_client_via_api()
    {
        $adminUser = $this->authenticateAdmin();

        $client = Client::create([
            'name' => 'To Be Deleted',
            'type' => ClientTypeEnum::SUPPLIER,
        ]);

        $response = $this->deleteJson("/admin/clients/{$client->id}");

        $response->assertStatus(405);
    }

    public function test_can_create_and_update_client_password()
    {
        $adminUser = $this->authenticateAdmin();

        $response = $this->postJson('/admin/clients', [
            'name' => 'Client With Password',
            'type' => 'customer',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $clientId = $response->json('resources.id');
        $client = Client::find($clientId);
        $this->assertNotNull($client->password);
        $this->assertTrue(Hash::check('secret123', $client->password));

        // Update password
        $updateResponse = $this->putJson("/admin/clients/{$client->id}", [
            'name' => 'Client With Password',
            'type' => 'customer',
            'password' => 'newsecret456',
        ]);
        $updateResponse->assertStatus(200);
        $client->refresh();
        $this->assertTrue(Hash::check('newsecret456', $client->password));
    }
}
