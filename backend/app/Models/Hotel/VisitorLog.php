<?php

namespace App\Models\Hotel;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'vehicle_no',
        'purpose',
        'time_in',
        'time_out',
        'logged_by_id',
    ];

    protected function casts(): array
    {
        return [
            'time_in' => 'datetime',
            'time_out' => 'datetime',
        ];
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_id');
    }
}
