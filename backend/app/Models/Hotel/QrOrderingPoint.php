<?php

namespace App\Models\Hotel;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrOrderingPoint extends Model
{
    use HasUserstamps;

    protected $fillable = [
        'room_id',
        'dining_table_id',
        'token',
        'enabled',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    /** The guest-facing ordering link this QR code encodes. */
    public function publicUrl(): string
    {
        return rtrim(config('app.frontend_url'), '/')."/order/{$this->token}";
    }
}
