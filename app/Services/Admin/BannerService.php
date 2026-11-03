<?php

namespace App\Services\Admin;

use App\Http\Resources\BannerResource;
use App\Models\Banner;
use App\Traits\UploadAble;

class BannerService
{
    use UploadAble;

    public function index()
    {
        $banners = Banner::latest()->get();

        return responseSuccess(BannerResource::collection($banners));
    }

    public function show(string $id)
    {
        $banner = Banner::findOrFail($id);

        return responseSuccess(BannerResource::make($banner));
    }

    public function store($request)
    {
        try {
            $data = $request->validated();
            $data['is_active'] = $request->boolean('is_active', true);

            if ($request->hasFile('banner_image')) {
                $path = $this->uploadFile($request->file('banner_image'), 'banners');
                $data['banner_image'] = $path;
            }

            if ($request->hasFile('mobile_banner_image')) {
                $mobilePath = $this->uploadFile($request->file('mobile_banner_image'), 'banners');
                $data['mobile_banner_image'] = $mobilePath;
            }

            $banner = Banner::create($data);

            return responseSuccess(BannerResource::make($banner), 'Banner created successfully');
        } catch (\Exception $e) {
            return responseError('Failed to create banner: '.$e->getMessage(), 500);
        }
    }

    public function update(Banner $banner, $request)
    {
        try {
            $data = $request->validated();
            $data['is_active'] = $request->boolean('is_active', true);

            if ($request->hasFile('banner_image')) {
                $this->deleteFile($banner->banner_image);

                $path = $this->uploadFile($request->file('banner_image'), 'banners');
                $data['banner_image'] = $path;
            }

            if ($request->hasFile('mobile_banner_image')) {
                if ($banner->mobile_banner_image) {
                    $this->deleteFile($banner->mobile_banner_image);
                }

                $mobilePath = $this->uploadFile($request->file('mobile_banner_image'), 'banners');
                $data['mobile_banner_image'] = $mobilePath;
            } elseif ($request->boolean('remove_mobile_image')) {
                if ($banner->mobile_banner_image) {
                    $this->deleteFile($banner->mobile_banner_image);
                }
                $data['mobile_banner_image'] = null;
            }

            $banner->update($data);

            return responseSuccess(BannerResource::make($banner), 'Banner updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to update banner: '.$e->getMessage(), 500);
        }
    }

    public function updateStatus(Banner $banner, $request = null)
    {
        try {
            $isActive = ($request && $request->has('is_active'))
                ? $request->boolean('is_active')
                : ! $banner->is_active;

            $banner->update([
                'is_active' => $isActive,
            ]);

            $statusText = $banner->is_active ? 'activated' : 'deactivated';

            return responseSuccess(BannerResource::make($banner), "Banner {$statusText} successfully");
        } catch (\Exception $e) {
            return responseError('Failed to update banner status: '.$e->getMessage(), 500);
        }
    }
}
