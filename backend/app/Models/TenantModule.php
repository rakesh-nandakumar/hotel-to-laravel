<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (tenant, catalog module) — whether a platform operator has
 * licensed that feature group for this tenant. See App\Support\ModuleCatalog
 * for the catalog and App\Services\TenantModules for enforcement.
 */
class TenantModule extends Model
{
    protected $fillable = [
        'tenant_id',
        'module_key',
        'is_enabled',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(CentralAdmin::class, 'granted_by');
    }
}
