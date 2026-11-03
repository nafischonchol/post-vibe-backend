<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CouponController extends Controller
{
    public function __construct(
        protected CouponService $couponService
    ) {}

    /**
     * Validate a coupon and compute the discount amount.
     */
    public function validateCoupon(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:50',
            'subtotal' => 'required|numeric|min:0',
            'phone' => 'nullable|string|max:25',
        ]);

        try {
            $user = $request->user();
            $clientId = $user ? (int) $user->id : null;

            $result = $this->couponService->validateAndCalculate(
                code: $request->input('code'),
                subtotal: (float) $request->input('subtotal'),
                clientId: $clientId,
                phone: $request->input('phone')
            );

            return responseSuccess($result, 'Coupon is valid.');
        } catch (InvalidArgumentException $e) {
            return responseError($e->getMessage(), 422);
        } catch (\Throwable $th) {
            return responseError('Failed to validate coupon: '.$th->getMessage(), 500);
        }
    }
}
