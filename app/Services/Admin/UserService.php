<?php

namespace App\Services\Admin;

use App\Http\Requests\Admin\User\StoreUserRequest;
use App\Http\Requests\Admin\User\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function index()
    {
        $users = Admin::latest()->get();

        return responseSuccess(AdminUserResource::collection($users), 'Users fetched successfully');
    }

    public function show(string $id)
    {
        $user = Admin::findOrFail($id);

        return responseSuccess(AdminUserResource::make($user), 'User details fetched successfully');
    }

    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();

        $user = Admin::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return responseSuccess(AdminUserResource::make($user), 'User created successfully', 201);
    }

    public function update(string $id, UpdateUserRequest $request)
    {
        $user = Admin::findOrFail($id);
        $validated = $request->validated();

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
        ];

        if ($request->has('is_active')) {
            $updateData['is_active'] = $request->boolean('is_active');
        }

        if (! empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $user->update($updateData);

        return responseSuccess(AdminUserResource::make($user->fresh()), 'User updated successfully');
    }

    public function destroy(string $id)
    {
        $user = Admin::findOrFail($id);
        $user->delete();

        return responseSuccess(null, 'User deleted successfully');
    }
}
