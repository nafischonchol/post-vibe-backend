<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientOtp extends Model
{
    use HasFactory;

    protected $table = 'client_otps';

    protected $fillable = [
        'phone',
        'otp_code',
        'purpose',
        'expires_at',
        'verified_at',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * Generate an OTP for given phone.
     * In local development, it always generates '123456'.
     */
    public static function generateOtp(string $phone, string $purpose = 'auth', ?string $ip = null): self
    {
        // Standardize phone number (strip spaces/dashes)
        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);

        // Always '123456' in local development environment
        if (app()->environment('local', 'testing') || config('app.env') === 'local') {
            $otpCode = '123456';
        } else {
            $otpCode = (string) random_int(100000, 999999);
        }

        // Invalidate previous unverified OTPs for this phone and purpose
        static::where('phone', $cleanPhone)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->delete();

        return static::create([
            'phone' => $cleanPhone,
            'otp_code' => $otpCode,
            'purpose' => $purpose,
            'expires_at' => Carbon::now()->addMinutes(5),
            'ip_address' => $ip,
        ]);
    }

    /**
     * Verify OTP for a given phone and purpose.
     */
    public static function verifyOtp(string $phone, string $otp, string $purpose = 'auth'): bool
    {
        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);

        $record = static::where('phone', $cleanPhone)
            ->where('purpose', $purpose)
            ->where('otp_code', $otp)
            ->whereNull('verified_at')
            ->where('expires_at', '>=', Carbon::now())
            ->latest('id')
            ->first();

        if (! $record) {
            return false;
        }

        $record->update([
            'verified_at' => Carbon::now(),
        ]);

        return true;
    }
}
