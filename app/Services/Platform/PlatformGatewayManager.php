<?php

namespace App\Services\Platform;

use Exception;
use Illuminate\Contracts\Container\Container;

class PlatformGatewayManager
{
    public function __construct(
        protected Container $container
    ) {}

    public function gateway(string $platform): PlatformGatewayInterface
    {
        return match (strtolower($platform)) {
            'youtube' => $this->container->make(YouTubeGateway::class),
            'tiktok' => $this->container->make(TikTokGateway::class),
            'facebook', 'facebook_reels' => $this->container->make(FacebookGateway::class),
            default => throw new Exception("Unsupported destination platform gateway: {$platform}"),
        };
    }
}
