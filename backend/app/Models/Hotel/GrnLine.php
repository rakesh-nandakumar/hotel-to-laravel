<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrnLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id',

        'grn_id',
        'ingredient_id',
        'qty',
        'unit_cost',
        'line_total',
        'batch_no',
        'manufactured_at',
        'expiry_date',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'unit_cost' => 'integer',
            'line_total' => 'integer',
            'manufactured_at' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
