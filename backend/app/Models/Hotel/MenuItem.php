<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No SoftDeletes trait — `active` is the domain-meaningful archive flag
 * (matches the Node app exactly, see the migration comment): an item that
 * appears in past orders is archived, never soft-deleted, so it stays fully
 * visible in order history and reports.
 */
class MenuItem extends Model
{
    use BelongsToTenant, HasUserstamps;

    protected $table = 'pos_menu_items';

    protected $fillable = [
        'item_no',
        'name',
        'menu_category_id',
        'price',
        'description',
        'image',
        'sold_out',
        'active',
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

    public function recipe(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
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
