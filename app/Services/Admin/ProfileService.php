<?php

namespace App\Services\Admin;

use App\Http\Requests\Admin\Profile\UpdateProfileRequest;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileService
{
    public function show(Request $request)
    {
        $admin = $request->user();

        if (! $admin) {
            return responseError('Unauthenticated', 401);
        }

        return responseSuccess($this->formatAdmin($admin), 'Profile retrieved successfully');
    }

    public function update(UpdateProfileRequest $request)
    {
        /** @var Admin $admin */
        $admin = $request->user();

        if (! $admin) {
            return responseError('Unauthenticated', 401);
        }

        $validated = $request->validated();

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
        ];

        if (! empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        if ($request->hasFile('avatar')) {
            if ($admin->avatar && Storage::disk('public')->exists($admin->avatar)) {
                Storage::disk('public')->delete($admin->avatar);
            }
            $path = $request->file('avatar')->store('avatars', 'public');
            $updateData['avatar'] = $path;
        }

        $admin->update($updateData);

        return responseSuccess($this->formatAdmin($admin->fresh()), 'Profile updated successfully');
    }

    private function formatAdmin(Admin $admin): array
    {
        return [
            'id' => (string) $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'phone' => $admin->phone,
            'avatar' => $admin->avatar ? Storage::disk('public')->url($admin->avatar) : null,
            'is_active' => (bool) $admin->is_active,
            'created_at' => $admin->created_at?->toISOString() ?? (string) $admin->created_at,
            'updated_at' => $admin->updated_at?->toISOString() ?? (string) $admin->updated_at,
        ];
    }
}
