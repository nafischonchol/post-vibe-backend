<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanPlatformQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::with('platformQuotas')->get();

        return response()->json([
            'status' => 'success',
            'resources' => $plans,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly' => ['required', 'numeric', 'min:0'],
            'global_daily_quota' => ['required', 'integer', 'min:1'],
            'team_member_limit' => ['required', 'integer', 'min:1'],
            'storage_limit_mb' => ['required', 'integer', 'min:100'],
            'features' => ['nullable', 'array'],
            'platform_quotas' => ['nullable', 'array'],
            'platform_quotas.*.platform' => ['required', 'string'],
            'platform_quotas.*.daily_quota' => ['required', 'integer', 'min:0'],
        ]);

        $plan = DB::transaction(function () use ($validated) {
            $plan = Plan::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']),
                'price_monthly' => $validated['price_monthly'],
                'price_yearly' => $validated['price_yearly'],
                'global_daily_quota' => $validated['global_daily_quota'],
                'team_member_limit' => $validated['team_member_limit'],
                'storage_limit_mb' => $validated['storage_limit_mb'],
                'features' => $validated['features'] ?? [],
                'status' => 'active',
            ]);

            if (! empty($validated['platform_quotas'])) {
                foreach ($validated['platform_quotas'] as $pq) {
                    PlanPlatformQuota::create([
                        'plan_id' => $plan->id,
                        'platform' => $pq['platform'],
                        'daily_quota' => $pq['daily_quota'],
                        'enabled' => true,
                    ]);
                }
            }

            return $plan->load('platformQuotas');
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Plan created successfully.',
            'resources' => $plan,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $plan = Plan::with('platformQuotas')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'resources' => $plan,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'price_monthly' => ['nullable', 'numeric', 'min:0'],
            'price_yearly' => ['nullable', 'numeric', 'min:0'],
            'global_daily_quota' => ['nullable', 'integer', 'min:1'],
            'team_member_limit' => ['nullable', 'integer', 'min:1'],
            'storage_limit_mb' => ['nullable', 'integer', 'min:100'],
            'features' => ['nullable', 'array'],
            'status' => ['nullable', 'in:active,archived'],
            'platform_quotas' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($plan, $validated) {
            $plan->update(array_filter([
                'name' => $validated['name'] ?? null,
                'price_monthly' => $validated['price_monthly'] ?? null,
                'price_yearly' => $validated['price_yearly'] ?? null,
                'global_daily_quota' => $validated['global_daily_quota'] ?? null,
                'team_member_limit' => $validated['team_member_limit'] ?? null,
                'storage_limit_mb' => $validated['storage_limit_mb'] ?? null,
                'features' => $validated['features'] ?? null,
                'status' => $validated['status'] ?? null,
            ]));

            if (isset($validated['platform_quotas'])) {
                foreach ($validated['platform_quotas'] as $pq) {
                    PlanPlatformQuota::updateOrCreate(
                        ['plan_id' => $plan->id, 'platform' => $pq['platform']],
                        ['daily_quota' => $pq['daily_quota'], 'enabled' => $pq['enabled'] ?? true]
                    );
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Plan updated successfully.',
            'resources' => $plan->fresh('platformQuotas'),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);

        if ($plan->subscriptions()->where('status', 'active')->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete plan with active subscriptions. Archive it instead.',
            ], 422);
        }

        $plan->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Plan deleted successfully.',
        ]);
    }
}
