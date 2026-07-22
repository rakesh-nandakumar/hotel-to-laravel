<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $fillable = [
        'code',
        'guest_id',
        'booking_channel_id',
        'reservation_status_id',
        'check_in',
        'check_out',
        'adults',
        'children',
        'package_id',
        'group_booking_id',
        'corporate_account_id',
        'notes',
        'deposit_due',
        'pre_check_in',
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
            'deposit_due' => 'integer',
            'pre_check_in' => 'array',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'booking_channel_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'reservation_status_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function groupBooking(): BelongsTo
    {
        return $this->belongsTo(GroupBooking::class);
    }

    public function corporateAccount(): BelongsTo
    {
        return $this->belongsTo(CorporateAccount::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    public function folio(): HasOne
    {
        return $this->hasOne(Folio::class);
    }

    public function roomItemChecks(): HasMany
    {
        return $this->hasMany(RoomItemCheck::class);
    }

    /**
     * @param  Builder<Reservation>  $query
     */
    public function scopeStatusCode(Builder $query, string $code): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('code', $code));
    }

    /**
     * @param  Builder<Reservation>  $query
     * @param  list<string>  $codes
     */
    public function scopeStatusIn(Builder $query, array $codes): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->whereIn('code', $codes));
    }
}
