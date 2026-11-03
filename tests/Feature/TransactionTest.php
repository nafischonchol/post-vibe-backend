<?php

use App\Enums\AccountTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\TransactionCategoryEnum;
use App\Enums\TransactionTypeEnum;
use App\Models\Account;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Transaction;
use App\Services\Admin\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function authenticateAdmin()
{
    $adminUser = Admin::create([
        'name' => 'John Admin',
        'email' => 'john@admin.com',
        'password' => bcrypt('password'),
        'phone' => '1234567891',
        'is_active' => true,
    ]);

    test()->actingAs($adminUser, 'sanctum');

    return $adminUser;
}

test('can deposit funds to account', function () {
    $adminUser = authenticateAdmin();

    $account = Account::create([
        'name' => 'Cash Account',
        'type' => AccountTypeEnum::CASH,
        'is_active' => true,
        'balance' => 100.00,
    ]);

    $service = new TransactionService;
    $transaction = $service->deposit($account, 50.00, 'Adding extra cash');

    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->amount)->toEqual(50.00);
    expect($transaction->type)->toBe(TransactionTypeEnum::IN);
    expect($transaction->category)->toBe(TransactionCategoryEnum::DEPOSIT);
    expect($transaction->description)->toBe('Adding extra cash');

    // Verify balance was updated
    $account->refresh();
    expect($account->balance)->toEqual(150.00);

    // Verify database record
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'account_id' => $account->id,
        'amount' => 50.00,
        'type' => '+',
        'category' => 'deposit',
    ]);
});

test('can withdraw funds from account', function () {
    $adminUser = authenticateAdmin();

    $account = Account::create([
        'name' => 'Cash Account',
        'type' => AccountTypeEnum::CASH,
        'is_active' => true,
        'balance' => 100.00,
    ]);

    $service = new TransactionService;
    $transaction = $service->withdraw($account, 40.00, 'Withdraw for personal use');

    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->amount)->toEqual(40.00);
    expect($transaction->type)->toBe(TransactionTypeEnum::OUT);
    expect($transaction->category)->toBe(TransactionCategoryEnum::WITHDRAW);

    $account->refresh();
    expect($account->balance)->toEqual(60.00);
});

test('cannot withdraw funds if balance is insufficient', function () {
    $adminUser = authenticateAdmin();

    $account = Account::create([
        'name' => 'Cash Account',
        'type' => AccountTypeEnum::CASH,
        'is_active' => true,
        'balance' => 30.00,
    ]);

    $service = new TransactionService;

    expect(fn () => $service->withdraw($account, 40.00))
        ->toThrow(InvalidArgumentException::class, 'Insufficient funds in the account.');

    $account->refresh();
    expect($account->balance)->toEqual(30.00);
});

test('can transfer funds between accounts', function () {
    $adminUser = authenticateAdmin();

    $accountA = Account::create([
        'name' => 'Cash Account',
        'type' => AccountTypeEnum::CASH,
        'is_active' => true,
        'balance' => 200.00,
    ]);

    $accountB = Account::create([
        'name' => 'Bank Account',
        'type' => AccountTypeEnum::BANK,
        'account_number' => '123456789',
        'is_active' => true,
        'balance' => 50.00,
    ]);

    $service = new TransactionService;
    $result = $service->transfer($accountA, $accountB, 120.00, 'Transfer cash to bank');

    expect($result)->toBeArray();
    expect($result['out_transaction']->type)->toBe(TransactionTypeEnum::OUT);
    expect($result['out_transaction']->account_id)->toBe($accountA->id);
    expect($result['out_transaction']->related_account_id)->toBe($accountB->id);

    expect($result['in_transaction']->type)->toBe(TransactionTypeEnum::IN);
    expect($result['in_transaction']->account_id)->toBe($accountB->id);
    expect($result['in_transaction']->related_account_id)->toBe($accountA->id);

    $accountA->refresh();
    $accountB->refresh();

    expect($accountA->balance)->toEqual(80.00);
    expect($accountB->balance)->toEqual(170.00);
});

test('cannot transfer if balance is insufficient or destination is same', function () {
    $adminUser = authenticateAdmin();

    $accountA = Account::create([
        'name' => 'Cash Account',
        'type' => AccountTypeEnum::CASH,
        'is_active' => true,
        'balance' => 20.00,
    ]);

    $accountB = Account::create([
        'name' => 'Bank Account',
        'type' => AccountTypeEnum::BANK,
        'account_number' => '123456789',
        'is_active' => true,
        'balance' => 50.00,
    ]);

    $service = new TransactionService;

    // Insufficient funds
    expect(fn () => $service->transfer($accountA, $accountB, 30.00))
        ->toThrow(InvalidArgumentException::class, 'Insufficient funds in the source account.');

    // Same account
    expect(fn () => $service->transfer($accountA, $accountA, 10.00))
        ->toThrow(InvalidArgumentException::class, 'Source and destination accounts must be different.');
});

test('can record sale order payment in account', function () {
    $adminUser = authenticateAdmin();

    $account = Account::create([
        'name' => 'Cash Account',
        'type' => AccountTypeEnum::CASH,
        'is_active' => true,
        'balance' => 0.00,
    ]);

    $order = Order::create([
        'invoice_no' => 'INVTEST-001',
        'total_amount' => 150.00,
        'discount_amount' => 10.00,
        'grand_total' => 140.00,
        'paid_amount' => 140.00,
        'change_amount' => 0.00,
        'account_id' => $account->id,
        'status' => OrderStatusEnum::PLACED->value,
    ]);

    $service = new TransactionService;
    $transaction = $service->recordOrderPayment($order, $account, 140.00);

    expect($transaction->type)->toBe(TransactionTypeEnum::IN);
    expect($transaction->category)->toBe(TransactionCategoryEnum::SALE);
    expect($transaction->reference_id)->toBe($order->id);
    expect($transaction->reference_type)->toBe(Order::class);

    $account->refresh();
    expect($account->balance)->toEqual(140.00);
});

test('can record purchase payment in account', function () {
    $adminUser = authenticateAdmin();

    $account = Account::create([
        'name' => 'Cash Account',
        'type' => AccountTypeEnum::CASH,
        'is_active' => true,
        'balance' => 500.00,
    ]);

    $purchase = Purchase::create([
        'supplier_name' => 'Supplier A',
        'total_amount' => 300.00,
        'status' => 'completed',
    ]);

    $service = new TransactionService;
    $transaction = $service->recordPurchasePayment($purchase, $account, 300.00);

    expect($transaction->type)->toBe(TransactionTypeEnum::OUT);
    expect($transaction->category)->toBe(TransactionCategoryEnum::PURCHASE);
    expect($transaction->reference_id)->toBe($purchase->id);
    expect($transaction->reference_type)->toBe(Purchase::class);

    $account->refresh();
    expect($account->balance)->toEqual(200.00);
});

test('can record expense in account', function () {
    $adminUser = authenticateAdmin();

    $account = Account::create([
        'name' => 'Cash Account',
        'type' => AccountTypeEnum::CASH,
        'is_active' => true,
        'balance' => 500.00,
    ]);

    $service = new TransactionService;
    $transaction = $service->recordExpense($account, 100.00, 'Office stationary expense');

    expect($transaction->type)->toBe(TransactionTypeEnum::OUT);
    expect($transaction->category)->toBe(TransactionCategoryEnum::EXPENSE);
    expect($transaction->description)->toBe('Office stationary expense');
    expect($transaction->reference_id)->toBeNull();

    $account->refresh();
    expect($account->balance)->toEqual(400.00);
});
