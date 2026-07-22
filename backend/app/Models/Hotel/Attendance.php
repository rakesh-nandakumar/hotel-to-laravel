<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use BelongsToTenant;
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'clock_in',
        'clock_out',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Attendance>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('clock_out');
    }

    public function hours(): ?float
    {
        if (! $this->clock_out) {
            return null;
        }

        return round(($this->clock_out->timestamp - $this->clock_in->timestamp) / 3600, 2);
    }
}
