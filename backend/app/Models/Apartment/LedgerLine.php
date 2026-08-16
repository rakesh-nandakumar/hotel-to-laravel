<?php

namespace App\Models\Apartment;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerLine extends Model
{
    use BelongsToTenant;

    protected $table = 'apartment_ledger_lines';

    protected $fillable = ['tenant_id',

        'ledger_id',
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

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
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
     * @param  Builder<LedgerLine>  $query
     */
    public function scopeNotVoided(Builder $query): void
    {
        $query->where('voided', false);
    }
}
