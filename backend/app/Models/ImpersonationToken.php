<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short-lived, single-use token letting a platform operator (CentralAdmin)
 * land on a tenant's subdomain already logged in as one of its users, without
 * ever handling that user's password. See App\Services\Impersonation.
 */
class ImpersonationToken extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'central_admin_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function centralAdmin(): BelongsTo
    {
        return $this->belongsTo(CentralAdmin::class);
    }
}
