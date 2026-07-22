<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'run_id',
        'user_id',
        'base_salary',
        'worked_hours',
        'ot_hours',
        'ot_pay',
        'allowance',
        'bonus',
        'unpaid_leave_deduction',
        'loan',
        'advance',
        'other_deduction',
        'other_deduction_note',
        'gross',
        'epf_employee',
        'epf_employer',
        'etf',
        'apit',
        'net_pay',
        'employer_cost',
        'paid',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'integer',
            'worked_hours' => 'float',
            'ot_hours' => 'float',
            'ot_pay' => 'integer',
            'allowance' => 'integer',
            'bonus' => 'integer',
            'unpaid_leave_deduction' => 'integer',
            'loan' => 'integer',
            'advance' => 'integer',
            'other_deduction' => 'integer',
            'gross' => 'integer',
            'epf_employee' => 'integer',
            'epf_employer' => 'integer',
            'etf' => 'integer',
            'apit' => 'integer',
            'net_pay' => 'integer',
            'employer_cost' => 'integer',
            'paid' => 'boolean',
            'paid_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
