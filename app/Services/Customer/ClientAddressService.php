<?php

namespace App\Services\Customer;

use Illuminate\Contracts\Auth\Authenticatable;

class ClientAddressService
{
    public function getAddresses(Authenticatable $user)
    {
        try {
            $addresses = $user->addresses()
                ->orderByDesc('is_default')
                ->latest('id')
                ->get();

            return responseSuccess($addresses, 'Addresses retrieved successfully');
        } catch (\Exception $e) {
            return responseError('Failed to retrieve addresses: '.$e->getMessage(), 500, $e);
        }
    }

    public function createAddress(Authenticatable $user, array $data)
    {
        try {
            $addressCount = $user->addresses()->count();
            if ($addressCount === 0 || ! empty($data['is_default'])) {
                $user->addresses()->update(['is_default' => false]);
                $data['is_default'] = true;
            } else {
                $data['is_default'] = false;
            }

            $address = $user->addresses()->create($data);

            return responseSuccess($address, 'Address created successfully');
        } catch (\Exception $e) {
            return responseError('Failed to create address: '.$e->getMessage(), 500, $e);
        }
    }

    public function updateAddress(Authenticatable $user, int $id, array $data)
    {
        try {
            $address = $user->addresses()->findOrFail($id);

            if (! empty($data['is_default'])) {
                $user->addresses()->where('id', '!=', $id)->update(['is_default' => false]);
                $data['is_default'] = true;
            }

            $address->update($data);

            return responseSuccess($address->fresh(), 'Address updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to update address: '.$e->getMessage(), 500, $e);
        }
    }

    public function deleteAddress(Authenticatable $user, int $id)
    {
        try {
            $address = $user->addresses()->findOrFail($id);
            $wasDefault = $address->is_default;

            $address->delete();

            if ($wasDefault) {
                $firstNext = $user->addresses()->first();
                if ($firstNext) {
                    $firstNext->update(['is_default' => true]);
                }
            }

            return responseSuccess(null, 'Address deleted successfully');
        } catch (\Exception $e) {
            return responseError('Failed to delete address: '.$e->getMessage(), 500, $e);
        }
    }

    public function setDefaultAddress(Authenticatable $user, int $id)
    {
        try {
            $address = $user->addresses()->findOrFail($id);

            $user->addresses()->update(['is_default' => false]);
            $address->update(['is_default' => true]);

            return responseSuccess($address->fresh(), 'Default address updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to set default address: '.$e->getMessage(), 500, $e);
        }
    }
}
