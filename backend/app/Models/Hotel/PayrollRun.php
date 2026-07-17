<?php

namespace App\Models\Hotel;

use App\Models\Lookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $fillable = [
        'month',
        'payroll_status_id',
        'run_by_id',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'finalized_at' => 'datetime',
        ];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'payroll_status_id');
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class, 'run_id');
    }
}
