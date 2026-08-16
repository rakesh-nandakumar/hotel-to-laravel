<?php

namespace App\Models;

use App\Support\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A SaaS customer account (one hotel company), root of the tenant isolation
 * boundary. Deliberately carries no BelongsToTenant scope itself — it IS the
 * tenant — and is only ever created/edited from the central (platform) admin,
 * never from inside a tenant's own app.
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    public const ENV_LIVE = 'live';

    public const ENV_TEST = 'test';

    /**
     * @var list<string>
     */
    public const ENVIRONMENTS = [self::ENV_LIVE, self::ENV_TEST];

    protected $fillable = [
        'name',
        'slug',
        'status',
        'environment',
        'parent_tenant_id',
        'last_synced_at',
        'last_synced_by',
        'trial_ends_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * The demo/dev seed tenant — shared by every seeder that needs a real
     * tenant_id to stamp on the rows it creates, so a fresh install's demo
     * data is reachable from that tenant's own subdomain rather than sitting
     * unowned.
     */
    public static function demo(): self
    {
        return static::query()->firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default Tenant', 'status' => TenantStatus::ACTIVE],
        );
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(CentralAdmin::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(CentralAdmin::class, 'updated_by');
    }

    public function parentTenant(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_tenant_id');
    }

    /**
     * The single test instance this live tenant owns, if one exists.
     */
    public function testInstance(): HasOne
    {
        return $this->hasOne(self::class, 'parent_tenant_id')
            ->where('environment', self::ENV_TEST);
    }

    /**
     * The central admin who last synced this test instance from its parent.
     */
    public function lastSyncAdmin(): BelongsTo
    {
        return $this->belongsTo(CentralAdmin::class, 'last_synced_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::SUSPENDED;
    }

    public function isCancelled(): bool
    {
        return $this->status === TenantStatus::CANCELLED;
    }

    public function isTestInstance(): bool
    {
        return $this->environment === self::ENV_TEST;
    }

    public function environmentLabel(): string
    {
        return $this->isTestInstance() ? 'Test' : 'Live';
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', TenantStatus::ACTIVE);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('environment', self::ENV_LIVE);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeTestInstances(Builder $query): void
    {
        $query->where('environment', self::ENV_TEST);
    }
}
