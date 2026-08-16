<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One append-only row in a till session's cash ledger. Never updated after
 * creation — TillService::expectedBalance() always sums these rows; the
 * till's balance is never derived from sales reports (see TillService).
 */
class TillMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id',

        'till_session_id',
        'type_id',
        'amount',
        'reason',
        'reference',
        'notes',
        'source_type',
        'source_id',
        'performed_by',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TillSession::class, 'till_session_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'type_id');
    }

    /** The Hotel/Apartment Payment this movement was auto-generated from, if any. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
