<?php

namespace App\Models\Apartment;

use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lease extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $table = 'apartment_leases';

    protected $fillable = [
        'code',
        'unit_id',
        'customer_id',
        'lease_status_id',
        'start_date',
        'end_date',
        'monthly_rent',
        'rent_due_day',
        'security_deposit',
        'notice_period_days',
        'auto_renew',
        'signed_at',
        'terminated_at',
        'termination_reason',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'monthly_rent' => 'integer',
            'rent_due_day' => 'integer',
            'security_deposit' => 'integer',
            'notice_period_days' => 'integer',
            'auto_renew' => 'boolean',
            'signed_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'lease_status_id');
    }

    public function ledger(): HasOne
    {
        return $this->hasOne(Ledger::class);
    }

    public function utilityReadings(): HasMany
    {
        return $this->hasMany(UtilityReading::class);
    }

    public function rentCharges(): HasMany
    {
        return $this->hasMany(RentCharge::class);
    }

    /**
     * @param  Builder<Lease>  $query
     */
    public function scopeStatusCode(Builder $query, string $code): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('code', $code));
    }

    /**
     * @param  Builder<Lease>  $query
     * @param  list<string>  $codes
     */
    public function scopeStatusIn(Builder $query, array $codes): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->whereIn('code', $codes));
    }
}
