<?php

namespace App\Services\Admin;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantPublishingSetting;
use App\Models\TenantQuotaOverride;
use App\Models\TenantUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantManagementService
{
    /**
     * Get paginated list of tenants with subscription and usage stats.
     */
    public function listTenants(array $filters = []): LengthAwarePaginator
    {
        $query = Tenant::query()
            ->with(['subscription.plan'])
            ->withCount(['videos', 'publishingBatches']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new Tenant with Owner User and Subscription.
     */
    public function createTenant(array $data): Tenant
    {
        return DB::transaction(function () use ($data) {
            $slug = Str::slug($data['name']);
            if (Tenant::where('slug', $slug)->exists()) {
                $slug .= '-' . Str::lower(Str::random(5));
            }

            // 1. Create Tenant
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $slug,
                'email' => $data['email'],
                'timezone' => $data['timezone'] ?? 'UTC',
                'status' => 'active',
                'onboarding_completed_at' => now(),
            ]);

            // 2. Create Owner User
            TenantUser::create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => Hash::make($data['owner_password']),
                'role' => 'owner',
                'status' => 'active',
            ]);

            // 3. Assign Plan / Subscription
            $planId = $data['plan_id'] ?? Plan::where('slug', 'basic')->value('id');
            if ($planId) {
                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $planId,
                    'status' => 'active',
                    'starts_at' => now(),
                    'expires_at' => now()->addYear(),
                    'billing_cycle' => $data['billing_cycle'] ?? 'monthly',
                ]);
            }

            // 4. Default Publishing Settings
            TenantPublishingSetting::create([
                'tenant_id' => $tenant->id,
                'automatic_publishing_enabled' => false,
                'manual_publishing_enabled' => true,
                'timezone' => $tenant->timezone,
            ]);

            return $tenant->load(['subscription.plan', 'users']);
        });
    }

    /**
     * Update Tenant information.
     */
    public function updateTenant(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data) {
            $updateFields = [];
            if (array_key_exists('name', $data) && $data['name'] !== null) {
                $updateFields['name'] = $data['name'];
            }
            if (array_key_exists('email', $data) && $data['email'] !== null) {
                $updateFields['email'] = $data['email'];
            }
            if (array_key_exists('timezone', $data) && $data['timezone'] !== null) {
                $updateFields['timezone'] = $data['timezone'];
            }

            if (array_key_exists('status', $data) && ! empty($data['status']) && $data['status'] !== $tenant->status) {
                if ($data['status'] === 'active') {
                    $this->setStatus($tenant, 'active');
                } elseif ($data['status'] === 'suspended') {
                    $this->setStatus($tenant, 'suspended');
                } elseif ($data['status'] === 'rejected') {
                    $this->rejectTenant($tenant);
                } else {
                    $updateFields['status'] = $data['status'];
                }
            }

            if (! empty($updateFields)) {
                $tenant->update($updateFields);
            }

            if (array_key_exists('plan_id', $data) && ! empty($data['plan_id'])) {
                $subscription = $tenant->subscription;
                if ($subscription) {
                    $subscription->update(['plan_id' => $data['plan_id']]);
                } else {
                    Subscription::create([
                        'tenant_id' => $tenant->id,
                        'plan_id' => $data['plan_id'],
                        'status' => 'active',
                        'starts_at' => now(),
                        'expires_at' => now()->addYear(),
                        'billing_cycle' => 'monthly',
                    ]);
                }
            }

            return $tenant->fresh(['subscription.plan', 'users']);
        });
    }

    /**
     * Approve a pending tenant.
     */
    public function approveTenant(Tenant $tenant): Tenant
    {
        return DB::transaction(function () use ($tenant) {
            $tenant->update([
                'status' => 'active',
                'onboarding_completed_at' => $tenant->onboarding_completed_at ?? now(),
            ]);

            // Activate pending users
            $tenant->users()->where('status', 'pending')->update(['status' => 'active']);

            // Activate pending subscriptions
            $tenant->subscriptions()->where('status', 'pending')->update(['status' => 'active']);

            return $tenant->fresh(['subscription.plan', 'users']);
        });
    }

    /**
     * Reject a pending tenant.
     */
    public function rejectTenant(Tenant $tenant): Tenant
    {
        return DB::transaction(function () use ($tenant) {
            $tenant->update(['status' => 'rejected']);

            // Deactivate tenant users
            $tenant->users()->update(['status' => 'suspended']);

            return $tenant->fresh(['subscription.plan', 'users']);
        });
    }

    /**
     * Toggle Tenant status (active / suspended).
     */
    public function setStatus(Tenant $tenant, string $status): Tenant
    {
        return DB::transaction(function () use ($tenant, $status) {
            $tenant->update(['status' => $status]);

            if ($status === 'active') {
                $tenant->users()->where('status', 'pending')->update(['status' => 'active']);
                $tenant->subscriptions()->where('status', 'pending')->update(['status' => 'active']);
            } elseif ($status === 'suspended') {
                $tenant->users()->update(['status' => 'suspended']);
            }

            return $tenant->fresh(['subscription.plan', 'users']);
        });
    }

    /**
     * Set or clear custom quota override for a tenant.
     */
    public function setQuotaOverride(Tenant $tenant, array $data): TenantQuotaOverride
    {
        return TenantQuotaOverride::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'global_daily_quota' => $data['global_daily_quota'] ?? null,
                'custom_settings' => $data['custom_settings'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ]
        );
    }
}
