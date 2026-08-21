<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Goods Received Note — the purchase document. Draft until `receive()`
 * (App\Services\Hotel\GrnService) posts its lines into batches + stock
 * movements; a received GRN is immutable — correct mistakes with a stock
 * adjustment, not by un-posting.
 */
class Grn extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $fillable = ['tenant_id',

        'grn_no',
        'reference',
        'grn_status_id',
        'received_at',
        'notes',
        'total_cost',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'total_cost' => 'integer',
        ];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'grn_status_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GrnLine::class);
    }
}
