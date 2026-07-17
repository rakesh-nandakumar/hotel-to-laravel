<?php

namespace App\Models\Hotel;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GroupBooking extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $fillable = [
        'reference',
        'name',
        'contact_name',
        'contact_phone',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
