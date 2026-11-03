<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AiSettingService;
use Illuminate\Http\Request;

class AiSettingController extends Controller
{
    public function __construct(protected AiSettingService $service) {}

    /**
     * Get AI settings.
     */
    public function show()
    {
        return $this->service->getSettings();
    }

    /**
     * Update AI settings.
     */
    public function update(Request $request)
    {
        return $this->service->update($request);
    }

    /**
     * Test connection to OpenRouter.
     */
    public function testConnection(Request $request)
    {
        return $this->service->testConnection($request);
    }
}
