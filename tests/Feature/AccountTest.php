<?php

namespace Tests\Feature;

use App\Enums\AccountTypeEnum;
use App\Models\Account;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountTest extends TestCase
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

    public function test_can_list_accounts()
    {
        $this->authenticateAdmin();

        Account::create([
            'name' => 'Cash Account',
            'type' => AccountTypeEnum::CASH,
            'is_active' => true,
        ]);

        $response = $this->getJson('/admin/accounts');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'resources');
    }

    public function test_can_create_cash_account_without_account_number()
    {
        $this->authenticateAdmin();

        $response = $this->postJson('/admin/accounts', [
            'name' => 'Cash Drawer',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('accounts', [
            'name' => 'Cash Drawer',
            'type' => 'cash',
            'account_number' => null,
        ]);
    }

    public function test_cannot_create_bank_account_without_account_number()
    {
        $this->authenticateAdmin();

        $response = $this->postJson('/admin/accounts', [
            'name' => 'My Bank',
            'type' => 'bank',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('account_number');
    }

    public function test_can_create_bank_account_with_account_number()
    {
        $this->authenticateAdmin();

        $response = $this->postJson('/admin/accounts', [
            'name' => 'My Bank',
            'type' => 'bank',
            'account_number' => '123456789',
            'is_active' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('accounts', [
            'name' => 'My Bank',
            'type' => 'bank',
            'account_number' => '123456789',
        ]);
    }

    public function test_can_update_account_types_and_rules()
    {
        $this->authenticateAdmin();

        $account = Account::create([
            'name' => 'Test Account',
            'type' => AccountTypeEnum::CASH,
            'is_active' => true,
        ]);

        // Try updating to bank without account_number (should fail)
        $response = $this->putJson("/admin/accounts/{$account->id}", [
            'name' => 'Updated Bank Account',
            'type' => 'bank',
            'is_active' => true,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('account_number');

        // Try updating to bank with account_number (should succeed)
        $response = $this->putJson("/admin/accounts/{$account->id}", [
            'name' => 'Updated Bank Account',
            'type' => 'bank',
            'account_number' => '987654321',
            'is_active' => true,
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'type' => 'bank',
            'account_number' => '987654321',
        ]);
    }

    public function test_can_add_funds_to_account()
    {
        $this->authenticateAdmin();

        $account = Account::create([
            'name' => 'Cash Account',
            'type' => AccountTypeEnum::CASH,
            'is_active' => true,
            'balance' => 0.00,
        ]);

        $response = $this->postJson("/admin/accounts/{$account->id}/add-funds", [
            'amount' => 150.50,
            'description' => 'Direct cash deposit',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'balance' => 150.50,
        ]);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $account->id,
            'amount' => 150.50,
            'type' => '+',
            'category' => 'deposit',
            'description' => 'Direct cash deposit',
        ]);
    }

    public function test_cannot_add_funds_with_invalid_amount()
    {
        $this->authenticateAdmin();

        $account = Account::create([
            'name' => 'Cash Account',
            'type' => AccountTypeEnum::CASH,
            'is_active' => true,
            'balance' => 0.00,
        ]);

        $response = $this->postJson("/admin/accounts/{$account->id}/add-funds", [
            'amount' => -10.00,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('amount');

        $response = $this->postJson("/admin/accounts/{$account->id}/add-funds", [
            'amount' => 'invalid-amount',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('amount');
    }
}
