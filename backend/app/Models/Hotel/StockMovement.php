<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stock consumption/receipt ledger — one row per batch draw or return,
 * signed (positive in, negative out), carrying the cost of the batch it
 * touched. See App\Support\Lookups\StockMovementType for the kinds.
 */
class StockMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id',

        'ingredient_id',
        'ingredient_batch_id',
        'movement_type_id',
        'qty',
        'unit_cost',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'unit_cost' => 'integer',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngredientBatch::class, 'ingredient_batch_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'movement_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
