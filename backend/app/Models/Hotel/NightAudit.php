<?php

namespace App\Models\Hotel;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NightAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'business_date',
        'data',
        'run_by_id',
        'run_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'data' => 'array',
            'run_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $nightAudit): void {
            $nightAudit->run_at ??= now();
        });
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by_id');
    }
}
