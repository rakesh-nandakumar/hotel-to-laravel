<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class VenueBooking extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $fillable = ['tenant_id',

        'code',
        'venue_id',
        'guest_id',
        'client_name',
        'client_phone',
        'client_email',
        'event_type',
        'date',
        'start_time',
        'end_time',
        'duration_type_id',
        'hours',
        'guest_count',
        'seating',
        'av_needs',
        'decoration',
        'catering_by_hotel',
        'notes',
        'venue_booking_status_id',
        'deposit_due',
        'cancelled_at',
        'cancel_reason',
        'created_by',
        'updated_by',
        // Enhanced package pricing fields
        'package_type',
        'per_plate_price',
        'hall_charge_used',
        'hall_only_per_person',
        'service_charge_pct',
        'byod_selected',
        'dj_required',
        'advance_payment',
        'advance_paid_at',
        'advance_payment_method',
        'profit_margin',
        'folio_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'hours' => 'float',
            'guest_count' => 'integer',
            'catering_by_hotel' => 'boolean',
            'deposit_due' => 'integer',
            'cancelled_at' => 'datetime',
            'per_plate_price' => 'integer',
            'hall_charge_used' => 'integer',
            'service_charge_pct' => 'integer',
            'byod_selected' => 'boolean',
            'dj_required' => 'boolean',
            'advance_payment' => 'integer',
            'advance_paid_at' => 'datetime',
            'profit_margin' => 'integer',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function durationType(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'duration_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'venue_booking_status_id');
    }

    public function folio(): HasOne
    {
        return $this->hasOne(Folio::class);
    }

    public function extraCharges(): HasMany
    {
        return $this->hasMany(VenueExtraCharge::class)->orderBy('sort_order');
    }
}
