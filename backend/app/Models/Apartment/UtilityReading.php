<?php

namespace App\Models\Apartment;

use App\Models\Lookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UtilityReading extends Model
{
    protected $table = 'apartment_utility_readings';

    protected $fillable = [
        'lease_id',
        'utility_type_id',
        'period_month',
        'previous_reading',
        'current_reading',
        'rate_per_unit',
        'amount',
        'ledger_line_id',
        'staff_id',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'previous_reading' => 'float',
            'current_reading' => 'float',
            'rate_per_unit' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function utilityType(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'utility_type_id');
    }

    public function ledgerLine(): BelongsTo
    {
        return $this->belongsTo(LedgerLine::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
