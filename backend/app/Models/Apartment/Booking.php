<?php

namespace App\Models\Apartment;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $table = 'apartment_bookings';

    protected $fillable = ['tenant_id',

        'code',
        'unit_id',
        'customer_id',
        'booking_status_id',
        'channel_id',
        'check_in',
        'check_out',
        'adults',
        'children',
        'nightly_rate',
        'rate_basis',
        'deposit_due',
        'notes',
        'checked_in_at',
        'checked_out_at',
        'cancelled_at',
        'cancel_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'adults' => 'integer',
            'children' => 'integer',
            'nightly_rate' => 'integer',
            'deposit_due' => 'integer',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'booking_status_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'channel_id');
    }

    public function ledger(): HasOne
    {
        return $this->hasOne(Ledger::class);
    }

    /**
     * @param  Builder<Booking>  $query
     */
    public function scopeStatusCode(Builder $query, string $code): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('code', $code));
    }

    /**
     * @param  Builder<Booking>  $query
     * @param  list<string>  $codes
     */
    public function scopeStatusIn(Builder $query, array $codes): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->whereIn('code', $codes));
    }
}
