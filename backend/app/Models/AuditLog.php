<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AuditLog extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'context',
        'ip',
        'user_agent',
        'route',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
            'description' => 'string',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
