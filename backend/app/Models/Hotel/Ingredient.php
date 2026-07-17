<?php

namespace App\Models\Hotel;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ingredient extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $fillable = [
        'name',
        'unit',
        'stock_qty',
        'low_stock_threshold',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'stock_qty' => 'float',
            'low_stock_threshold' => 'float',
        ];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(IngredientBatch::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function isLow(): bool
    {
        return $this->stock_qty <= $this->low_stock_threshold;
    }
}
