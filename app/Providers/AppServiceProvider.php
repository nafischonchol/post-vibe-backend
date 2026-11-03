<?php

namespace App\Providers;

use App\Events\OrderPlacedEvent;
use App\Events\OrderStatusUpdatedEvent;
use App\Listeners\SendOrderPlacedMetaCapiListener;
use App\Listeners\SendOrderPlacedTikTokEventListener;
use App\Listeners\SendOrderStatusUpdatedMetaCapiListener;
use App\Listeners\UpdateParcelStatusListener;
use Bonik\Courier\Events\CourierWebhookReceived;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Bonik\Courier\Contracts\CredentialResolverInterface::class,
            \App\Services\Courier\MohimaCourierCredentialResolver::class
        );

        $this->app->singleton(
            \App\Contracts\AiServiceInterface::class,
            \App\Services\AI\OpenRouterService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'order' => \App\Models\Order::class,
            'purchase' => \App\Models\Purchase::class,
            'payment' => \App\Models\Payment::class,
        ]);

        Event::listen(
            CourierWebhookReceived::class,
            UpdateParcelStatusListener::class
        );

        Event::listen(
            OrderPlacedEvent::class,
            SendOrderPlacedMetaCapiListener::class
        );

        Event::listen(
            OrderPlacedEvent::class,
            SendOrderPlacedTikTokEventListener::class
        );

        Event::listen(
            OrderStatusUpdatedEvent::class,
            SendOrderStatusUpdatedMetaCapiListener::class
        );

        Event::listen(
            \App\Events\ChatMessageSent::class,
            \App\Listeners\SendChatMessageToOmnichannelChannel::class
        );

        RateLimiter::for('api', function (Request $request) {
            $user = $request->user('sanctum') ?? $request->user();
            $ip = $request->ip();

            if ($user) {
                return Limit::perMinute(400)->by('user_'.$user->id.'_'.$ip);
            }

            $visitorId = $request->header('X-Visitor-Id') ?? 'anonymous';

            return Limit::perMinute(200)->by('guest_'.$visitorId.'_'.$ip);
        });
    }
}
