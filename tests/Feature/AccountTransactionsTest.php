<?php

namespace Tests\Feature;

use App\Enums\AccountTypeEnum;
use App\Enums\TransactionCategoryEnum;
use App\Enums\TransactionTypeEnum;
use App\Models\Account;
use App\Models\Admin;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountTransactionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_fetch_account_transactions_with_filters(): void
    {
        $admin = Admin::create([
            'name' => 'Admin User',
            'email' => 'admin_test_tx@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $accountA = Account::create([
            'name' => 'Cash A',
            'type' => AccountTypeEnum::CASH,
            'balance' => 5000,
            'is_active' => true,
        ]);

        $accountB = Account::create([
            'name' => 'Bank B',
            'type' => AccountTypeEnum::BANK,
            'balance' => 10000,
            'is_active' => true,
        ]);

        Transaction::create([
            'account_id' => $accountA->id,
            'amount' => 5000,
            'type' => TransactionTypeEnum::IN,
            'category' => TransactionCategoryEnum::DEPOSIT,
            'description' => 'Initial capital',
            'created_by_id' => $admin->id,
        ]);

        Transaction::create([
            'account_id' => $accountB->id,
            'amount' => 10000,
            'type' => TransactionTypeEnum::IN,
            'category' => TransactionCategoryEnum::DEPOSIT,
            'description' => 'Bank opening',
            'created_by_id' => $admin->id,
        ]);

        // Filter by account A
        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/admin/accounts/transactions?account_id={$accountA->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('resources.0.account_id', $accountA->id)
            ->assertJsonPath('resources.0.amount', 5000)
            ->assertJsonPath('summary.current_balance', 5000);
    }
}
