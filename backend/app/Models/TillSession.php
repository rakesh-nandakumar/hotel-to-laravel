<?php

namespace App\Models;

use App\Models\Apartment\Payment as ApartmentPayment;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Hotel\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One open->close cycle of a Till by an operator. Replaces the old
 * Hotel\Shift — column names (opening_cash/closing_cash/expected_cash/
 * variance/opened_at/closed_at) are kept identical to it on purpose, and
 * `staff()` aliases `opened_by`, so reporting code that used to read
 * Shift barely changes.
 */
class TillSession extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id',

        'till_id',
        'status_id',
        'opened_by',
        'closed_by',
        'opening_cash',
        'closing_cash',
        'expected_cash',
        'variance',
        'notes',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_cash' => 'integer',
            'closing_cash' => 'integer',
            'expected_cash' => 'integer',
            'variance' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'status_id');
    }

    /** Who opened this session — aliased as `staff` for parity with the old Shift model. */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(TillMovement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function apartmentPayments(): HasMany
    {
        return $this->hasMany(ApartmentPayment::class);
    }

    /**
     * @param  Builder<TillSession>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }
}
