<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\BannerService;

class BannerController extends Controller
{
    public function __construct(public BannerService $bannerService) {}

    public function getByType(string $type)
    {
        try {
            return $this->bannerService->getByType($type);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch banners: '.$th->getMessage(), 500);
        }
    }
}
