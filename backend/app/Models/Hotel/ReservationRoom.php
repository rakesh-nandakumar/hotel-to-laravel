<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationRoom extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'reservation_id',
        'room_id',
        'nightly_rate',
        'bill_to_guest_id',
    ];

    protected function casts(): array
    {
        return [
            'nightly_rate' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function billToGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'bill_to_guest_id');
    }
}
