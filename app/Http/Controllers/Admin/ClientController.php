<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Client\StoreClientRequest;
use App\Http\Requests\Admin\Client\UpdateClientRequest;
use App\Models\Client;
use App\Services\Admin\ClientService;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(public ClientService $clientService) {}

    public function index(Request $request)
    {
        return $this->clientService->index($request);
    }

    public function lookup(Request $request)
    {
        return $this->clientService->lookup($request);
    }

    public function store(StoreClientRequest $request)
    {
        return $this->clientService->store($request);
    }

    public function show(Client $client)
    {
        return $this->clientService->show($client->id);
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        return $this->clientService->update($client, $request);
    }

    public function updateStatus(Request $request, Client $client)
    {
        return $this->clientService->updateStatus($client, $request);
    }

    public function clientTransactions(Request $request, Client $client)
    {
        return $this->clientService->clientTransactions($client->id, $request);
    }
}
