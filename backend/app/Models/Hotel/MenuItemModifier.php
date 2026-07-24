<?php

namespace App\Models\Hotel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItemModifier extends Model
{
    protected $fillable = [
        'modifier_group_id',
        'name',
        'price_delta',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'price_delta' => 'integer',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MenuItemModifierGroup::class, 'modifier_group_id');
    }
}
