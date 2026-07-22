<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class VenueBooking extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $fillable = [
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
}
