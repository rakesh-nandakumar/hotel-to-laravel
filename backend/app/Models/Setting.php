<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Business-configurable, admin-editable key/value setting (VAT %, deposit %,
 * cancellation policy, loyalty rates, ...). `key` is the primary key — a
 * stable, immutable business identifier, not a surrogate id (coding_principles.md §3).
 * `value` is stored as a JSON-encoded string and decoded by {@see \App\Services\Settings}.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'value',
        'type',
        'category',
        'label',
        'hint',
        'updated_by',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
