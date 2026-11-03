<?php

namespace App\Services\Admin;

use App\Enums\TransactionCategoryEnum;
use App\Http\Resources\ClientLookupResource;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Transaction;
use Illuminate\Support\Facades\Hash;

class ClientService
{
    public function index($request = null)
    {
        $request = $request ?? request();

        $perPage = (int) $request->input('per_page', 20);
        if ($perPage < 1) {
            $perPage = 20;
        }

        $page = (int) $request->input('page', 1);
        if ($page < 1) {
            $page = 1;
        }

        $clients = Client::with('details')->latest('id')
            ->paginate($perPage);

        $data = [
            'items' => ClientResource::collection($clients),
            'pagination' => pagination($clients),
        ];

        return responseSuccess($data);
    }

    /**
     * Get lightweight client lookup list for selection dropdowns.
     */
    public function lookup($request)
    {
        $query = Client::where('is_active', true);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $clients = $query->latest()
            ->get();

        $data = ClientLookupResource::collection($clients)->resolve();

        return responseSuccess($data);
    }

    public function show(string $id)
    {
        $client = Client::findOrFail($id);

        $client->load(['details', 'transactions' => function ($q) {
            $q->where('category', '!=', TransactionCategoryEnum::SALE->value)
                ->with([
                    'account' => function ($query) {
                        $query->select(['id', 'name']);
                    },
                    'createdBy' => function ($query) {
                        $query->select(['id', 'name']);
                    },
                ])
                ->latest();
        }]);

        return responseSuccess(ClientResource::make($client));
    }

    public function store($request)
    {
        try {
            $data = $request->validated();
            $data['is_active'] = $request->boolean('is_active', true);
            $data['status'] = 'approved';

            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            $client = Client::create($data);

            return responseSuccess(ClientResource::make($client), 'Client created successfully');
        } catch (\Exception $e) {
            return responseError('Failed to create client: '.$e->getMessage(), 500);
        }
    }

    public function update(Client $client, $request)
    {
        try {
            $data = $request->validated();
            if ($request->has('is_active')) {
                $data['is_active'] = $request->boolean('is_active');
            }
            if ($request->has('status')) {
                $data['status'] = $request->input('status');
            }

            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            $client->update($data);

            if ($client->status !== 'approved' || ! $client->is_active) {
                $client->tokens()->delete();
            }

            return responseSuccess(ClientResource::make($client->load('details')), 'Client updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to update client: '.$e->getMessage(), 500);
        }
    }

    public function updateStatus(Client $client, $request)
    {
        $status = $request->input('status');
        if (! in_array($status, ['approved', 'rejected', 'pending'])) {
            return responseError('Invalid status value', 422);
        }

        $isActive = ($status === 'approved');
        $client->update([
            'status' => $status,
            'is_active' => $isActive,
        ]);

        if ($status === 'rejected' || ! $isActive) {
            $client->tokens()->delete();
        }

        return responseSuccess(ClientResource::make($client->load('details')), 'Client status updated to '.$status);
    }

    public function clientTransactions(string $clientId, $request = null)
    {
        $request = $request ?? request();
        $client = Client::findOrFail($clientId);

        $perPage = (int) $request->input('per_page', 15);
        if ($perPage < 1) {
            $perPage = 15;
        }

        $query = Transaction::where('client_id', $client->id)
            ->with([
                'account' => function ($q) {
                    $q->select(['id', 'name']);
                },
                'createdBy' => function ($q) {
                    $q->select(['id', 'name']);
                },
            ])
            ->latest('id');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhere('amount', 'like', "%{$search}%");
            });
        }

        $transactions = $query->paginate($perPage);

        $items = collect($transactions->items())->map(function ($t) {
            return [
                'id' => $t->id,
                'amount' => (float) $t->amount,
                'type' => $t->type?->value ?? (string) $t->type,
                'category' => $t->category?->value ?? (string) $t->category,
                'account' => $t->account?->name ?? 'N/A',
                'description' => $t->description ?? '-',
                'date' => $t->date?->toISOString() ?? $t->created_at?->toISOString(),
                'created_by' => $t->createdBy?->name ?? 'N/A',
            ];
        });

        $data = [
            'items' => $items,
            'pagination' => pagination($transactions),
        ];

        return responseSuccess($data);
    }
}
