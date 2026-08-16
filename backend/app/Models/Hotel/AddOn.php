<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Standalone sellable add-on (extra cheese, extra curry…). Owns its own
 * `send_to_kot` routing and inventory tracking — a unit-based add-on links
 * straight to a batch-tracked ingredient via `stock_ingredient_id`, so it
 * deducts FEFO from expiry batches just like a recipe product does.
 */
class AddOn extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $fillable = ['tenant_id',

        'name',
        'price',
        'send_to_kot',
        'active',
        'stock_ingredient_id',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'send_to_kot' => true,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'send_to_kot' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function stockIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'stock_ingredient_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(AddOnLink::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @param  Builder<AddOn>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
