<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Admin\TenantManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(
        protected TenantManagementService $tenantService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenants = $this->tenantService->listTenants($request->all());

        return response()->json([
            'status' => 'success',
            'resources' => $tenants->items(),
            'meta' => [
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
                'per_page' => $tenants->perPage(),
                'total' => $tenants->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'unique:tenants,email'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'string', 'email', 'unique:tenant_users,email'],
            'owner_password' => ['required', 'string', 'min:8'],
            'plan_id' => ['nullable', 'exists:plans,id'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly'],
        ]);

        $tenant = $this->tenantService->createTenant($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Tenant and workspace owner created successfully.',
            'resources' => $tenant,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $tenant = Tenant::with(['subscription.plan.platformQuotas', 'users', 'quotaOverride', 'publishingSettings'])
            ->withCount(['videos', 'publishingBatches', 'platformAccounts'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'resources' => $tenant,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', "unique:tenants,email,{$id}"],
            'timezone' => ['nullable', 'string', 'max:50'],
        ]);

        $tenant = $this->tenantService->updateTenant($tenant, $validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Tenant updated successfully.',
            'resources' => $tenant,
        ]);
    }

    public function suspend(int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $this->tenantService->setStatus($tenant, 'suspended');

        return response()->json([
            'status' => 'success',
            'message' => "Tenant {$tenant->name} has been suspended.",
        ]);
    }

    public function activate(int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $this->tenantService->setStatus($tenant, 'active');

        return response()->json([
            'status' => 'success',
            'message' => "Tenant {$tenant->name} has been activated.",
        ]);
    }

    public function setQuotaOverride(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $validated = $request->validate([
            'global_daily_quota' => ['nullable', 'integer', 'min:1'],
            'custom_settings' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $override = $this->tenantService->setQuotaOverride($tenant, $validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Quota override updated successfully.',
            'resources' => $override,
        ]);
    }
}
