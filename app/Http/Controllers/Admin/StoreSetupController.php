<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSetup\UpdateStoreSetupRequest;
use App\Services\Admin\StoreSetupService;

class StoreSetupController extends Controller
{
    public function __construct(public StoreSetupService $storeSetupService) {}

    public function show()
    {
        return $this->storeSetupService->show();
    }

    public function update(UpdateStoreSetupRequest $request)
    {
        return $this->storeSetupService->update($request);
    }

    public function deleteLogo()
    {
        return $this->storeSetupService->deleteLogo();
    }
}
