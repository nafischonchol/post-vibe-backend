<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Adjustment\StoreAdjustmentRequest;
use App\Services\Admin\AdjustmentService;

class AdjustmentController extends Controller
{
    public function __construct(
        protected AdjustmentService $adjustmentService
    ) {}

    public function store(StoreAdjustmentRequest $request)
    {
        try {
            $result = $this->adjustmentService->storeAdjustment($request->validated());

            return responseSuccess($result, 'Stock adjusted successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to adjust stock: '.$th->getMessage(), 500, $th);
        }
    }

    public function index()
    {
        try {
            $adjustments = $this->adjustmentService->getPaginatedAdjustments();

            return responseSuccess($adjustments, 'Adjustments retrieved successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to fetch adjustments: '.$th->getMessage(), 500, $th);
        }
    }
}
