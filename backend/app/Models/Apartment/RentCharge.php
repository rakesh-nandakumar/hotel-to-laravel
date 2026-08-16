<?php

namespace App\Models\Apartment;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per lease per billed month — the idempotency guard for
 * ApartmentLeaseBillingService::generateMonthlyCharges().
 */
class RentCharge extends Model
{
    use BelongsToTenant;

    protected $table = 'apartment_lease_rent_charges';

    protected $fillable = ['tenant_id',

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
