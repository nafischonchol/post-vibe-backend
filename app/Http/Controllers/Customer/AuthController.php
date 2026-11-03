<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ClientLoginRequest;
use App\Http\Requests\Customer\LoginOtpRequest;
use App\Http\Requests\Customer\RegisterOtpRequest;
use App\Http\Requests\Customer\SendOtpRequest;
use App\Services\Customer\AuthService;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * Send OTP to customer mobile number.
     */
    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');
        $purpose = $request->validated('purpose', 'auth');
        $ip = $request->ip();

        return $this->authService->sendOtp($phone, $purpose, $ip);
    }

    /**
     * Customer login via Mobile and OTP.
     */
    public function loginOtp(LoginOtpRequest $request): JsonResponse
    {
        $guestToken = $request->header('X-Visitor-Id') ?: $request->input('guest_token');
        $phone = $request->validated('phone');
        $otp = $request->validated('otp');
        $name = $request->validated('name');

        return $this->authService->loginWithOtp($phone, $otp, $guestToken, $name);
    }

    /**
     * Customer registration via Mobile and OTP.
     */
    public function registerOtp(RegisterOtpRequest $request): JsonResponse
    {
        $guestToken = $request->header('X-Visitor-Id') ?: $request->input('guest_token');

        return $this->authService->registerWithOtp($request->validated(), $guestToken);
    }

    /**
     * Customer login via Mobile/Email/Username + Password.
     */
    public function login(ClientLoginRequest $request): JsonResponse
    {
        $guestToken = $request->header('X-Visitor-Id') ?: $request->input('guest_token');

        return $this->authService->login($request->validated(), $guestToken);
    }
}
