<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant-owned role — each tenant gets its own independent rows (even for
 * the shared system-role names like "Full Administrator"/"Manager"), so
 * editing one tenant's role can never touch another's. See
 * App\Models\Concerns\BelongsToTenant.
 */
class Role extends Model
{
    use BelongsToTenant, HasFactory, HasUserstamps, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'is_system',
        'is_full_admin',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_full_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function assignedUserCount(): int
    {
        return $this->users()->count();
    }
}
