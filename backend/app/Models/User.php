<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPermissions;
use App\Traits\HasUserstamps;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, HasPermissions, HasUserstamps, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_INACTIVE,
    ];

    public const LOCKOUT_THRESHOLD = 20;

    public const LOCKOUT_DURATION_HOURS = 24;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'profile_image',
        'status',
        'last_login_at',
        'last_login_ip',
        'failed_login_count',
        'locked_until',
        'must_change_password',
        'password_changed_at',
        'two_factor_required',
        'two_factor_email_enabled',
        'otp_hash',
        'otp_expires_at',
        'otp_attempts',
        'last_otp_sent_at',
        'password_reset_otp_hash',
        'password_reset_otp_expires_at',
        'role_id',
        'tenant_id',
        'created_by',
        'updated_by',
        'email_verified_at',
        'base_salary',
        'ot_hourly_rate',
        'monthly_allowance',
        'epf_enabled',
        'epf_number',
        'pin_hash',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'password_reset_otp_hash',
        'otp_hash',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'pin_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_hash' => 'hashed',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'two_factor_required' => 'boolean',
            'two_factor_email_enabled' => 'boolean',
            'otp_expires_at' => 'datetime',
            'otp_attempts' => 'integer',
            'last_otp_sent_at' => 'datetime',
            'password_reset_otp_expires_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'failed_login_count' => 'integer',
            'base_salary' => 'integer',
            'ot_hourly_rate' => 'integer',
            'monthly_allowance' => 'integer',
            'epf_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Track password rotation. A self-service change clears the forced
        // flag; admin flows that set the flag alongside the password keep it.
        static::saving(function (User $user): void {
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();

                if (! $user->isDirty('must_change_password')) {
                    $user->must_change_password = false;
                }
            }
        });
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strtolower(trim((string) $value)),
        );
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Every role assigned to this user (the real basis for permissions).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
    }

    /**
     * Whether this user is a platform-level admin (not a tenant user).
     * This is distinct from isFullAdmin() which checks tenant-level admin.
     */
    public function isPlatformAdmin(): bool
    {
        return false; // Tenant users are never platform admins.
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'updated_by');
    }

    public function isFullAdmin(): bool
    {
        return Cache::remember(
            "user:{$this->id}:full_admin",
            now()->addHour(),
            fn () => $this->roles()->where('is_full_admin', true)->where('is_active', true)->exists(),
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $initials = collect($parts)
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : '?';
    }
}
