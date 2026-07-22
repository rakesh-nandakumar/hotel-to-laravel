<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use App\Models\Lookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folio extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'folio_type_id',
        'folio_status_id',
        'invoice_no',
        'reservation_id',
        'venue_booking_id',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'settled_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'folio_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'folio_status_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function venueBooking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FolioLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
