<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\SeoSettingService;
use Illuminate\Http\Request;

class SeoSettingController extends Controller
{
    public function __construct(public SeoSettingService $service) {}

    /**
     * Get public SEO settings for customer storefront.
     */
    public function showPublic()
    {
        return $this->service->getPublicSettings();
    }

    /**
     * Get SEO settings.
     */
    public function show()
    {
        return $this->service->getSettings();
    }

    /**
     * Save/update SEO settings.
     */
    public function updateOrCreate(Request $request)
    {
        return $this->service->updateOrCreate($request);
    }

    /**
     * Get 404 logs.
     */
    public function logs()
    {
        return $this->service->get404Logs();
    }

    /**
     * Clear all 404 logs.
     */
    public function clearLogs()
    {
        return $this->service->clear404Logs();
    }
}
