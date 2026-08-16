<?php

namespace App\Models\Apartment;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HousekeepingTask extends Model
{
    use BelongsToTenant;

    protected $table = 'apartment_housekeeping_tasks';

    protected $fillable = ['tenant_id',

        'unit_id',
        'assigned_to_id',
        'task_status_id',
        'checklist',
        'notes',
        'booking_id',
        'lease_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'task_status_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * @param  Builder<HousekeepingTask>  $query
     */
    public function scopeStatusCode(Builder $query, string $code): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('code', $code));
    }
}
