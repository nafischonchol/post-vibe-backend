<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Purchase\ReceivePurchaseItemsRequest;
use App\Http\Requests\Admin\Purchase\StorePurchaseRequest;
use App\Models\Purchase;
use App\Services\Admin\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function __construct(public PurchaseService $purchaseService) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $query = Purchase::with(['client', 'items.variant.product'])
                ->orderBy('id', 'desc');

            if ($request->filled('client_id')) {
                $query->where('client_id', $request->input('client_id'));
            }

            if ($request->filled('payment_status')) {
                $query->where('payment_status', $request->input('payment_status'));
            }

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_no', 'like', "%{$search}%")
                        ->orWhere('supplier_name', 'like', "%{$search}%");
                });
            }

            $perPage = (int) $request->input('per_page', 15);
            $purchases = $query->paginate($perPage);

            return responseSuccess([
                'items' => $purchases->items(),
                'pagination' => pagination($purchases),
            ], 'Purchases retrieved successfully.');
        } catch (\Throwable $th) {
            return responseError('Failed to fetch purchases: ' . $th->getMessage(), 500, $th);
        }
    }

    public function store(StorePurchaseRequest $request): JsonResponse
    {
        try {
            $purchase = $this->purchaseService->createPurchase($request->validated(), auth()->id());

            return responseSuccess($purchase, 'Purchase invoice created successfully.', 201);
        } catch (\InvalidArgumentException $e) {
            return responseError($e->getMessage(), 422, $e);
        } catch (\Throwable $th) {
            return responseError('Failed to create purchase: ' . $th->getMessage(), 500, $th);
        }
    }

    public function show(Purchase $purchase): JsonResponse
    {
        try {
            $purchase->load([
                'client',
                'items.product',
                'items.variant',
                'allocations.payment.account',
                'challans.items.variant',
                'challans.items.purchaseItem',
                'challans.receivedBy',
                'createdBy',
            ]);

            return responseSuccess($purchase, 'Purchase fetched successfully.');
        } catch (\Throwable $th) {
            return responseError('Failed to fetch purchase details: ' . $th->getMessage(), 500, $th);
        }
    }

    public function receiveItems(ReceivePurchaseItemsRequest $request, Purchase $purchase): JsonResponse
    {
        try {
            $validated = $request->validated();
            $updatedPurchase = $this->purchaseService->receivePurchaseItems(
                $purchase,
                $validated['items'],
                auth()->id(),
                $validated['note'] ?? null,
                $validated['received_date'] ?? null,
                $validated['challan_no'] ?? null
            );

            return responseSuccess($updatedPurchase, 'Purchase items received and stock updated successfully.');
        } catch (\InvalidArgumentException $e) {
            return responseError($e->getMessage(), 422, $e);
        } catch (\Throwable $th) {
            return responseError('Failed to receive purchase items: ' . $th->getMessage(), 500, $th);
        }
    }
}
