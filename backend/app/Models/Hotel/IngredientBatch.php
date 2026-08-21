<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientBatch extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id',

        'ingredient_id',
        'qty',
        'initial_qty',
        'unit_cost',
        'expiry_date',
        'manufactured_at',
        'batch_no',
        'received_at',
        'grn_line_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'initial_qty' => 'float',
            'unit_cost' => 'integer',
            'expiry_date' => 'date',
            'manufactured_at' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function grnLine(): BelongsTo
    {
        return $this->belongsTo(GrnLine::class);
    }
}
