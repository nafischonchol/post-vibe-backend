<?php

namespace Database\Seeders;

use App\Enums\AccountTypeEnum;
use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Account::firstOrCreate([
            'name' => 'Default Cash',
        ], [
            'type' => AccountTypeEnum::CASH,
            'balance' => 0,
            'is_active' => true,
            'is_editable' => false,
        ]);

        Account::firstOrCreate([
            'name' => 'SteadFast Holding Account',
        ], [
            'type' => AccountTypeEnum::COURIER_HOLDING,
            'balance' => 0,
            'is_active' => true,
            'is_editable' => false,
        ]);

        Account::firstOrCreate([
            'name' => 'Pathao Holding Account',
        ], [
            'type' => AccountTypeEnum::COURIER_HOLDING,
            'balance' => 0,
            'is_active' => true,
            'is_editable' => false,
        ]);
    }
}
