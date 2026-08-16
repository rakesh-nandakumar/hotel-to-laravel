<?php

namespace App\Models\Apartment;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $table = 'apartment_units';

    protected $fillable = ['tenant_id',

        'unit_no',
        'property_id',
        'unit_type_id',
        'floor',
        'view',
        'amenities',
        'listing_type_id',
        'unit_status_id',
        'sale_price',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
            'sale_price' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }

    public function listingType(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'listing_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'unit_status_id');
    }

    /**
     * @param  Builder<Unit>  $query
     */
    public function scopeStatusCode(Builder $query, string $code): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('code', $code));
    }

    /**
     * @param  Builder<Unit>  $query
     */
    public function scopeListingTypeCode(Builder $query, string $code): void
    {
        $query->whereHas('listingType', fn (Builder $q) => $q->where('code', $code));
    }
}
