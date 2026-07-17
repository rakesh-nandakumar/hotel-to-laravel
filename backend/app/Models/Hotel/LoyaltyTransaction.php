<?php

namespace App\Models\Hotel;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'guest_id',
        'points',
        'reason',
        'ref_type',
        'ref_id',
        'staff_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $transaction->created_at ??= now();
        });
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
