<?php

namespace App\Services\Customer;

use App\Http\Resources\Customer\CustomerOrderDetailResource;
use App\Http\Resources\Customer\CustomerOrderResource;
use App\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;

class OrderService
{
    /**
     * Get customer order list.
     *
     * @return JsonResponse
     */
    public function getCustomerOrders(Authenticatable $client, ?string $search = null, ?string $status = null)
    {
        try {
            $query = Order::where('client_id', $client->id)
                ->withCount('items')
                ->latest('id');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_no', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($iq) use ($search) {
                            $iq->where('product_snapshot->name', 'like', "%{$search}%");
                        });
                });
            }

            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }

            $orders = $query->get();

            return responseSuccess(CustomerOrderResource::collection($orders), 'Customer orders retrieved successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to fetch orders: '.$th->getMessage(), 500);
        }
    }

    /**
     * Get customer order detail.
     *
     * @return JsonResponse
     */
    public function getCustomerOrderDetail(Authenticatable $client, int|string $id)
    {
        try {
            $query = Order::where('client_id', $client->id);

            if (is_numeric($id)) {
                $query->where('id', $id);
            } else {
                $query->where('invoice_no', $id);
            }

            $order = $query->with([
                'items.product.images',
                'items.variant',
                'items.review',
                'parcels',
                'statusHistories' => function ($q) {
                    $q->latest('id');
                },
            ])->first();

            if (! $order) {
                return responseError('Order not found', 404);
            }

            return responseSuccess(CustomerOrderDetailResource::make($order), 'Customer order detail retrieved successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to fetch order detail: '.$th->getMessage(), 500);
        }
    }
}
