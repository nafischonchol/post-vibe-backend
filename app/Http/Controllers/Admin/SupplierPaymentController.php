<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Supplier\StoreSupplierPaymentRequest;
use App\Models\Account;
use App\Models\Client;
use App\Models\Purchase;
use App\Services\Admin\SupplierPaymentService;
use Illuminate\Http\JsonResponse;

class SupplierPaymentController extends Controller
{
    public function __construct(public SupplierPaymentService $supplierPaymentService) {}

    /**
     * Get open due purchases for a specific supplier.
     */
    public function getDueInvoices(Client $client): JsonResponse
    {
        if (!in_array($client->type, [ClientTypeEnum::SUPPLIER, ClientTypeEnum::BOTH])) {
            return responseError('The selected client is not a supplier.', 422);
        }

        try {
            $duePurchases = Purchase::where('client_id', $client->id)
                ->whereIn('payment_status', ['unpaid', 'partially_paid'])
                ->where('due_amount', '>', 0)
                ->orderBy('purchase_date', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $totalDue = (float) $duePurchases->sum('due_amount');

            $data = [
                'supplier' => $client,
                'total_due' => $totalDue,
                'due_invoices_count' => $duePurchases->count(),
                'invoices' => $duePurchases,
            ];

            return responseSuccess($data, 'Supplier due invoices retrieved successfully.');
        } catch (\Throwable $th) {
            return responseError('Failed to fetch due invoices: ' . $th->getMessage(), 500, $th);
        }
    }

    /**
     * Pay due for a supplier using FIFO logic.
     */
    public function payDue(Client $client, StoreSupplierPaymentRequest $request): JsonResponse
    {
        try {
            $account = Account::findOrFail($request->input('account_id'));
            $amount = (float) $request->input('amount');

            $payment = $this->supplierPaymentService->paySupplierDue(
                $client,
                $account,
                $amount,
                $request->validated(),
                auth()->id()
            );

            return responseSuccess($payment, 'Supplier due payment processed successfully via FIFO.');
        } catch (\InvalidArgumentException $e) {
            return responseError($e->getMessage(), 422, $e);
        } catch (\Throwable $th) {
            return responseError('Failed to process due payment: ' . $th->getMessage(), 500, $th);
        }
    }
}
