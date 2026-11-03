<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customer\StoreCustomerPaymentRequest;
use App\Models\Account;
use App\Models\Client;
use App\Models\Order;
use App\Services\Admin\CustomerPaymentService;
use Illuminate\Http\JsonResponse;

class CustomerPaymentController extends Controller
{
    public function __construct(public CustomerPaymentService $customerPaymentService) {}

    /**
     * Get open due orders for a specific customer.
     */
    public function getDueOrders(Client $client): JsonResponse
    {
        if (!in_array($client->type, [ClientTypeEnum::CUSTOMER, ClientTypeEnum::BOTH])) {
            return responseError('The selected client is not a customer.', 422);
        }

        try {
            $dueOrders = Order::where('client_id', $client->id)
                ->whereRaw('grand_total > paid_amount')
                ->orderBy('created_at', 'asc')
                ->get();

            $totalDue = 0.0;
            foreach ($dueOrders as $ord) {
                $totalDue += ((float) $ord->grand_total - (float) $ord->paid_amount);
            }

            $data = [
                'customer' => $client,
                'total_due' => $totalDue,
                'due_orders_count' => $dueOrders->count(),
                'orders' => $dueOrders,
            ];

            return responseSuccess($data, 'Customer due orders retrieved successfully.');
        } catch (\Throwable $th) {
            return responseError('Failed to fetch customer due orders: ' . $th->getMessage(), 500, $th);
        }
    }

    /**
     * Collect due payment from a customer using FIFO logic.
     */
    public function collectDue(Client $client, StoreCustomerPaymentRequest $request): JsonResponse
    {
        try {
            $account = Account::findOrFail($request->input('account_id'));
            $amount = (float) $request->input('amount');

            $result = $this->customerPaymentService->collectCustomerDue(
                $client,
                $account,
                $amount,
                $request->validated(),
                auth()->id()
            );

            return responseSuccess($result, 'Customer due payment collected successfully via FIFO.');
        } catch (\InvalidArgumentException $e) {
            return responseError($e->getMessage(), 422, $e);
        } catch (\Throwable $th) {
            return responseError('Failed to collect due payment: ' . $th->getMessage(), 500, $th);
        }
    }
}
