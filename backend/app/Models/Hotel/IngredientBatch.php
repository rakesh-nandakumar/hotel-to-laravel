<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientBatch extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'ingredient_id',
        'qty',
        'initial_qty',
        'expiry_date',
        'received_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'initial_qty' => 'float',
            'expiry_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
