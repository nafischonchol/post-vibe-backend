<?php

namespace App\Services;

use App\Models\Tenant;

class TenantContext
{
    protected static ?int $currentTenantId = null;
    protected static ?Tenant $currentTenant = null;

    public static function setTenant(Tenant $tenant): void
    {
        static::$currentTenant = $tenant;
        static::$currentTenantId = $tenant->id;
    }

    public static function setTenantId(?int $tenantId): void
    {
        static::$currentTenantId = $tenantId;
        static::$currentTenant = null;
    }

    public static function getTenantId(): ?int
    {
        return static::$currentTenantId;
    }

    public static function getTenant(): ?Tenant
    {
        if (static::$currentTenant === null && static::$currentTenantId !== null) {
            static::$currentTenant = Tenant::find(static::$currentTenantId);
        }

        return static::$currentTenant;
    }

    public static function clear(): void
    {
        static::$currentTenantId = null;
        static::$currentTenant = null;
    }
}
