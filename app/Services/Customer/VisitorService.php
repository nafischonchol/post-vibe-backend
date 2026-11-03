<?php

namespace App\Services\Customer;

use App\Models\Client;
use App\Models\VisitorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VisitorService
{
    public function recordSession(Request $request, ?Client $client = null): VisitorSession
    {
        $guestToken = $request->header('X-Visitor-Id') ?: $request->input('guest_token');

        if (! $guestToken) {
            $guestToken = (string) Str::ulid();
        }

        $userAgent = $request->header('User-Agent');
        $deviceType = $this->detectDeviceType($userAgent);

        $authUser = auth('sanctum')->user();
        $clientId = $client?->id ?? ($authUser instanceof Client ? $authUser->id : null);

        $session = VisitorSession::updateOrCreate(
            ['guest_token' => $guestToken],
            [
                'client_id' => $clientId,
                'ip_address' => $request->ip(),
                'user_agent' => $userAgent,
                'device_type' => $deviceType,
                'last_active_at' => now(),
            ]
        );

        return $session;
    }

    /**
     * Touch session last_active_at timestamp only if > 5 minutes have elapsed.
     */
    public function touchSessionLastActive(string $guestToken): void
    {
        VisitorSession::where('guest_token', $guestToken)
            ->where(function ($query) {
                $query->whereNull('last_active_at')
                    ->orWhere('last_active_at', '<', now()->subMinutes(5));
            })
            ->update(['last_active_at' => now()]);
    }

    public function getSessionResponse(Request $request): JsonResponse
    {
        $client = auth('sanctum')->user();
        $session = $this->recordSession($request, $client);

        return responseSuccess([
            'guest_token' => $session->guest_token,
            'session' => [
                'ip_address' => $session->ip_address,
                'device_type' => $session->device_type,
                'last_active_at' => $session->last_active_at,
            ],
        ], 'Visitor session recorded successfully');
    }

    /**
     * Associate a guest_token with an authenticated Client after login.
     */
    public function associateClientWithToken(Client $client, string $guestToken): void
    {
        VisitorSession::where('guest_token', $guestToken)
            ->update([
                'client_id' => $client->id,
                'last_active_at' => now(),
            ]);
    }

    /**
     * Detect simple device type from User-Agent.
     */
    private function detectDeviceType(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'desktop';
        }

        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $userAgent)) {
            return 'tablet';
        }

        if (preg_match('/Mobile|iP(hone|od)|Android|BlackBerry|IEMobile|Kindle|NetFront|Silk-Accelerated|(hpw|web)OS|Fennec|Minimo|Opera M(obi|ini)|Blazer|Dolfin|Dolphin|Skyfire|Zune/i', $userAgent)) {
            return 'mobile';
        }

        return 'desktop';
    }
}
