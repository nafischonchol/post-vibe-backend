<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Account;
use App\Models\Client;
use App\Models\Transaction;
use App\Enums\TransactionCategoryEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\ClientTypeEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientTransactionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_fetch_paginated_client_transactions(): void
    {
        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin_tx@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $account = Account::create([
            'name' => 'Main Cash',
            'type' => 'cash',
            'balance' => 1000,
        ]);

        $client = Client::create([
            'name' => 'Test Client',
            'phone' => '01700000000',
            'type' => ClientTypeEnum::CUSTOMER,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'amount' => 1500,
            'type' => TransactionTypeEnum::IN,
            'category' => TransactionCategoryEnum::SALE,
            'description' => 'Test payment for sale',
            'created_by_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/admin/clients/{$client->id}/transactions");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('resources.0.amount', 1500);
    }
}
