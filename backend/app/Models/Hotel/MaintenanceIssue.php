<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use App\Models\Lookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceIssue extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'room_id',
        'venue_id',
        'description',
        'maintenance_status_id',
        'logged_by_id',
        'resolution_notes',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'maintenance_status_id');
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_id');
    }
}
