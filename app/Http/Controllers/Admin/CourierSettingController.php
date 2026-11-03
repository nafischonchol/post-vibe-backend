<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\CourierSettingService;
use Illuminate\Http\Request;

class CourierSettingController extends Controller
{
    public function __construct(public CourierSettingService $service) {}

    public function index()
    {
        return $this->service->index();
    }

    /**
     * Save/update a courier configuration.
     */
    public function updateOrCreate(Request $request)
    {
        return $this->service->updateOrCreate($request);
    }

    /**
     * Check customer courier delivery history & fraud risk.
     */
    public function checkFraud(Request $request)
    {
        return $this->service->checkFraud($request);
    }
}
