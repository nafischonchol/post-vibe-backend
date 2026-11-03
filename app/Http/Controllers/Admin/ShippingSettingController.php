<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShippingSetting\UpdateShippingSettingRequest;
use App\Services\Admin\ShippingSettingService;

class ShippingSettingController extends Controller
{
    public function __construct(public ShippingSettingService $shippingSettingService) {}

    public function show()
    {
        return $this->shippingSettingService->getSettings();
    }

    public function update(UpdateShippingSettingRequest $request)
    {
        return $this->shippingSettingService->update($request);
    }

    public function showPublic()
    {
        return $this->shippingSettingService->getPublicSettings();
    }
}
