<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Business-configurable, admin-editable key/value setting (VAT %, deposit %,
 * cancellation policy, loyalty rates, ...), scoped per tenant — the same
 * `key` (a stable, immutable business identifier, coding_principles.md §3)
 * now exists once per tenant, so the row's real identity is the surrogate
 * `id` plus a (tenant_id, key) unique, not `key` alone.
 * `value` is stored as a JSON-encoded string and decoded by {@see Settings}.
 */
class Setting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
