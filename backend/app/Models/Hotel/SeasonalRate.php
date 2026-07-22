<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeasonalRate extends Model
{
    use BelongsToTenant, HasUserstamps;

    protected $fillable = [
        'room_type_id',
        'name',
        'start_date',
        'end_date',
        'rate',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'rate' => 'integer',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
