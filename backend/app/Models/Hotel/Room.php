<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'number',
        'name',
        'room_type_id',
        'floor',
        'view',
        'amenities',
        'max_occupancy',
        'bed_config',
        'weekday_rate',
        'weekend_rate',
        'item_checklist',
        'cleaning_checklist',
        'room_status_id',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
            'item_checklist' => 'array',
            'cleaning_checklist' => 'array',
            'max_occupancy' => 'integer',
            'weekday_rate' => 'integer',
            'weekend_rate' => 'integer',
        ];
    }

    /**
     * Legacy relation — kept for backwards compat where a room still carries a
     * room_type_id. New rooms store all type info directly; this will be null.
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function seasonalRates(): HasMany
    {
        return $this->hasMany(SeasonalRate::class);
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
