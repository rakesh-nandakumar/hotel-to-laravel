<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use App\Models\Lookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'idempotency_key',
        'payment_kind_id',
        'payment_method_id',
        'amount',
        'reference',
        'reason',
        'folio_id',
        'order_id',
        'corporate_account_id',
        'staff_id',
        'shift_id',
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

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function corporateAccount(): BelongsTo
    {
        return $this->belongsTo(CorporateAccount::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
