<?php

namespace App\Models\Hotel;

use App\Models\Lookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HousekeepingTask extends Model
{
    protected $fillable = [
        'room_id',
        'assigned_to_id',
        'task_status_id',
        'checklist',
        'notes',
        'reservation_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'task_status_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @param  Builder<HousekeepingTask>  $query
     */
    public function scopeStatusCode(Builder $query, string $code): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('code', $code));
    }
}
