<?php

namespace App\Services\Platform;

use App\Models\PublishingSchedule;
use App\Models\TenantPlatformAccount;
use App\Models\Video;
use App\Models\VideoPlatformMetadata;

interface PlatformGatewayInterface
{
    /**
     * Upload / publish video to the target platform.
     *
     * @return array{external_post_id: string, external_post_url: string, raw: array}
     */
    public function upload(
        TenantPlatformAccount $account,
        Video $video,
        VideoPlatformMetadata $metadata,
        PublishingSchedule $schedule
    ): array;

    /**
     * Reconcile or verify publication status if an uncertain timeout occurred.
     *
     * @return array{published: bool, external_post_id?: string, external_post_url?: string}
     */
    public function reconcile(TenantPlatformAccount $account, string $externalPostId): array;

    /**
     * Refresh OAuth access token if expired or expiring soon.
     */
    public function refreshToken(TenantPlatformAccount $account): TenantPlatformAccount;
}
