<?php

namespace App\Services\Customer;

use App\Models\ClientOtp;
use App\Services\SmsService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileService
{
    public function __construct(
        protected SmsService $smsService
    ) {}

    /**
     * Retrieve customer profile details.
     */
    public function getProfile(Authenticatable $user): JsonResponse
    {
        try {
            $avatarUrl = null;
            if ($user->avatar) {
                $avatarUrl = str_starts_with($user->avatar, 'http')
                    ? $user->avatar
                    : Storage::disk('public')->url($user->avatar);
            }

            $data = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'avatar' => $avatarUrl,
                'address' => $user->address,
                'has_password' => ! empty($user->password),
                'status' => $user->status,
                'is_active' => $user->is_active,
            ];

            return responseSuccess($data, 'Profile retrieved successfully');
        } catch (\Exception $e) {
            return responseError('Failed to retrieve profile: '.$e->getMessage(), 500, $e);
        }
    }

    /**
     * Update customer profile info.
     */
    public function updateProfile(Authenticatable $user, array $data, $avatarFile = null): JsonResponse
    {
        try {
            $updateData = [
                'name' => $data['name'] ?? $user->name,
                'email' => array_key_exists('email', $data) ? $data['email'] : $user->email,
                'phone' => $data['phone'] ?? $user->phone,
                'gender' => array_key_exists('gender', $data) ? $data['gender'] : $user->gender,
                'address' => array_key_exists('address', $data) ? $data['address'] : $user->address,
            ];

            // Handle Avatar Upload
            if ($avatarFile) {
                if ($user->avatar && ! str_starts_with($user->avatar, 'http') && Storage::disk('public')->exists($user->avatar)) {
                    Storage::disk('public')->delete($user->avatar);
                }
                $path = $avatarFile->store('avatars', 'public');
                $updateData['avatar'] = $path;
            } elseif (isset($data['avatar']) && is_string($data['avatar']) && str_starts_with($data['avatar'], 'http')) {
                $updateData['avatar'] = $data['avatar'];
            }

            $user->update($updateData);

            return $this->getProfile($user->fresh());
        } catch (\Exception $e) {
            return responseError('Failed to update profile: '.$e->getMessage(), 500, $e);
        }
    }

    /**
     * Send OTP for setting or changing password.
     */
    public function sendPasswordOtp(Authenticatable $user): JsonResponse
    {
        try {
            $phone = $user->phone;
            $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
            $otp = ClientOtp::generateOtp($cleanPhone, 'reset');

            $message = "Your Mohima password reset code is {$otp->otp_code}. Valid for 5 minutes.";
            $this->smsService->send($cleanPhone, $message);

            $responseData = [
                'phone' => $cleanPhone,
                'expires_in_seconds' => 300,
            ];

            if (app()->environment('local', 'testing') || config('app.env') === 'local') {
                $responseData['dev_otp'] = $otp->otp_code;
            }

            return responseSuccess($responseData, 'Password reset verification code sent.');
        } catch (\Exception $e) {
            return responseError('Failed to send OTP: '.$e->getMessage(), 500, $e);
        }
    }

    /**
     * Update customer password using either current password or OTP.
     */
    public function updatePassword(Authenticatable $user, array $data): JsonResponse
    {
        try {
            $hasExistingPassword = ! empty($user->password);

            // Option 1: Using OTP
            if (! empty($data['otp'])) {
                $cleanPhone = preg_replace('/[^0-9+]/', '', $user->phone);
                $isValid = ClientOtp::verifyOtp($cleanPhone, $data['otp'], 'reset')
                    || ClientOtp::verifyOtp($cleanPhone, $data['otp'], 'auth');

                if (! $isValid) {
                    return responseError('Invalid or expired OTP verification code.', 422);
                }
            }
            // Option 2: Using Current Password (or if user has no password set yet)
            elseif ($hasExistingPassword) {
                if (empty($data['current_password'])) {
                    return responseError('Please provide your current password.', 422);
                }

                if (! Hash::check($data['current_password'], $user->password)) {
                    return responseError('The current password does not match our records.', 422);
                }
            }

            $user->update([
                'password' => Hash::make($data['new_password']),
            ]);

            return responseSuccess(null, 'Password updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to update password: '.$e->getMessage(), 500, $e);
        }
    }
}
