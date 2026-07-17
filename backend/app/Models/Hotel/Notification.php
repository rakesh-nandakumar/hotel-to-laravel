<?php

namespace App\Models\Hotel;

use App\Models\Lookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type',
        'notification_channel_id',
        'to',
        'subject',
        'body',
        'notification_status_id',
        'ref_type',
        'ref_id',
        'created_at',
        'sent_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            $notification->created_at ??= now();
        });
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'notification_channel_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'notification_status_id');
    }
}
