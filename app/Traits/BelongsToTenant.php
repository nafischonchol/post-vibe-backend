<?php

namespace App\Traits;

use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        // 1. Global Scope for Multi-Tenant Query Isolation
        static::addGlobalScope('tenant_isolation', function (Builder $builder) {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId !== null) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenantId);
            }
        });

        // 2. Automatically set tenant_id on model creation if not explicitly provided
        static::creating(function (Model $model) {
            if (empty($model->tenant_id)) {
                $tenantId = TenantContext::getTenantId();
                if ($tenantId !== null) {
                    $model->tenant_id = $tenantId;
                }
            }
        });
    }

    /**
     * Relationship to the owning Tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
