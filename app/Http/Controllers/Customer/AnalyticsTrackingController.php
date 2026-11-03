<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\TrackAnalyticsEventRequest;
use App\Jobs\SendMetaCapiEventJob;
use App\Jobs\SendTikTokEventJob;
use Illuminate\Http\JsonResponse;

class AnalyticsTrackingController extends Controller
{
    /**
     * Handle incoming client analytics event and queue it for Meta CAPI and TikTok Events API.
     */
    public function trackEvent(TrackAnalyticsEventRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $userData = $validated['user_data'] ?? [];

        // Attach client request details
        $userData['client_ip_address'] = $userData['client_ip_address'] ?? $request->ip();
        $userData['client_user_agent'] = $userData['client_user_agent'] ?? $request->userAgent();
        $userData['fbp'] = $userData['fbp'] ?? $request->cookie('_fbp');
        $userData['fbc'] = $userData['fbc'] ?? $request->cookie('_fbc');
        $userData['ttclid'] = $userData['ttclid'] ?? $request->cookie('ttclid') ?? $request->cookie('_ttp');

        if ($user) {
            $userData['external_id'] = $userData['external_id'] ?? $user->id;
            $userData['phone'] = $userData['phone'] ?? $user->phone;
            $userData['name'] = $userData['name'] ?? $user->name;
            $userData['email'] = $userData['email'] ?? $user->email;
        }

        $eventName = $validated['event_name'];
        $eventId = $validated['event_id'] ?? null;
        $customData = $validated['custom_data'] ?? [];
        $eventSourceUrl = $validated['event_source_url'] ?? $request->header('Referer');

        SendMetaCapiEventJob::dispatch(
            eventName: $eventName,
            eventId: $eventId,
            userData: $userData,
            customData: $customData,
            eventSourceUrl: $eventSourceUrl,
            eventTime: time(),
            actionSource: 'website'
        );

        SendTikTokEventJob::dispatch(
            eventName: $eventName,
            eventId: $eventId,
            userData: $userData,
            properties: $customData,
            eventSourceUrl: $eventSourceUrl,
            eventTime: time()
        );

        return responseSuccess(null, 'Event queued successfully');
    }
}
