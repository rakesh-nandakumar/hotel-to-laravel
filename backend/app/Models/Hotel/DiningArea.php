<?php

namespace App\Models\Hotel;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiningArea extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $fillable = [
        'name',
        'sort_order',
        'active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }
}
