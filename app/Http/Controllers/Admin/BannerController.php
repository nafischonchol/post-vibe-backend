<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BannerTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Banner\StoreBannerRequest;
use App\Http\Requests\Admin\Banner\UpdateBannerRequest;
use App\Models\Banner;
use App\Services\Admin\BannerService;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    public function __construct(public BannerService $bannerService) {}

    public function index()
    {
        return $this->bannerService->index();
    }

    public function store(StoreBannerRequest $request)
    {
        return $this->bannerService->store($request);
    }

    public function show(Banner $banner)
    {
        return $this->bannerService->show($banner->id);
    }

    public function update(UpdateBannerRequest $request, Banner $banner)
    {
        return $this->bannerService->update($banner, $request);
    }

    public function updateStatus(Request $request, Banner $banner)
    {
        return $this->bannerService->updateStatus($banner, $request);
    }

    public function types()
    {
        try {
            return responseSuccess(BannerTypeEnum::options());
        } catch (\Throwable $th) {
            return responseError('Failed to fetch banner types: '.$th->getMessage(), 500);
        }
    }
}
