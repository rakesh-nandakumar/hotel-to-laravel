<?php

namespace App\Models\Apartment;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeasonalRate extends Model
{
    use BelongsToTenant, HasUserstamps;

    protected $table = 'apartment_seasonal_rates';

    protected $fillable = ['tenant_id',

        'unit_type_id',
        'name',
        'start_date',
        'end_date',
        'rate',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'rate' => 'integer',
        ];
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }
}
