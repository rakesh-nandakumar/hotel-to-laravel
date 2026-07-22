<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant represents an isolated business on the platform. Each tenant gets
 * its own subdomain (slug.example.com) and all data is scoped by tenant_id.
 */
class Tenant extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'logo',
        'status',
        'plan',
        'storage_limit_mb',
        'max_users',
        'settings',
        'trial_ends_at',
        'suspended_at',
        'suspension_reason',
        'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'storage_limit_mb' => 'integer',
            'max_users' => 'integer',
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * Permissions enabled for this tenant (the upper limit for its users).
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'tenant_permissions');
    }

    /**
     * @param  Builder<Tenant>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * @param  Builder<Tenant>  $query
     */
    public function scopeSuspended(Builder $query): void
    {
        $query->where('status', self::STATUS_SUSPENDED);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function subdomain(): string
    {
        return $this->slug;
    }

    /**
     * Whether this tenant has been granted a specific permission.
     */
    public function hasPermission(string $permissionName): bool
    {
        return $this->permissions()->where('name', $permissionName)->exists();
    }

    /**
     * @return array<int, string>
     */
    public function enabledPermissionNames(): array
    {
        return $this->permissions()->pluck('permissions.name')->all();
    }
}
