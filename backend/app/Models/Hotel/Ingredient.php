<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Support\Lookups\InventoryKind;
use App\Support\Lookups\LookupType;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stocked thing — classified by `kind` as either an `ingredient` (raw
 * material, consumed via a recipe) or a `product` (directly sellable, no
 * recipe — e.g. a bottled drink). One batch table, one GRN line target, one
 * stock engine for both; `selling_price`/`menu_category_id`/`image` only
 * apply to products.
 */
class Ingredient extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $fillable = ['tenant_id',

        'name',
        'unit',
        'stock_qty',
        'low_stock_threshold',
        'unit_cost',
        'inventory_kind_id',
        'selling_price',
        'menu_category_id',
        'image',
        'active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'stock_qty' => 'float',
            'low_stock_threshold' => 'float',
            'unit_cost' => 'integer',
            'selling_price' => 'integer',
            'active' => 'boolean',
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

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'inventory_kind_id');
    }

    /** POS grouping for products — irrelevant for ingredients. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function isLow(): bool
    {
        return $this->stock_qty <= $this->low_stock_threshold;
    }

    /**
     * @param  Builder<Ingredient>  $query
     */
    public function scopeIngredients(Builder $query): void
    {
        $query->where('inventory_kind_id', Lookup::id(LookupType::INVENTORY_KIND, InventoryKind::INGREDIENT));
    }

    /**
     * @param  Builder<Ingredient>  $query
     */
    public function scopeProducts(Builder $query): void
    {
        $query->where('inventory_kind_id', Lookup::id(LookupType::INVENTORY_KIND, InventoryKind::PRODUCT));
    }

    /**
     * @param  Builder<Ingredient>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
