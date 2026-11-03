<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Order\CreateOrderAction;
use App\Actions\Order\UpdateOrderStatusAction;
use App\Actions\Order\UpdateOrderItemsAction;
use App\Http\Requests\Admin\Order\UpdateOrderItemsRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\StoreOrderRequest;
use App\Http\Requests\Admin\Order\UpdateOrderRequest;
use App\Http\Requests\Admin\Order\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Services\Admin\OrderService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderController extends Controller
{
    public function __construct(public OrderService $orderService) {}

    public function lookup()
    {
        return $this->orderService->lookup(request()->query('search'));
    }

    public function index()
    {
        $clientId = request()->query('client_id');

        return $this->orderService->index($clientId ? (int) $clientId : null);
    }

    public function show(Order $order)
    {
        try {
            return $this->orderService->show($order);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch order details: '.$th->getMessage(), 500);
        }
    }

    public function update(Order $order, UpdateOrderRequest $request)
    {
        return $this->orderService->update($order, $request->validated());
    }

    public function updateItems(Order $order, UpdateOrderItemsRequest $request, UpdateOrderItemsAction $action)
    {
        try {
            $updatedOrder = $action->execute($order, $request);

            return $this->orderService->show($updatedOrder);
        } catch (HttpException $e) {
            return responseError($e->getMessage(), $e->getStatusCode());
        } catch (\Throwable $th) {
            return responseError('Failed to update order items: ' . $th->getMessage(), 500);
        }
    }

    public function store(StoreOrderRequest $request, CreateOrderAction $action)
    {
        try {
            $order = $action->execute($request);

            $order->load(['items', 'transactions.account']);

            return responseSuccess(['items' => [
                'id' => $order->id,
                'invoice_no' => $order->invoice_no,
                'date' => $order->created_at->toISOString(),
                'customer_snapshot' => $order->client_snapshot,
                'items' => $order->items->map(fn ($item) => [
                    'id' => (string) $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'name' => $item->product_snapshot['name'] ?? '',
                    'product_snapshot' => $item->product_snapshot,
                    'unit_price' => (float) $item->unit_price,
                    'quantity' => $item->quantity,
                    'total' => (float) $item->total,
                ]),
                'subtotal' => (float) $order->total_amount,
                'discount_amount' => (float) $order->discount_amount,
                'tax_amount' => (float) $order->tax_amount,
                'grand_total' => (float) $order->grand_total,
                'paid_amount' => (float) $order->paid_amount,
                'change_amount' => (float) $order->change_amount,
                'payment_method' => $order->transactions->map(fn ($t) => $t->account?->name)->filter()->join(', ') ?: 'N/A',
            ]], 'Order created successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to create order: '.$th->getMessage(), 500);
        }
    }

    public function updateStatus(Order $order, UpdateOrderStatusRequest $request, UpdateOrderStatusAction $action)
    {
        try {
            $updatedOrder = $action->execute($order, $request);

            return responseSuccess([
                'id' => $updatedOrder->id,
                'status' => $updatedOrder->status->value,
            ], 'Order status updated successfully');
        } catch (HttpException $e) {
            return responseError($e->getMessage(), $e->getStatusCode());
        } catch (\Throwable $th) {
            return responseError('Failed to update order status: '.$th->getMessage(), 500);
        }
    }
}
