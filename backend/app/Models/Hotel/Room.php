<?php

namespace App\Models\Hotel;

use App\Models\Branch;
use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $fillable = [
        'number',
        'room_type_id',
        'branch_id',
        'floor',
        'view',
        'amenities',
        'room_status_id',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'room_status_id');
    }

    public function reservationRooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    /**
     * @param  Builder<Room>  $query
     */
    public function scopeStatusCode(Builder $query, string $code): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('code', $code));
    }
}
