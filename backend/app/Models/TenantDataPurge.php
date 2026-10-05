<?php

namespace App\Models;

use Database\Factories\TenantDataPurgeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * History row for one master-control data purge. Central (platform) data: it
 * carries a tenant_id but deliberately not BelongsToTenant — tenant staff must
 * never see it, and operators read it with the tenant in the URL.
 */
class TenantDataPurge extends Model
{
    /** @use HasFactory<TenantDataPurgeFactory> */
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_RESTORED = 'restored';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'central_admin_id',
        'status',
        'selections',
        'summary',
        'fingerprint',
        'note',
        'continue_numbering',
        'total_rows',
        'backup_path',
        'backup_bytes',
        'backup_sha256',
        'expires_at',
        'restored_at',
        'restored_by',
        'restore_summary',
    ];

    protected $attributes = [
        'status' => self::STATUS_COMPLETED,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $purge): void {
            $purge->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'selections' => 'array',
            'summary' => 'array',
            'restore_summary' => 'array',
            'continue_numbering' => 'boolean',
            'total_rows' => 'integer',
            'backup_bytes' => 'integer',
            'expires_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function centralAdmin(): BelongsTo
    {
        return $this->belongsTo(CentralAdmin::class);
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(CentralAdmin::class, 'restored_by');
    }

    public function isRestorable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->backup_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
