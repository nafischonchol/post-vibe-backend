<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * Send SMS using the active driver configured in .env / config('sms.default').
     */
    public function send(string $phone, string $message, array $options = []): bool
    {
        $driver = config('sms.default', 'log');

        return match ($driver) {
            'alphanet' => $this->sendViaAlphaNet($phone, $message, $options),
            default => $this->sendViaLog($phone, $message, $options),
        };
    }

    /**
     * AlphaNet SMS Provider implementation.
     */
    public function sendViaAlphaNet(string $phone, string $message, array $options = []): bool
    {
        $config = config('sms.providers.alphanet', []);

        Log::info("Dispatching SMS via AlphaNet to {$phone}: {$message}", [
            'config' => array_keys($config),
            'options' => $options,
        ]);

        // Placeholder for AlphaNet API integration
        // Implementation will be added when API credentials are provided.
        return true;
    }

    /**
     * Local Log / Mock SMS Provider implementation.
     */
    public function sendViaLog(string $phone, string $message, array $options = []): bool
    {
        Log::info("[SMS Log Driver] To: {$phone} | Message: {$message}", $options);

        return true;
    }
}
