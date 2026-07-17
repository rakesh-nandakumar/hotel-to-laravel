<?php

namespace App\Models\Hotel;

use App\Models\Lookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomItemCheck extends Model
{
    protected $fillable = [
        'reservation_id',
        'room_id',
        'check_kind_id',
        'items',
        'staff_id',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
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

    public function kind(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'check_kind_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
