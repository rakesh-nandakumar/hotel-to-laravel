<?php

namespace App\Models\Hotel;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaundryItem extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $fillable = [
        'name',
        'price',
        'active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'active' => 'boolean',
        ];
    }
}
