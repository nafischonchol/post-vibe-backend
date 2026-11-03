<?php

namespace App\Services\Admin;

use App\Http\Requests\Admin\LoginRequest;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function login(LoginRequest $request)
    {
        $admin = Admin::where('email', $request->email)->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            return responseError('Invalid credentials', 401);
        }

        if (! $admin->is_active) {
            return responseError('Account is inactive', 403);
        }

        $token = $admin->createToken('admin_token')->plainTextToken;

        return responseSuccess([
            'admin' => $admin,
            'token' => $token,
        ], 'Login successful');
    }
}
