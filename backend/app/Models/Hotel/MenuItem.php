<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * No SoftDeletes trait — `active` is the domain-meaningful archive flag
 * (matches the Node app exactly, see the migration comment): an item that
 * appears in past orders is archived, never soft-deleted, so it stays fully
 * visible in order history and reports.
 *
 * Every menu item routes to the kitchen (KOT) — a directly-sellable,
 * non-recipe item (bottled drink, packaged snack) is a Product
 * (App\Models\Hotel\Ingredient with kind=product), not a MenuItem; KOT
 * routing is derived from line kind, not a per-row toggle.
 */
class MenuItem extends Model
{
    use BelongsToTenant, HasUserstamps;

    protected $table = 'pos_menu_items';

    protected $fillable = ['tenant_id',

        'item_no',
        'name',
        'menu_category_id',
        'price',
        'description',
        'image',
        'sold_out',
        'active',
        'stock_ingredient_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'item_no' => 'integer',
            'price' => 'integer',
            'sold_out' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    /** Direct unit-stock link for products without a recipe (e.g. bottled drinks). */
    public function stockIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'stock_ingredient_id');
    }

    /** Add-ons linked directly to this specific item. */
    public function addOnLinks(): HasMany
    {
        return $this->hasMany(AddOnLink::class, 'menu_item_id');
    }

    public function linkedAddOns(): HasManyThrough
    {
        return $this->hasManyThrough(AddOn::class, AddOnLink::class, 'menu_item_id', 'id', 'id', 'add_on_id');
    }

    /** Add-ons linked to this item's category (a category-scoped add-on applies to every item in it). */
    public function categoryAddOns(): HasManyThrough
    {
        return $this->hasManyThrough(
            AddOn::class,
            AddOnLink::class,
            'menu_category_id',
            'id',
            'menu_category_id',
            'add_on_id'
        );
    }

    public function recipe(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function modifierGroups(): HasMany
    {
        return $this->hasMany(MenuItemModifierGroup::class);
    }

    /**
     * @param  Builder<MenuItem>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%");
            if (ctype_digit($term)) {
                $q->orWhere('item_no', (int) $term);
            }
        });
    }
}
