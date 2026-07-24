<?php

namespace App\Models\Apartment;

use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $table = 'apartment_sales';

    protected $fillable = [
        'code',
        'unit_id',
        'customer_id',
        'sale_status_id',
        'agreed_price',
        'reserved_until',
        'agreement_signed_at',
        'handover_at',
        'cancelled_at',
        'cancel_reason',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'agreed_price' => 'integer',
            'reserved_until' => 'date',
            'agreement_signed_at' => 'datetime',
            'handover_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The buyer. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'sale_status_id');
    }

    public function ledger(): HasOne
    {
        return $this->hasOne(Ledger::class);
    }

    /**
     * @param  Builder<Sale>  $query
     */
    public function scopeStatusCode(Builder $query, string $code): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('code', $code));
    }

    /**
     * @param  Builder<Sale>  $query
     * @param  list<string>  $codes
     */
    public function scopeStatusIn(Builder $query, array $codes): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->whereIn('code', $codes));
    }
}
