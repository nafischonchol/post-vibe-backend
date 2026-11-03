<?php

namespace App\Services\Customer;

use App\Enums\ClientTypeEnum;
use App\Jobs\MergeWishlistJob;
use App\Models\Client;
use App\Models\ClientOtp;
use App\Models\Wishlist;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(
        protected SmsService $smsService,
        protected CartService $cartService
    ) {}

    /**
     * Send OTP to a customer's mobile number.
     */
    public function sendOtp(string $phone, string $purpose = 'auth', ?string $ip = null): JsonResponse
    {
        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
        $otp = ClientOtp::generateOtp($cleanPhone, $purpose, $ip);

        $message = "Your Mohima verification code is {$otp->otp_code}. Valid for 5 minutes.";
        $this->smsService->send($cleanPhone, $message);

        $responseData = [
            'phone' => $cleanPhone,
            'expires_in_seconds' => 300,
        ];

        // In local development or testing, expose OTP in the payload for easier debugging
        if (app()->environment('local', 'testing') || config('app.env') === 'local') {
            $responseData['dev_otp'] = $otp->otp_code;
        }

        return responseSuccess($responseData, 'Verification code sent successfully.');
    }

    /**
     * Login customer using Mobile and OTP.
     */
    public function loginWithOtp(string $phone, string $otp, ?string $guestToken = null, ?string $name = null): JsonResponse
    {
        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);

        // Verify OTP against purpose 'auth' or 'login'
        $isValid = ClientOtp::verifyOtp($cleanPhone, $otp, 'auth')
            || ClientOtp::verifyOtp($cleanPhone, $otp, 'login');

        if (! $isValid) {
            return responseError('Invalid or expired verification code.', 422);
        }

        // Find client by phone (checking normalized formats)
        $client = Client::where('phone', $cleanPhone)
            ->orWhere('phone', ltrim($cleanPhone, '+880'))
            ->orWhere('phone', '0'.ltrim($cleanPhone, '+880'))
            ->first();

        if (! $client) {
            $client = Client::create([
                'name' => ! empty($name) ? $name : 'Customer '.substr($cleanPhone, -4),
                'phone' => $cleanPhone,
                'phone_verified_at' => Carbon::now(),
                'type' => ClientTypeEnum::CUSTOMER,
                'status' => 'approved',
                'is_active' => true,
            ]);
        } else {
            if (! $client->phone_verified_at) {
                $client->update(['phone_verified_at' => Carbon::now()]);
            }
        }

        if (! $client->is_active) {
            return responseError('Your account is currently inactive. Please contact support.', 403);
        }

        if ($client->status === 'rejected') {
            return responseError('Your account has been deactivated. Please contact support.', 403);
        }

        return $this->generateAuthResponse($client, $guestToken, 'Logged in successfully.');
    }

    /**
     * Register a new retail customer using Mobile and OTP.
     */
    public function registerWithOtp(array $data, ?string $guestToken = null): JsonResponse
    {
        $cleanPhone = preg_replace('/[^0-9+]/', '', $data['phone']);
        $otp = $data['otp'];

        // Verify OTP
        $isValid = ClientOtp::verifyOtp($cleanPhone, $otp, 'auth')
            || ClientOtp::verifyOtp($cleanPhone, $otp, 'register');

        if (! $isValid) {
            return responseError('Invalid or expired verification code.', 422);
        }

        // If user already exists, log them in
        $existing = Client::where('phone', $cleanPhone)
            ->orWhere('phone', ltrim($cleanPhone, '+880'))
            ->orWhere('phone', '0'.ltrim($cleanPhone, '+880'))
            ->first();

        if ($existing) {
            return $this->generateAuthResponse($existing, $guestToken, 'Logged in successfully.');
        }

        $clientName = ! empty($data['name']) ? $data['name'] : 'Customer '.substr($cleanPhone, -4);

        $client = Client::create([
            'name' => $clientName,
            'phone' => $cleanPhone,
            'phone_verified_at' => Carbon::now(),
            'password' => ! empty($data['password']) ? Hash::make($data['password']) : null,
            'type' => ClientTypeEnum::CUSTOMER,
            'status' => 'approved',
            'is_active' => true,
        ]);

        return $this->generateAuthResponse($client, $guestToken, 'Account created and logged in successfully.', 201);
    }

    /**
     * Authenticate customer credentials (mobile/email/username + password).
     */
    public function login(array $credentials, ?string $guestToken = null): JsonResponse
    {
        $login = $credentials['login'];
        $password = $credentials['password'];
        $cleanPhone = preg_replace('/[^0-9+]/', '', $login);

        $client = Client::where('email', $login)
            ->orWhere('username', $login)
            ->orWhere('phone', $login)
            ->orWhere('phone', $cleanPhone)
            ->orWhere('phone', ltrim($cleanPhone, '+880'))
            ->orWhere('phone', '0'.ltrim($cleanPhone, '+880'))
            ->first();

        if (! $client || ! Hash::check($password, $client->password)) {
            return responseError('Invalid login credentials or password.', 401);
        }

        // Check if approved / active
        if ($client->status !== 'approved') {
            if ($client->status === 'pending') {
                return responseError('Your account is pending admin approval. Please wait for review.', 403);
            }

            if ($client->status === 'rejected') {
                return responseError('Your account application was rejected. Please contact support.', 403);
            }

            return responseError('Your account is not approved or inactive.', 403);
        }

        if (! $client->is_active) {
            return responseError('Your account is currently inactive.', 403);
        }

        return $this->generateAuthResponse($client, $guestToken, 'Logged in successfully.');
    }

    /**
     * Helper to generate token, dispatch wishlist merge, and return JSON payload.
     */
    protected function generateAuthResponse(Client $client, ?string $guestToken = null, string $message = 'Success', int $statusCode = 200): JsonResponse
    {
        $token = $client->createToken('customer_token')->plainTextToken;

        // Merge guest cart and wishlist if guestToken is present
        if ($guestToken) {
            $this->cartService->mergeGuestCart($client->id, $guestToken);

            if (Wishlist::where('guest_token', $guestToken)->exists()) {
                MergeWishlistJob::dispatch($client->id, $guestToken);
            }
        }

        return responseSuccess([
            'token' => $token,
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'username' => $client->username,
                'phone' => $client->phone,
                'avatar' => $client->avatar,
                'address' => $client->address,
                'status' => $client->status,
                'type' => $client->type?->value ?? 'customer',
            ],
        ], $message, $statusCode);
    }
}
