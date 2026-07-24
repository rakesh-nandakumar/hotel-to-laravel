<?php

namespace App\Models\Apartment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per lease per billed month — the idempotency guard for
 * ApartmentLeaseBillingService::generateMonthlyCharges().
 */
class RentCharge extends Model
{
    protected $table = 'apartment_lease_rent_charges';

    protected $fillable = [
        'lease_id',
        'period_month',
        'ledger_line_id',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function ledgerLine(): BelongsTo
    {
        return $this->belongsTo(LedgerLine::class);
    }
}
