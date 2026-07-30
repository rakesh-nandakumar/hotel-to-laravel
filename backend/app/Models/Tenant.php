<?php

namespace App\Models;

use App\Support\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected $fillable = [
        'name',
        'slug',
        'status',
        'trial_ends_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
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
}
