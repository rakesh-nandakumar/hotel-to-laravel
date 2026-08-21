<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MenuCategory extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $table = 'pos_menu_categories';

    protected $fillable = ['tenant_id',

        'name',
        'sort_order',
        'is_minibar',
        'kitchen_station_id',
        'active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_minibar' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /** Directly-sellable, non-recipe stock items grouped under this POS category. */
    public function products(): HasMany
    {
        return $this->hasMany(Ingredient::class, 'menu_category_id');
    }

    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'kitchen_station_id');
    }
}
