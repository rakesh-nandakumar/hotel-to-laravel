<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Models\TillSession;
use App\Models\User;
use App\Support\Lookups\PaymentKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToTenant;

    /**
     * The `reason` stamped on the REFUND row a cash over-tender produces at
     * settlement (POS settle / folio checkout). It's what distinguishes
     * "handed the guest their change" from a genuine refund on receipts,
     * invoices and the folio screen.
     */
    public const CHANGE_REASON = 'Change returned to guest';

    protected $fillable = ['tenant_id',

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

    public function tillSession(): BelongsTo
    {
        return $this->belongsTo(TillSession::class);
    }

    public function isRefund(): bool
    {
        return $this->kind->code === PaymentKind::REFUND;
    }

    public function isChange(): bool
    {
        return $this->isRefund() && $this->reason === self::CHANGE_REASON;
    }
}
