<?php

namespace App\Models\Hotel;

use App\Models\Lookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolioLine extends Model
{
    protected $fillable = [
        'folio_id',
        'order_id',
        'line_source_id',
        'description',
        'qty',
        'unit_price',
        'amount',
        'staff_id',
        'voided',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'unit_price' => 'integer',
            'amount' => 'integer',
            'voided' => 'boolean',
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'line_source_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * @param  Builder<FolioLine>  $query
     */
    public function scopeNotVoided(Builder $query): void
    {
        $query->where('voided', false);
    }

    /**
     * Lines posted from a POS order were already taxed at order time (see
     * OrderService::postOrderToFolio()) — never re-taxed at folio checkout.
     *
     * @param  Builder<FolioLine>  $query
     */
    public function scopeNotLinkedToOrder(Builder $query): void
    {
        $query->whereNull('order_id');
    }
}
