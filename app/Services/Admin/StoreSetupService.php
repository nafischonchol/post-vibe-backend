<?php

namespace App\Services\Admin;

use App\Http\Resources\StoreSetupResource;
use App\Models\StoreSetup;
use App\Traits\UploadAble;

class StoreSetupService
{
    use UploadAble;

    public function show()
    {
        $storeSetup = StoreSetup::with(['division', 'district', 'upazila'])->first();

        return responseSuccess($storeSetup ? StoreSetupResource::make($storeSetup) : null);
    }

    public function update($request)
    {
        try {
            $data = $request->validated();

            if ($request->hasFile('logo')) {
                $existing = StoreSetup::value('logo');
                $this->deleteFile($existing);

                $path = $this->uploadFile($request->file('logo'), 'store-setups');
                $data['logo'] = $path;
            }

            $storeSetup = StoreSetup::first();
            if ($storeSetup) {
                $storeSetup->update($data);
            } else {
                $storeSetup = StoreSetup::create($data);
            }

            $storeSetup = $storeSetup->fresh(['division', 'district', 'upazila']);

            return responseSuccess(StoreSetupResource::make($storeSetup), 'Store setup updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to update store setup: '.$e->getMessage(), 500);
        }
    }

    public function deleteLogo()
    {
        try {
            $storeSetup = StoreSetup::first();

            if ($storeSetup && $storeSetup->logo) {
                $this->deleteFile($storeSetup->logo);
                $storeSetup->update(['logo' => null]);
            }

            return responseSuccess(null, 'Logo removed successfully');
        } catch (\Exception $e) {
            return responseError('Failed to remove logo: '.$e->getMessage(), 500);
        }
    }
}
