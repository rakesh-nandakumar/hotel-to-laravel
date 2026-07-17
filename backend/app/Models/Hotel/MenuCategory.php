<?php

namespace App\Models\Hotel;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MenuCategory extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $table = 'pos_menu_categories';

    protected $fillable = [
        'name',
        'sort_order',
        'is_minibar',
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
}
