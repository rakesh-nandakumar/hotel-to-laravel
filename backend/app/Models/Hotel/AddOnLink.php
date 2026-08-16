<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot scoping an add-on to parent menu items and/or categories.
 */
class AddOnLink extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id',

        'add_on_id',
        'menu_item_id',
        'menu_category_id',
    ];

    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function menuCategory(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }
}
