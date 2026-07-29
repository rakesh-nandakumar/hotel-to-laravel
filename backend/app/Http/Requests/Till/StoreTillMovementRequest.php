<?php

namespace App\Http\Requests\Till;

use App\Support\Lookups\TillMovementType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The manually-entered movement types only — CASH_IN (float top-up), and
 * CASH_OUT/EXPENSE/TRANSFER (money leaving the drawer). OPENING_BALANCE,
 * REFUND, and CLOSING_ADJUSTMENT are always system-generated (TillService),
 * never posted directly through this endpoint.
 */
class StoreTillMovementRequest extends FormRequest
{
    private const MANUAL_TYPES = [
        TillMovementType::CASH_IN,
        TillMovementType::CASH_OUT,
        TillMovementType::EXPENSE,
        TillMovementType::TRANSFER,
    ];

    public function authorize(): bool
    {
        $permission = $this->input('type') === TillMovementType::CASH_IN ? 'till.cash_in' : 'till.cash_out';

        return $this->user()?->hasPermissionTo($permission) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:'.implode(',', self::MANUAL_TYPES)],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required_unless:type,'.TillMovementType::CASH_IN, 'nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'approved_by' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
