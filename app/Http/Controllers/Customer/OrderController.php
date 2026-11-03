<?php

namespace App\Http\Controllers\Customer;

use App\Actions\Customer\Order\PlaceCustomerOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Order\PlaceOrderRequest;
use App\Services\Customer\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $status = $request->query('status');

        return $this->orderService->getCustomerOrders($request->user(), $search, $status);
    }

    public function show(Request $request, string|int $id): JsonResponse
    {
        return $this->orderService->getCustomerOrderDetail($request->user(), $id);
    }

    public function store(PlaceOrderRequest $request, PlaceCustomerOrderAction $action): JsonResponse
    {
        try {
            $order = $action->execute($request, $request->user());

            return responseSuccess([
                'order_id' => $order->id,
                'invoice_no' => $order->invoice_no,
                'grand_total' => (float) $order->grand_total,
                'status' => $order->status->value,
            ], 'Order placed successfully');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return responseError($e->getMessage(), 422);
        } catch (\Throwable $th) {
            return responseError('Failed to place order: '.$th->getMessage(), 500);
        }
    }
}
