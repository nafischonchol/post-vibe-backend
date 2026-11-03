<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ClientAddressRequest;
use App\Services\Customer\ClientAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientAddressController extends Controller
{
    public function __construct(
        protected ClientAddressService $addressService
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->addressService->getAddresses($request->user());
    }

    public function store(ClientAddressRequest $request): JsonResponse
    {
        return $this->addressService->createAddress($request->user(), $request->validated());
    }

    public function update(ClientAddressRequest $request, int $id): JsonResponse
    {
        return $this->addressService->updateAddress($request->user(), $id, $request->validated());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->addressService->deleteAddress($request->user(), $id);
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        return $this->addressService->setDefaultAddress($request->user(), $id);
    }
}
