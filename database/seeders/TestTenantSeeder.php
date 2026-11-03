<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantPublishingSetting;
use App\Models\TenantUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestTenantSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Demo Tenant
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo-studio'],
            [
                'name' => 'Demo Studio',
                'email' => 'contact@demostudio.com',
                'status' => 'active',
                'timezone' => 'Asia/Dhaka',
                'onboarding_completed_at' => now(),
            ]
        );

        // 2. Create Owner User for Demo Tenant
        TenantUser::updateOrCreate(
            ['email' => 'demo@gmail.com'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Demo Owner',
                'password' => Hash::make('demo@gmail.com'),
                'role' => 'owner',
                'status' => 'active',
            ]
        );

        // 3. Assign Pro Subscription to Tenant
        $proPlan = Plan::where('slug', 'pro')->first();
        if ($proPlan) {
            Subscription::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'plan_id' => $proPlan->id,
                    'status' => 'active',
                    'starts_at' => now(),
                    'expires_at' => now()->addYear(),
                    'billing_cycle' => 'yearly',
                ]
            );
        }

        // 4. Create Default Publishing Settings
        TenantPublishingSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'automatic_publishing_enabled' => false,
                'manual_publishing_enabled' => true,
                'timezone' => 'Asia/Dhaka',
                'preferred_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                'preferred_time_windows' => [
                    ['start' => '18:00', 'end' => '21:00'],
                ],
            ]
        );
    }
}
