<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrOrderingPoint extends Model
{
    use BelongsToTenant, HasUserstamps;

    protected $fillable = ['tenant_id',

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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The guest-facing ordering link this QR code encodes. */
    public function publicUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/{$this->tenant->slug}/order/{$this->token}";
    }
}
