<?php

namespace App\Models\Apartment;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Models\TillSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToTenant;

    protected $table = 'apartment_payments';

    protected $fillable = ['tenant_id',

        'idempotency_key',
        'payment_kind_id',
        'payment_method_id',
        'amount',
        'reference',
        'reason',
        'ledger_id',
        'staff_id',
        'till_session_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'payment_kind_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'payment_method_id');
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function tillSession(): BelongsTo
    {
        return $this->belongsTo(TillSession::class);
    }
}
