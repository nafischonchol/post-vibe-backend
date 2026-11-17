<?php

namespace App\Services\Admin;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function listUsers(): Collection
    {
        return Admin::latest()->get();
    }

    public function findUser(string|int $id): Admin
    {
        return Admin::findOrFail($id);
    }

    public function createUser(array $data, bool $isActive = true): Admin
    {
        return Admin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'is_active' => $isActive,
        ]);
    }

    public function updateUser(Admin $user, array $data, ?bool $isActive = null): Admin
    {
        $updateData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
        ];

        if ($isActive !== null) {
            $updateData['is_active'] = $isActive;
        }

        if (! empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        $user->update($updateData);

        return $user->fresh();
    }

    public function deleteUser(Admin $user): bool
    {
        return (bool) $user->delete();
    }
}
