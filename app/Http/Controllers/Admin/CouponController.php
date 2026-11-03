<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Coupon\StoreCouponRequest;
use App\Http\Requests\Admin\Coupon\UpdateCouponRequest;
use App\Models\Coupon;
use App\Models\CouponUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Coupon::with(['createdBy:id,name', 'clients:id,name,phone']);

        // Search by code or campaign name
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        // Status filter
        $status = $request->query('status');
        if ($status === 'active') {
            $query->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                });
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status === 'expired') {
            $query->where('expires_at', '<', now());
        }

        // Type filter
        if ($type = $request->query('type')) {
            if (in_array($type, ['fixed', 'percentage'])) {
                $query->where('type', $type);
            }
        }

        $perPage = (int) $request->query('per_page', 15);
        $coupons = $query->latest('id')->paginate($perPage);

        // Calculate summary cards
        $now = now();
        $totalCoupons = Coupon::count();
        $activeCoupons = Coupon::where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            })->count();
        $totalRedemptions = (int) CouponUsage::count();
        $totalDiscountGiven = (float) CouponUsage::sum('discount_amount');

        return responseSuccess([
            'coupons' => $coupons->items(),
            'pagination' => [
                'current_page' => $coupons->currentPage(),
                'last_page' => $coupons->lastPage(),
                'per_page' => $coupons->perPage(),
                'total' => $coupons->total(),
            ],
            'stats' => [
                'total_coupons' => $totalCoupons,
                'active_coupons' => $activeCoupons,
                'total_redemptions' => $totalRedemptions,
                'total_discount_given' => round($totalDiscountGiven, 2),
            ],
        ], 'Coupons retrieved successfully');
    }

    /**
     * Store a new coupon.
     */
    public function store(StoreCouponRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $clientIds = $validated['client_ids'] ?? [];
        unset($validated['client_ids']);

        $validated['created_by_id'] = $request->user()?->id;

        $coupon = DB::transaction(function () use ($validated, $clientIds) {
            $coupon = Coupon::create($validated);

            if ($coupon->target_type === 'specific_clients' && ! empty($clientIds)) {
                $coupon->clients()->sync($clientIds);
            }

            return $coupon;
        });

        $coupon->load(['createdBy:id,name', 'clients:id,name,phone']);

        return responseSuccess($coupon, 'Coupon created successfully', 201);
    }

    /**
     * Show coupon details.
     */
    public function show(Coupon $coupon): JsonResponse
    {
        $coupon->load(['createdBy:id,name', 'clients:id,name,phone,email']);

        $usagesCount = $coupon->usages()->count();
        $totalDiscount = (float) $coupon->usages()->sum('discount_amount');

        $data = $coupon->toArray();
        $data['usages_count'] = $usagesCount;
        $data['total_discount_issued'] = round($totalDiscount, 2);

        return responseSuccess($data, 'Coupon retrieved successfully');
    }

    /**
     * Update an existing coupon.
     */
    public function update(UpdateCouponRequest $request, Coupon $coupon): JsonResponse
    {
        $validated = $request->validated();

        $clientIds = $validated['client_ids'] ?? [];
        unset($validated['client_ids']);

        DB::transaction(function () use ($coupon, $validated, $clientIds) {
            $coupon->update($validated);

            if ($coupon->target_type === 'specific_clients') {
                $coupon->clients()->sync($clientIds);
            } else {
                $coupon->clients()->detach();
            }
        });

        $coupon->load(['createdBy:id,name', 'clients:id,name,phone']);

        return responseSuccess($coupon, 'Coupon updated successfully');
    }

    /**
     * Toggle active/inactive status.
     */
    public function updateStatus(Request $request, Coupon $coupon): JsonResponse
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $coupon->update([
            'is_active' => $request->boolean('is_active'),
        ]);

        return responseSuccess($coupon, 'Coupon status updated successfully');
    }

    /**
     * Delete coupon.
     */
    public function destroy(Coupon $coupon): JsonResponse
    {
        if ($coupon->orders()->exists() || $coupon->usages()->exists()) {
            // If already used in orders, soft deactivate instead of deleting to protect order history
            $coupon->update(['is_active' => false]);

            return responseSuccess($coupon, 'Coupon has previous order usages and has been deactivated instead of deleted.');
        }

        $coupon->clients()->detach();
        $coupon->delete();

        return responseSuccess(null, 'Coupon deleted successfully');
    }

    /**
     * Get paginated redemption history for a coupon.
     */
    public function usages(Request $request, Coupon $coupon): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);

        $usages = $coupon->usages()
            ->with([
                'order:id,invoice_no,grand_total,status,created_at',
                'client:id,name,phone,email',
            ])
            ->latest('id')
            ->paginate($perPage);

        return responseSuccess([
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'name' => $coupon->name,
                'type' => $coupon->type,
                'value' => (float) $coupon->value,
                'used_count' => $coupon->used_count,
            ],
            'usages' => $usages->items(),
            'pagination' => [
                'current_page' => $usages->currentPage(),
                'last_page' => $usages->lastPage(),
                'per_page' => $usages->perPage(),
                'total' => $usages->total(),
            ],
        ], 'Coupon usages retrieved successfully');
    }

    /**
     * Generate a guaranteed unique coupon code.
     */
    public function generateUniqueCode(Request $request): JsonResponse
    {
        $prefix = strtoupper($request->query('prefix', 'MOI'));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefix);
        if (empty($prefix)) {
            $prefix = 'MOI';
        }

        $code = '';
        $maxAttempts = 10;
        $attempt = 0;

        do {
            $attempt++;
            $randomPart = strtoupper(Str::random(4).'-'.Str::random(4));
            $candidate = "{$prefix}-{$randomPart}";

            if (! Coupon::where('code', $candidate)->exists()) {
                $code = $candidate;
                break;
            }
        } while ($attempt < $maxAttempts);

        if (empty($code)) {
            $code = $prefix.'-'.strtoupper(uniqid());
        }

        return responseSuccess(['code' => $code], 'Unique code generated');
    }
}
