<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Services\Admin\AuthService;

class AuthController extends Controller
{
    public function __construct(public AuthService $adminAuthService) {}

    public function login(LoginRequest $request)
    {
        return $this->adminAuthService->login($request);
    }
}
